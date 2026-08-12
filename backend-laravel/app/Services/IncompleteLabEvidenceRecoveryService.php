<?php

namespace App\Services;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/** Replays only incomplete screening evidence against the same snapshot. */
class IncompleteLabEvidenceRecoveryService
{
    public const PROTOCOL = 'incomplete_screening_evidence_recovery_v1';
    private const MAX_ATTEMPTS = 1;

    public function __construct(
        private LabImmutableEvidenceService $evidence,
        private LabReplayRecoveryService $recovery,
    ) {}

    /** @return array{selected: int, requeued: int, quarantined: int, skipped: int, rows: array<int, array<string, mixed>>} */
    public function recover(?string $symbol = null, ?string $timeframe = null, ?int $generation = null, int $limit = 20, bool $apply = false, bool $scheduledSweep = false, ?array $approval = null): array
    {
        if ($apply && $approval === null) {
            return [
                'selected' => 0, 'requeued' => 0, 'quarantined' => 0, 'skipped' => 0,
                'deferred' => true, 'action' => 'operator_approval_required', 'rows' => [],
            ];
        }
        // Recovery jobs use the frontier lane directly because a missing
        // request/response/trace/ledger boundary outranks ordinary
        // screening. Keep that lane bounded even when this service is called
        // directly (outside the scheduled command's queue guard).
        $frontierCapacity = null;
        if ($apply && DB::getSchemaBuilder()->hasTable('jobs')) {
            $frontierLimit = max(1, (int) config('services.lab_queue.frontier_backlog_limit', 4));
            $existingFrontier = (int) DB::table('jobs')
                ->where('queue', (string) config('services.lab_queue.frontier_queue', 'lab-frontier'))
                ->count();
            $frontierCapacity = max(0, $frontierLimit - $existingFrontier);
            if ($frontierCapacity === 0) {
                return [
                    'selected' => 0,
                    'requeued' => 0,
                    'quarantined' => 0,
                    'skipped' => 0,
                    'deferred' => true,
                    'action' => 'deferred_frontier_backlog',
                    'frontier_capacity' => 0,
                    'rows' => [],
                ];
            }
        }

        $requestedLimit = max(1, min(50, $limit));
        $selectionLimit = $frontierCapacity === null
            ? $requestedLimit
            : min($requestedLimit, $frontierCapacity);
        $agents = LabAgent::query()->with(['generation.laboratory', 'modelVersion'])
            ->whereIn('lifecycle_status', ['screened', 'technical_quarantine'])
            ->when($symbol, fn ($query) => $query->where('symbol', strtoupper($symbol)))
            ->when($timeframe, fn ($query) => $query->where('timeframe', strtoupper($timeframe)))
            ->when($generation !== null, fn ($query) => $query->whereHas('generation', fn ($g) => $g->where('generation', $generation)))
            ->when($scheduledSweep, fn ($query) => $query->whereHas('generation', fn ($g) => $g
                ->where('created_at', '>=', now()->subHours(6))
                ->whereIn('status', ['screening', 'screened', 'full_validation'])))
            ->whereHas('generation', fn ($query) => $query->whereIn('status', ['screening', 'screened', 'full_validation']))
            ->orderBy('id')->get()
            ->filter(fn (LabAgent $agent): bool => $this->isIncompleteScreenCandidate($agent))
            ->take($selectionLimit)
            ->values();

        $rows = [];
        $requeued = $quarantined = $skipped = 0;
        foreach ($agents as $agent) {
            $attempts = (int) data_get($agent->modelVersion?->metadata, 'evidence_recovery.attempts', 0);
            $row = [
                'agent_id' => (int) $agent->id,
                'generation_id' => (int) $agent->lab_generation_id,
                'symbol' => $agent->symbol,
                'timeframe' => $agent->timeframe,
                'attempts' => $attempts,
                'action' => 'skipped',
                'reason' => 'not_applied',
                'promotion_evidence' => false,
            ];

            if ($attempts >= self::MAX_ATTEMPTS) {
                if ($apply) {
                    $this->quarantine($agent, 'EVIDENCE_RECOVERY_RETRY_EXHAUSTED');
                    $row['action'] = 'technical_quarantine';
                    $row['reason'] = 'EVIDENCE_RECOVERY_RETRY_EXHAUSTED';
                    $quarantined++;
                } else {
                    $row['action'] = 'would_quarantine';
                    $row['reason'] = 'EVIDENCE_RECOVERY_RETRY_EXHAUSTED';
                    $skipped++;
                }
                $rows[] = $row;
                continue;
            }

            try {
                $contract = $this->recovery->prepare($agent, 'screen');
            } catch (\Throwable $exception) {
                if ($apply) {
                    $this->quarantine($agent, 'RECOVERY_DATASET_CONTRACT_INVALID', $exception->getMessage());
                    $row['action'] = 'technical_quarantine';
                    $row['reason'] = 'RECOVERY_DATASET_CONTRACT_INVALID';
                    $quarantined++;
                } else {
                    $row['action'] = 'would_quarantine';
                    $row['reason'] = 'RECOVERY_DATASET_CONTRACT_INVALID';
                    $skipped++;
                }
                $rows[] = $row;
                continue;
            }

            if (! $apply) {
                $row['action'] = 'would_requeue';
                $row['reason'] = 'SAME_GENERATION_EVIDENCE_CONTRACT_READY';
                $skipped++;
                $rows[] = $row;
                continue;
            }

            $this->requeue($agent, $contract, $attempts);
            $row['action'] = 'requeued';
            $row['reason'] = 'SAME_GENERATION_EVIDENCE_CONTRACT_READY';
            $requeued++;
            $rows[] = $row;
        }

        return compact('requeued', 'quarantined', 'skipped') + [
            'selected' => $agents->count(),
            'frontier_capacity' => $frontierCapacity,
            'rows' => $rows,
        ];
    }

    private function isIncompleteScreenCandidate(LabAgent $agent): bool
    {
        $decision = CandidateGateDecision::query()
            ->where('lab_agent_id', $agent->id)->where('stage', 'screening')
            ->latest('id')->first();
        // A complete response may legitimately fail an economic gate and
        // must never be replayed here.  An interrupted response can already
        // have projected a normal `failed` screening decision, however; the
        // immutable run is the authority for recovery, not the projection's
        // verdict label.  Accept both projection states and then require the
        // persisted evidence chain to be incomplete below.
        if (! $decision || ! in_array((string) $decision->decision, ['failed', 'insufficient_evidence'], true)) return false;

        $run = LabEvaluationRun::query()->where('lab_agent_id', $agent->id)
            ->where('phase', 'screening')->latest('id')->first();
        if ($agent->lifecycle_status === 'technical_quarantine'
            && ! $this->isOperationalEvidenceQuarantine($run)) return false;
        if (! $run || $run->status !== 'completed') {
            return $agent->lifecycle_status === 'screened'
                || $this->isOperationalEvidenceQuarantine($run);
        }

        return ! $this->evidence->learningEligibility($run)['complete'];
    }

    private function isOperationalEvidenceQuarantine(?LabEvaluationRun $run): bool
    {
        $reason = (string) data_get($run?->metadata, 'reason_code', '');

        return in_array($reason, [
            'STALE_QUEUE_RESERVATION_RECOVERED',
            'ORPHANED_OPEN_RUN_RECONCILED',
            'QUEUE_MIDDLEWARE_RELEASE',
        ], true);
    }

    /** @param array<string, mixed> $contract */
    private function requeue(LabAgent $agent, array $contract, int $attempts): void
    {
        DB::transaction(function () use ($agent, $contract, $attempts): void {
            $metadata = (array) ($agent->modelVersion?->metadata ?? []);
            data_set($metadata, 'evidence_recovery', [
                'protocol' => self::PROTOCOL,
                'attempts' => $attempts + 1,
                'status' => 'retry_dispatched',
                'generation_id' => $agent->lab_generation_id,
                'dataset_hashes' => data_get($contract, 'dataset_hashes', []),
                'requeued_at' => now()->utc()->toIso8601String(),
                'promotion_evidence' => false,
            ]);
            $agent->modelVersion?->update(['metadata' => $metadata]);
            $from = (string) $agent->lifecycle_status;
            $agent->update([
                'lifecycle_status' => 'queued',
                'sample_count' => 0,
                'profit_factor' => null,
                'max_drawdown' => null,
                'risk_of_ruin' => null,
                'train_score' => null,
                'validation_score' => null,
                'forward_score' => null,
                'champion_improvement' => null,
                'rolling_wins' => 0,
                'decision_reason' => 'Incomplete screening evidence; one same-generation replay dispatched against frozen snapshots. Quality verdict withheld.',
            ]);
            $fresh = $agent->fresh();
            $this->evidence->recordLifecycle($fresh, 'incomplete_screen_evidence_requeued', [
                'protocol' => self::PROTOCOL,
                'reason_code' => 'INCOMPLETE_SCREENING_EVIDENCE',
                'recovery_contract' => $contract,
                'promotion_evidence' => false,
            ], 'screening', null, $attempts + 1, self::class, null, $from, 'queued');
            $agent->generation()->update(['status' => 'screening', 'completed_at' => null]);
            Bus::dispatch(new EvaluateLabAgentJob(
                $fresh->id,
                $fresh->symbol,
                'screen',
                $contract,
                (string) config('services.lab_queue.frontier_queue', 'lab-frontier'),
            ));
        });
    }

    private function quarantine(LabAgent $agent, string $reason, ?string $detail = null): void
    {
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);
        data_set($metadata, 'evidence_recovery', [
            'protocol' => self::PROTOCOL,
            'attempts' => (int) data_get($metadata, 'evidence_recovery.attempts', 0),
            'status' => 'technical_quarantine',
            'quarantined_at' => now()->utc()->toIso8601String(),
            'reason' => $reason,
            'promotion_evidence' => false,
        ]);
        $agent->modelVersion?->update(['metadata' => $metadata]);
        $agent->update([
            'lifecycle_status' => 'technical_quarantine',
            'decision_reason' => 'Technical quarantine: incomplete evidence recovery blocked or exhausted; strategy verdict withheld.'.($detail ? ' '.substr($detail, 0, 300) : ''),
        ]);
        $this->evidence->recordLifecycle($agent->fresh(), 'technical_quarantine', [
            'protocol' => self::PROTOCOL,
            'reason_code' => $reason,
            'quality_verdict' => 'withheld',
            'promotion_evidence' => false,
        ], 'screening', null, null, self::class);

        $generation = $agent->generation()->with('agents')->first();
        if ($generation && ! $generation->agents->contains(fn (LabAgent $peer): bool => in_array($peer->lifecycle_status, ['draft', 'queued', 'screening', 'training', 'evaluation_error'], true))) {
            $generation->update(['status' => 'screened', 'completed_at' => now()]);
        }
    }
}
