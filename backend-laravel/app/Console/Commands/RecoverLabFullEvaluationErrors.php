<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use App\Services\LabDatasetExportService;
use App\Services\LabAgentPreflightService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Requeues full-replay transport failures without creating strategy evidence. */
class RecoverLabFullEvaluationErrors extends Command
{
    protected $signature = 'trading:recover-lab-full-evaluation-errors
        {symbol?}
        {--timeframe=H1}
        {--generation= : Restrict recovery to one laboratory generation}
        {--limit=4}
        {--agent= : Restrict recovery to one lab agent ID}
        {--after-code-repair : Retry bounded full-replay errors after the evaluator or dataset pipeline was repaired}
        {--after-proof-repair : Re-run only named candidates quarantined by the old proof verifier}';

    protected $description = 'Requeue full-validation transport errors after a bounded code/data repair';

    public function handle(LabDatasetExportService $datasets): int
    {
        try {
            $probe = Http::connectTimeout(5)->timeout(10)->acceptJson()
                ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                ->get(rtrim((string) config('services.ai_service.url'), '/').'/api/strategies');
            if (! $probe->successful() || ! is_array($probe->json('strategies'))) {
                $this->warn('AI authenticated readiness probe failed; full recovery was not dispatched.');

                return self::FAILURE;
            }
        } catch (\Throwable) {
            $this->warn('AI authenticated readiness probe unreachable; full recovery was not dispatched.');

            return self::FAILURE;
        }

        $symbol = $this->argument('symbol') ? strtoupper((string) $this->argument('symbol')) : null;
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $generationNumber = $this->option('generation') !== null ? (int) $this->option('generation') : null;
        $limit = max(1, min(8, (int) $this->option('limit')));
        $agentId = $this->option('agent') !== null ? (int) $this->option('agent') : null;
        $afterCodeRepair = (bool) $this->option('after-code-repair');
        $afterProofRepair = (bool) $this->option('after-proof-repair');

        if ($afterProofRepair && ! $agentId) {
            $this->warn('Proof repair requires an explicit --agent ID; old evidence is never bulk-replayed implicitly.');

            return self::FAILURE;
        }

        $agents = LabAgent::query()->with(['modelVersion', 'generation'])
            ->where(function ($query) use ($afterProofRepair): void {
                $statuses = $afterProofRepair
                    ? ['challenger', 'rejected', 'overfit', 'stagnated']
                    : ['evaluation_error', 'training'];
                if (! $afterProofRepair) {
                    $statuses[] = 'technical_quarantine';
                }
                $query->whereIn('lifecycle_status', $statuses);
            })
            ->when($agentId, fn ($query) => $query->where('id', $agentId))
            ->where('timeframe', $timeframe)
            ->when($symbol, fn ($query) => $query->where('symbol', $symbol))
            ->whereHas('generation', function ($query) use ($generationNumber): void {
                $query->whereIn('status', ['full_validation', 'completed', 'screened', 'technical_quarantine']);
                if ($generationNumber !== null) {
                    $query->where('generation', $generationNumber);
                }
            })
            ->when(! $afterCodeRepair, fn ($query) => $query->where('updated_at', '<=', now()->subMinutes(5)))
            ->orderBy('id')->limit($limit * 3)->get()
            ->filter(fn (LabAgent $agent): bool => (($agent->lifecycle_status === 'evaluation_error' && $this->isFullQueueError((string) $agent->decision_reason))
                    || ($afterCodeRepair && $this->isRepairableTechnicalQuarantine($agent))
                    || ($afterCodeRepair && $this->isStaleTrainingWithoutEvidence($agent))
                    || ($afterProofRepair && $this->hasLegacyProofMismatch($agent)))
                && ! $this->hasQueuedFullJob($agent)
                && ($afterCodeRepair || $afterProofRepair || (int) data_get($agent->modelVersion?->metadata, 'full_replay_recovery_attempts', 0) < 1)
            )
            ->take($limit)->values();

        if ($agents->isEmpty()) {
            $this->info('No bounded full evaluator failures are ready for recovery.');

            return self::SUCCESS;
        }

        // Recovery is an operational repair, not permission to bypass the
        // dataset contract. Prepare one immutable foundation snapshot per
        // generation before changing any agent back to full_queued; if the
        // archive cannot be sealed, no jobs are dispatched and no lifecycle
        // state is mutated.
        $blockedAgentIds = [];
        foreach ($agents->groupBy('lab_generation_id') as $generationAgents) {
            $generation = $generationAgents->first()?->generation;
            if (! $generation) {
                $this->warn('Full recovery stopped: laboratory generation was not found.');

                return self::FAILURE;
            }
            try {
                $datasets->ensureGenerationFoundationSnapshot($generation);
            } catch (\Throwable $exception) {
                if ($afterCodeRepair && $this->isFoundationContinuityFailure($exception)) {
                    $quarantined = $this->quarantineFoundationBlockedAgents($generationAgents, $exception);
                    $blockedAgentIds = array_merge($blockedAgentIds, $generationAgents->pluck('id')->all());
                    $this->warn('Foundation continuity gate blocked '.$quarantined.' recovery candidate(s); no replay jobs were dispatched and no quality verdict was created.');

                    continue;
                }
                $this->warn('Full recovery stopped: foundation archive tayyor emas; jobs dispatch qilinmadi: '.$exception->getMessage());

                return self::FAILURE;
            }
        }

        if ($blockedAgentIds !== []) {
            $agents = $agents->reject(fn (LabAgent $agent): bool => in_array($agent->id, $blockedAgentIds, true))->values();
        }
        if ($agents->isEmpty()) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($agents, $afterCodeRepair, $afterProofRepair): void {
            foreach ($agents as $agent) {
                $metadata = (array) ($agent->modelVersion?->metadata ?? []);
                if ($afterProofRepair) {
                    $previous = CandidateGateDecision::query()
                        ->where('lab_agent_id', $agent->id)
                        ->where('stage', 'statistical_forward_gate')
                        ->latest('evaluated_at')
                        ->first();
                    $history = (array) data_get($metadata, 'proof_repair_history', []);
                    $history[] = [
                        'protocol' => 'proof_replay_repair_v1',
                        'queued_at' => now()->utc()->toIso8601String(),
                        'source_gate_decision_id' => $previous?->id,
                        'source_reason_codes' => $previous?->reason_codes ?? [],
                        'source_result_hash' => data_get($previous?->metrics, 'forward_identity.result_hash'),
                        'rule' => 'Historical proof mismatch is preserved as audit history; the candidate receives no promotion credit until a fresh canonical replay completes.',
                    ];
                    data_set($metadata, 'proof_repair_history', $history);
                    data_set($metadata, 'proof_repair_contract', [
                        'protocol' => 'proof_replay_repair_v1',
                        'queued_at' => now()->utc()->toIso8601String(),
                        'source_gate_decision_id' => $previous?->id,
                        'promotion_credit' => false,
                    ]);
                } else {
                    data_set($metadata, 'full_replay_recovery_attempts', (int) data_get($metadata, 'full_replay_recovery_attempts', 0) + 1);
                    data_set($metadata, 'last_full_replay_recovery_at', now()->utc()->toIso8601String());
                }
                // A timed-out cohort must not be reused by the repaired
                // singleton portfolio replay path.
                unset($metadata['full_validation_batch']);
                $restoreOperationalQuarantine = $afterCodeRepair
                    && $this->restoreOperationalQuarantine($agent, $metadata);
                $modelUpdate = ['metadata' => $metadata];
                if ($restoreOperationalQuarantine) {
                    $modelUpdate += [
                        'evidence_status' => 'valid',
                        'invalidated_at' => null,
                        'invalidation_reason' => null,
                    ];
                }
                $agent->modelVersion?->update($modelUpdate);
                $agent->update([
                    'lifecycle_status' => 'full_queued',
                    'decision_reason' => $afterProofRepair
                        ? 'Post-proof-verifier repair full replay; historical mismatch preserved and strategy verdict remains withheld until a fresh canonical replay.'
                        : 'Post-code-repair full evaluator recovery; strategy verdict remains withheld until clean replay.',
                ]);
                $agent->generation()->update(['status' => 'full_validation', 'completed_at' => null]);
            }
        });

        $jobs = $agents->map(fn (LabAgent $agent) => new EvaluateLabAgentJob($agent->id, $agent->symbol, 'full'))->all();
        $batch = Bus::batch($jobs)
            ->name('Bounded full evaluator recovery')
            ->allowFailures()
            ->onConnection((string) config('queue.default', 'redis'))
            ->onQueue('lab-full-validation')
            ->dispatch();

        $this->info('Queued '.$agents->count().' full evaluator recoveries; batch '.$batch->id.'. No promotion evidence was created.');

        return self::SUCCESS;
    }

    private function isFullQueueError(string $reason): bool
    {
        $reason = strtolower($reason);

        return str_contains($reason, 'full queue evaluation error')
            || str_contains($reason, 'dataset export lock')
            || str_contains($reason, 'foundation training')
            || str_contains($reason, 'foundation archive')
            || str_contains($reason, 'continuity')
            || str_contains($reason, 'replay worker exited before returning evidence')
            || str_contains($reason, 'undefined method')
            || str_contains($reason, 'curl error')
            || str_contains($reason, 'operation timed out');
    }

    private function isRepairableTechnicalQuarantine(LabAgent $agent): bool
    {
        if ($agent->lifecycle_status !== 'technical_quarantine') {
            return false;
        }

        $reason = strtolower((string) $agent->decision_reason);

        return str_contains($reason, 'full_replay_dataset_coverage_insufficient')
            || str_contains($reason, 'foundation_dataset_continuity_passport_invalid')
            || str_contains($reason, 'foundation training')
            || str_contains($reason, 'foundation archive')
            // A previous coverage quarantine used to invalidate the root
            // passport. The next admission then reported a secondary
            // CONTROL_ROOT_SEED_PROTOCOL_INVALID error. Recover only when
            // the append-only preflight ledger proves that exact history.
            || ($this->hasPreviousCoverageQuarantine($agent)
                && str_contains($reason, 'control_root_seed_protocol_invalid'));
    }

    private function restoreOperationalQuarantine(LabAgent $agent, array &$metadata): bool
    {
        $model = $agent->modelVersion;
        $errors = array_values(array_unique((array) data_get($metadata, 'preflight_quarantine.errors', [])));
        $coverageOnly = $errors !== []
            && array_diff($errors, [
                'FULL_REPLAY_DATASET_COVERAGE_INSUFFICIENT',
                'FOUNDATION_DATASET_CONTINUITY_PASSPORT_INVALID',
            ]) === []
            && array_intersect($errors, [
                'FULL_REPLAY_DATASET_COVERAGE_INSUFFICIENT',
                'FOUNDATION_DATASET_CONTINUITY_PASSPORT_INVALID',
            ]) !== [];
        $secondaryRootError = $this->hasPreviousCoverageQuarantine($agent)
            && in_array('CONTROL_ROOT_SEED_PROTOCOL_INVALID', $errors, true);
        if (! $model
            || $model->evidence_status !== 'stale_quarantine'
            || $model->invalidation_reason !== 'strict_lab_agent_preflight_failed'
            || (! $coverageOnly && ! $secondaryRootError)) {
            return false;
        }

        data_set($metadata, 'preflight_quarantine.classification', 'operational');
        data_set($metadata, 'preflight_quarantine.restored_at', now()->utc()->toIso8601String());
        data_set($metadata, 'preflight_quarantine.restoration_protocol', 'operational_dataset_quarantine_repair_v1');
        data_set($metadata, 'preflight_quarantine.promotion_evidence', false);

        return true;
    }

    private function hasPreviousCoverageQuarantine(LabAgent $agent): bool
    {
        if (! DB::getSchemaBuilder()->hasTable('lab_lifecycle_events')) {
            return false;
        }

        return DB::table('lab_lifecycle_events')
            ->where('lab_agent_id', $agent->id)
            ->where('phase', 'preflight')
            ->where('event_type', 'preflight_quarantine')
            ->where('payload', 'like', '%FULL_REPLAY_DATASET_COVERAGE_INSUFFICIENT%')
            ->get(['payload'])
            ->contains(function ($event): bool {
                $payload = json_decode((string) $event->payload, true);
                $errors = array_values(array_unique((array) data_get($payload, 'preflight.errors', [])));

                return $errors === ['FULL_REPLAY_DATASET_COVERAGE_INSUFFICIENT'];
            });
    }

    private function hasQueuedFullJob(LabAgent $agent): bool
    {
        $needle = 'labAgentId";i:'.(int) $agent->id.';';

        return DB::table('jobs')->where('queue', 'lab-full-validation')
            ->get(['payload'])
            ->contains(function (object $job) use ($needle): bool {
                $payload = json_decode((string) $job->payload, true);
                $command = (string) data_get($payload, 'data.command', '');

                return str_contains($command, $needle);
            });
    }

    private function isStaleTrainingWithoutEvidence(LabAgent $agent): bool
    {
        if ($agent->lifecycle_status !== 'training'
            || ! $agent->updated_at
            || $agent->updated_at->gt(now()->subMinutes(10))) {
            return false;
        }

        return ! ModelMarketPerformance::query()
            ->where('model_version_id', $agent->model_version_id)
            ->where('symbol', $agent->symbol)
            ->where('timeframe', $agent->timeframe)
            ->exists();
    }

    private function isFoundationContinuityFailure(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'foundation dataset continuity gate failed')
            || str_contains($message, 'foundation dataset snapshot continuity passport invalid')
            || str_contains($message, 'foundation_dataset_continuity');
    }

    private function quarantineFoundationBlockedAgents($agents, \Throwable $exception): int
    {
        $preflight = app(LabAgentPreflightService::class);
        $count = 0;
        foreach ($agents as $agent) {
            if (! in_array($agent->lifecycle_status, ['evaluation_error', 'training', 'full_queued'], true)) {
                continue;
            }

            $inspection = [
                'protocol' => LabAgentPreflightService::PROTOCOL,
                'passed' => false,
                'errors' => ['FOUNDATION_DATASET_CONTINUITY_PASSPORT_INVALID'],
                'stage' => 'full_validation',
                'agent_id' => $agent->id,
                'generation_id' => $agent->lab_generation_id,
                'failure_context' => [
                    'protocol' => 'foundation_continuity_blocked_recovery_v1',
                    'exception' => mb_substr($exception->getMessage(), 0, 1000),
                    'quality_verdict' => 'withheld',
                    'promotion_evidence' => false,
                ],
                'promotion_evidence' => false,
            ];
            $preflight->quarantine($agent, $inspection, 'foundation_continuity_blocked');
            $count++;
        }

        return $count;
    }

    private function hasLegacyProofMismatch(LabAgent $agent): bool
    {
        if ($agent->generation?->status !== 'completed') {
            return false;
        }

        $decision = CandidateGateDecision::query()
            ->where('lab_agent_id', $agent->id)
            ->where('stage', 'statistical_forward_gate')
            ->latest('evaluated_at')
            ->first();

        return in_array('QUARANTINED_PROOF_REPLAY_MISMATCH', (array) $decision?->reason_codes, true)
            && $agent->lifecycle_status !== 'forward_validated';
    }
}
