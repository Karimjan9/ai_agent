<?php

namespace App\Services;

use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;

/**
 * Repairs only the mutable generation projection after all screening work is
 * provably terminal. Strategy, promotion, and learning evidence remain
 * untouched; open agents, runs, or queue ownership make the repair fail closed.
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
        if (! in_array((string) $generation->status, ['queued', 'screening'], true)) {
            return $this->blocked('GENERATION_ALREADY_TERMINAL', $generation);
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

        $backlog = $this->queueJobs->generationQueueBacklog($agentIds);
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
        $watermark = $this->watermarks->reconcile($generation->laboratory->symbol, $generation->laboratory->timeframe, $generation);
        if (($watermark['generation_close_allowed'] ?? false) !== true) {
            return $this->blocked('SETTLEMENT_WATERMARK_NOT_TERMINAL', $generation, ['settlement_watermark' => $watermark]);
        }

        $screened = $agents->where('lifecycle_status', 'screened')->isNotEmpty();
        $status = $screened ? 'screened' : 'technical_quarantine';
        $fromStatus = (string) $generation->status;
        $this->contexts->updateWithAttributes($generation, [
            'status' => $status,
            'completed_at' => now(),
        ], function (array $context) use ($fromStatus, $status, $watermark): array {
            $context['screening_terminal_recovery'] = [
                'protocol' => self::PROTOCOL,
                'recovered_from_status' => $fromStatus,
                'status' => $status,
                'recovered_at' => now()->utc()->toIso8601String(),
                'all_agents_terminal' => true,
                'open_evidence_runs' => 0,
                'generation_queue_total' => 0,
                'settlement_watermark' => $watermark,
                'quality_verdict' => 'unchanged',
                'promotion_evidence' => false,
            ];

            return $context;
        });
        $this->reports->record(
            $generation->fresh(['agents']),
            $screened ? 'screening_completed_recovered' : 'screening_technical_quarantine_recovered',
        );

        return [
            'protocol' => self::PROTOCOL,
            'closed' => true,
            'reason_code' => 'TERMINAL_SCREENING_BOUNDARY_REPAIRED',
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
