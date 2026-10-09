<?php

namespace App\Services;

use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;

/**
 * Repairs only the mutable generation projection after all generation-owned
 * work is provably terminal. Strategy, promotion, and learning evidence remain
 * untouched; open agents, runs, queue ownership, or settlement work make the
 * repair fail closed.
 */
class LabGenerationTerminalBoundaryService
{
    public const PROTOCOL = 'generation_terminal_boundary_recovery_v2';

    public function __construct(
        private readonly LabGenerationContextService $contexts,
        private readonly LabGenerationReportService $reports,
        private readonly LabQueueJobInspector $queueJobs,
        private readonly SettlementWatermarkService $watermarks,
    ) {
    }

    /** @return array<string, mixed> */
    public function closeLatest(string $symbol, string $timeframe): array
    {
        $generation = LabGeneration::query()
            ->whereHas('laboratory', fn ($query) => $query
                ->where('symbol', strtoupper($symbol))
                ->where('timeframe', strtoupper($timeframe)))
            ->latest('id')
            ->first();

        if (! $generation) {
            return $this->blocked('GENERATION_NOT_FOUND');
        }

        return $this->closeIfTerminal($generation);
    }

    /** @return array<string, mixed> */
    public function closeIfTerminal(LabGeneration $generation): array
    {
        $generation = $generation->fresh(['agents']);
        if (! $generation) {
            return $this->blocked('GENERATION_NOT_FOUND');
        }
        if (! in_array((string) $generation->status, ['queued', 'screening', 'full_validation'], true)) {
            return $this->blocked('GENERATION_ALREADY_TERMINAL', $generation);
        }

        try { $depthDisposition = app(NativeReachabilityDepthAuditService::class)->reconcileGeneration($generation); }
        catch (\Throwable $error) {
            return $this->blocked('NATIVE_DEPTH_AUDIT_ORIGINAL_EVIDENCE_INVALID', $generation,
                ['native_depth_audit_reason' => str_starts_with($error->getMessage(), 'NATIVE_DEPTH_AUDIT_')
                    ? $error->getMessage() : 'ORIGINAL_DEPTH_OWNER_UNAVAILABLE']);
        }
        if ($depthDisposition !== null) {
            if (($depthDisposition['status'] ?? null) !== 'recorded') {
                return $this->blocked('NATIVE_DEPTH_AUDIT_ORIGINAL_PHASE_NOT_TERMINAL', $generation,
                    ['native_depth_audit_disposition' => $depthDisposition]);
            }
            $generation = $generation->fresh(['agents']);
        }

        // Four original study sources are references, not replay candidates.
        // The original pair owner alone can close them neutrally after both
        // immutable arm envelopes are terminal; missing/queued work stays open.
        try { $studyDisposition = app(NativeSpreadContextStudyService::class)->reconcileGeneration($generation); }
        catch (\Throwable $error) {
            return $this->blocked('NATIVE_SPREAD_STUDY_ORIGINAL_PAIR_EVIDENCE_INVALID', $generation,
                ['native_spread_study_reason' => str_starts_with($error->getMessage(), 'NATIVE_SPREAD_STUDY_')
                    ? $error->getMessage() : 'ORIGINAL_STUDY_OWNER_UNAVAILABLE']);
        }
        if ($studyDisposition !== null) {
            if (($studyDisposition['status'] ?? null) !== 'recorded') {
                return $this->blocked('NATIVE_SPREAD_STUDY_ORIGINAL_PAIR_NOT_TERMINAL', $generation,
                    ['native_spread_study_disposition' => $studyDisposition]);
            }
            $generation = $generation->fresh(['agents']);
        }

        $agents = $generation->agents;
        if ($agents->isEmpty()) {
            return $this->blocked('GENERATION_HAS_NO_AGENTS', $generation);
        }

        $openStatuses = [
            'draft', 'queued', 'screening', 'evaluation_error', 'full_queued',
            'full_validation', 'training',
        ];
        if ($agents->contains(fn ($agent): bool => in_array((string) $agent->lifecycle_status, $openStatuses, true))) {
            return $this->blocked('OPEN_AGENT_WORK_REMAINS', $generation);
        }

        $agentIds = $agents->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $openRuns = LabEvaluationRun::query()
            ->where('lab_generation_id', $generation->id)
            ->whereIn('lab_agent_id', $agentIds)
            ->whereIn('status', ['started', 'running', 'processing'])
            ->count();
        if ($openRuns > 0) {
            return $this->blocked('OPEN_EVIDENCE_RUNS_REMAIN', $generation, ['open_runs' => $openRuns]);
        }

        // A screening response is not the end of a generation. Its
        // generation-owned learning projection closes cooperative blocks,
        // causal outcomes and episode settlements after the immutable replay
        // has been sealed. Keep the mutable generation open while either the
        // replay job or that exact post-screen projection still owns a queue
        // row. The global replay allocator intentionally ignores the
        // research queue; this stricter queue set is local to terminality.
        $terminalQueues = array_values(array_unique(array_filter([
            (string) config('services.lab_queue.screening_queue', 'lab-screening'),
            (string) config('services.lab_queue.frontier_queue', 'lab-frontier'),
            (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation'),
            ...((array) config('services.lab_queue.legacy_screening_queues', [])),
            (string) config('services.lab_queue.learning_queue', 'lab-learning'),
        ])));
        $backlog = $this->queueJobs->generationQueueBacklog($agentIds, $terminalQueues);
        if (($backlog['available'] ?? true) === false || ($backlog['total'] ?? null) === null) {
            return $this->blocked('QUEUE_STATE_UNKNOWN', $generation);
        }
        if ((int) $backlog['total'] > 0) {
            return $this->blocked('GENERATION_QUEUE_WORK_REMAINS', $generation, [
                'queue_total' => (int) $backlog['total'],
            ]);
        }

        // A terminal agent projection is not enough: every learning episode
        // must have exactly one terminal settlement (or an explicit technical
        // / legacy disposition) before the generation can close.
        $observedDisposition = app(ObservedCouncilEpisodeDispositionService::class)->reconcileGeneration($generation);
        if (($observedDisposition['status'] ?? null) === 'blocked') {
            return $this->blocked('OBSERVED_COUNCIL_EPISODE_DISPOSITION_BLOCKED', $generation, ['observed_episode_disposition' => $observedDisposition]);
        }
        $watermark = $this->watermarks->reconcile($generation->laboratory->symbol, $generation->laboratory->timeframe, $generation);
        if (($watermark['generation_close_allowed'] ?? false) !== true) {
            return $this->blocked('SETTLEMENT_WATERMARK_NOT_TERMINAL', $generation, ['settlement_watermark' => $watermark]);
        }

        $technicalAgentIds = $agents->filter(fn ($agent): bool => in_array(
            (string) $agent->lifecycle_status,
            ['technical_quarantine', 'quarantined', 'legacy_quarantine', 'abandoned', 'failed'],
            true,
        ))->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
        $fromStatus = (string) $generation->status;
        $fullValidationBoundary = $fromStatus === 'full_validation';
        $screened = ! $fullValidationBoundary
            && $technicalAgentIds === []
            && $agents->where('lifecycle_status', 'screened')->isNotEmpty();
        $status = $technicalAgentIds !== []
            ? 'technical_quarantine'
            : ($fullValidationBoundary ? 'completed' : ($screened ? 'screened' : 'technical_quarantine'));
        if (($observedDisposition['status'] ?? null) === 'settled_zero_authority') $status = 'technical_quarantine';
        $this->contexts->updateWithAttributes($generation, [
            'status' => $status,
            'completed_at' => now(),
        ], function (array $context) use ($fromStatus, $status, $watermark, $technicalAgentIds, $fullValidationBoundary, $observedDisposition): array {
            $receipt = [
                'protocol' => self::PROTOCOL,
                'recovered_from_status' => $fromStatus,
                'status' => $status,
                'recovered_at' => now()->utc()->toIso8601String(),
                'all_agents_terminal' => true,
                'open_evidence_runs' => 0,
                'generation_queue_total' => 0,
                'settlement_watermark' => $watermark,
                'technical_agent_ids' => $technicalAgentIds,
                'quality_verdict' => 'unchanged',
                'promotion_evidence' => false,
            ];
            $context['generation_terminal_recovery'] = $receipt;
            if (($observedDisposition['status'] ?? null) === 'settled_zero_authority') {
                $receipt['observed_episode_disposition'] = $observedDisposition;
                $context['generation_terminal_recovery'] = $receipt;
            }
            if ($fullValidationBoundary) {
                $context['full_validation_terminal'] = $receipt;
            } else {
                $context['screening_terminal_recovery'] = $receipt;
                // Keep the original public projection key readable for reports
                // and operators while its protocol identifies the stricter v2
                // boundary.
                $context['screening_terminal'] = $receipt;
            }

            return $context;
        });
        $reportReason = $fullValidationBoundary
            ? ($status === 'completed'
                ? 'full_validation_completed_recovered'
                : 'full_validation_technical_quarantine_recovered')
            : ($status === 'screened' ? 'screening_completed_recovered' : 'screening_technical_quarantine_recovered');
        $this->reports->record(
            $generation->fresh(['agents']),
            $reportReason,
        );

        return [
            'protocol' => self::PROTOCOL,
            'closed' => true,
            'reason_code' => $fullValidationBoundary
                ? 'TERMINAL_FULL_VALIDATION_BOUNDARY_REPAIRED'
                : 'TERMINAL_SCREENING_BOUNDARY_REPAIRED',
            'generation_id' => (int) $generation->id,
            'from_status' => $fromStatus,
            'status' => $status,
            'promotion_evidence' => false,
        ];
    }

    /** @param array<string, mixed> $extra */
    private function blocked(string $reason, ?LabGeneration $generation = null, array $extra = []): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'closed' => false,
            'reason_code' => $reason,
            'generation_id' => $generation ? (int) $generation->id : null,
            ...$extra,
            'promotion_evidence' => false,
        ];
    }
}
