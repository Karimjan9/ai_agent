<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
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

    public function handle(): int
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
                $query->whereIn('lifecycle_status', $afterProofRepair
                    ? ['challenger', 'rejected', 'overfit', 'stagnated']
                    : ['evaluation_error', 'training']);
            })
            ->when($agentId, fn ($query) => $query->where('id', $agentId))
            ->where('timeframe', $timeframe)
            ->when($symbol, fn ($query) => $query->where('symbol', $symbol))
            ->whereHas('generation', function ($query) use ($generationNumber): void {
                $query->whereIn('status', ['full_validation', 'completed', 'screened']);
                if ($generationNumber !== null) {
                    $query->where('generation', $generationNumber);
                }
            })
            ->when(! $afterCodeRepair, fn ($query) => $query->where('updated_at', '<=', now()->subMinutes(5)))
            ->orderBy('id')->limit($limit * 3)->get()
            ->filter(fn (LabAgent $agent): bool => (($agent->lifecycle_status === 'evaluation_error' && $this->isFullQueueError((string) $agent->decision_reason))
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

        DB::transaction(function () use ($agents, $afterProofRepair): void {
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
                $agent->modelVersion?->update(['metadata' => $metadata]);
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
            || str_contains($reason, 'curl error 28')
            || str_contains($reason, 'operation timed out');
    }

    private function hasQueuedFullJob(LabAgent $agent): bool
    {
        return DB::table('jobs')->where('queue', 'lab-full-validation')
            ->where('payload', 'like', '%labAgentId%'.$agent->id.'%')->exists();
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
