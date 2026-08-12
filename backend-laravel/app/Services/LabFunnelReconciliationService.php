<?php

namespace App\Services;

use App\Models\CandidateGateDecision;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use Illuminate\Support\Facades\DB;

/** Repairs stale lifecycle projections without changing any quality verdict. */
class LabFunnelReconciliationService
{
    public function __construct(
        private LabImmutableEvidenceService $evidence,
        private LabQueueJobInspector $queueJobs,
    ) {}

    /** @return array{inspected: int, reconciled: int, skipped: int, rows: array<int, array<string, mixed>>} */
    public function reconcile(?string $symbol = null, ?string $timeframe = null, bool $apply = false, ?array $approval = null): array
    {
        if ($apply && $approval === null) {
            return [
                'inspected' => 0, 'reconciled' => 0, 'skipped' => 0, 'rows' => [],
                'deferred' => true, 'action' => 'operator_approval_required',
            ];
        }
        if ($apply && $this->queueJobs->hasLabJobs()) {
            return [
                'inspected' => 0, 'reconciled' => 0, 'skipped' => 0, 'rows' => [],
                'deferred' => true, 'action' => 'deferred_queue_backlog',
                'queue_backlog' => $this->queueJobs->labQueueBacklog(),
            ];
        }

        $generations = LabGeneration::query()->with(['laboratory', 'agents'])
            ->whereIn('status', ['full_queued', 'full_validation'])
            ->when($symbol, fn ($query) => $query->whereHas('laboratory', fn ($lab) => $lab->where('symbol', strtoupper($symbol))))
            ->when($timeframe, fn ($query) => $query->whereHas('laboratory', fn ($lab) => $lab->where('timeframe', strtoupper($timeframe))))
            ->orderBy('id')->get();

        $rows = [];
        $reconciled = 0;
        foreach ($generations as $generation) {
            $row = $this->inspectGeneration($generation);
            if ($row['eligible_for_projection_repair'] && $apply) {
                $this->apply($generation, $row);
                $row['action'] = 'reconciled_to_screened';
                $reconciled++;
            } else {
                $row['action'] = $row['eligible_for_projection_repair'] ? 'would_reconcile' : 'held_for_review';
            }
            $rows[] = $row;
        }

        return [
            'inspected' => count($rows),
            'reconciled' => $reconciled,
            'skipped' => count($rows) - $reconciled,
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function inspectGeneration(LabGeneration $generation): array
    {
        $agents = $generation->agents;
        $agentIds = $agents->pluck('id')->values()->all();
        $openStatuses = ['draft', 'queued', 'screening', 'training', 'evaluation_error', 'full_queued', 'full_validation'];
        $openAgents = $agents->whereIn('lifecycle_status', $openStatuses)->pluck('id')->values()->all();
        $hasFullJob = $this->hasFullJob($agentIds);
        $hasOpenFullRun = LabEvaluationRun::query()->where('lab_generation_id', $generation->id)
            ->where('phase', 'full_validation')->whereIn('status', ['started'])->exists();
        $hasCompletedFullRun = LabEvaluationRun::query()->where('lab_generation_id', $generation->id)
            ->where('phase', 'full_validation')->where('status', 'completed')->exists();
        $latestScreeningDecisions = CandidateGateDecision::query()
            ->whereIn('lab_agent_id', $agentIds)->where('stage', 'screening')->orderByDesc('id')->get()
            ->groupBy('lab_agent_id')->map(fn ($rows) => $rows->first());
        $hasScreeningPass = $latestScreeningDecisions->contains(fn ($decision): bool => $decision->decision === 'passed');
        $eligible = $openAgents === []
            && ! $hasFullJob
            && ! $hasOpenFullRun
            && ! $hasCompletedFullRun
            && ! $hasScreeningPass;

        return [
            'generation_id' => (int) $generation->id,
            'symbol' => $generation->laboratory?->symbol,
            'timeframe' => $generation->laboratory?->timeframe,
            'generation' => (int) $generation->generation,
            'status' => $generation->status,
            'agent_count' => $agents->count(),
            'open_agent_ids' => $openAgents,
            'full_job_present' => $hasFullJob,
            'open_full_run' => $hasOpenFullRun,
            'completed_full_run' => $hasCompletedFullRun,
            'screening_pass_present' => $hasScreeningPass,
            'eligible_for_projection_repair' => $eligible,
            'promotion_evidence' => false,
        ];
    }

    /** @param array<string, mixed> $row */
    private function apply(LabGeneration $generation, array $row): void
    {
        DB::transaction(function () use ($generation, $row): void {
            $fresh = $generation->fresh(['agents']);
            $fresh->agents()->whereIn('lifecycle_status', ['full_queued', 'full_validation'])->update([
                'lifecycle_status' => 'screened',
                'decision_reason' => 'Full-validation projection reconciled: no screening-pass/evidence-complete candidate or live full replay existed.',
            ]);
            $context = (array) $fresh->trigger_context;
            $context['funnel_projection_reconciliation'] = [
                'protocol' => 'lab_funnel_projection_reconciliation_v1',
                'from_status' => $fresh->status,
                'to_status' => 'screened',
                'reason_code' => 'NO_FULL_ELIGIBLE_CANDIDATE',
                'observed' => $row,
                'promotion_evidence' => false,
                'reconciled_at' => now()->utc()->toIso8601String(),
            ];
            $fresh->update(['status' => 'screened', 'completed_at' => now(), 'trigger_context' => $context]);
            $this->evidence->recordLifecycle(null, 'generation_projection_reconciled', [
                'generation_id' => $fresh->id,
                'from_status' => $generation->status,
                'to_status' => 'screened',
                'reason_code' => 'NO_FULL_ELIGIBLE_CANDIDATE',
                'promotion_evidence' => false,
            ], 'screening', null, null, self::class);
        });
    }

    /** @param array<int, int> $agentIds */
    private function hasFullJob(array $agentIds): bool
    {
        if ($agentIds === [] || ! DB::getSchemaBuilder()->hasTable('jobs')) return false;

        foreach ($agentIds as $agentId) {
            if ($this->queueJobs->hasAgentJob($agentId, ['lab-full-validation'])) {
                return true;
            }
        }

        return false;
    }
}
