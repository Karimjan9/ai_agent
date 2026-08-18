<?php

namespace App\Services;

use App\Models\CandidateHandoffEvent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLaneDispatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the evidence boundary agree across Redis, worker runs and Laravel's
 * job_batches projection. It only closes an already-drained batch; it never
 * cancels live work and never changes a gate or strategy result.
 */
class LabBatchTerminalityService
{
    public const PROTOCOL = 'lab_batch_terminality_v1';

    /** @return array<string, mixed> */
    public function reconcile(LabGeneration $generation, bool $apply = true): array
    {
        $generation->loadMissing('agents');
        $agentIds = $generation->agents->pluck('id')->map(fn ($id): int => (int) $id)->filter()->values()->all();
        $queue = app(LabQueueJobInspector::class)->generationQueueBacklog($agentIds);
        $queueAvailable = ($queue['available'] ?? true) === true;
        $queueRows = collect((array) ($queue['rows'] ?? []));
        $queueTerminal = $queueAvailable && $queueRows->isEmpty();

        $batchIds = LabLearningLaneDispatch::query()
            ->where('lab_generation_id', $generation->id)
            ->pluck('queue_batch_id')
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->all();
        $contextBatchIds = collect((array) data_get($generation->trigger_context, 'queue_batches', []))
            ->flatten()
            ->filter(fn ($id): bool => filled($id))
            ->map(fn ($id): string => (string) $id)
            ->all();
        $handoffBatchIds = CandidateHandoffEvent::query()
            ->where('lab_generation_id', $generation->id)
            ->get(['payload'])
            ->flatMap(fn (CandidateHandoffEvent $event): array => array_values(array_filter([
                data_get($event->payload, 'queue_batch_id'),
                data_get($event->payload, 'queue_job_id'),
            ], fn ($id): bool => filled($id))))
            ->map(fn ($id): string => (string) $id)
            ->all();
        $batchIds = array_values(array_unique([...$batchIds, ...$contextBatchIds, ...$handoffBatchIds]));

        $batchRows = Schema::hasTable('job_batches') && $batchIds !== []
            ? DB::table('job_batches')->whereIn('id', $batchIds)->get()
            : collect();
        $activeRunCount = LabEvaluationRun::query()
            ->where('lab_generation_id', $generation->id)
            ->whereIn('status', ['queued', 'started', 'running', 'processing'])
            ->count();
        $screenRunCount = LabEvaluationRun::query()
            ->where('lab_generation_id', $generation->id)
            ->where('phase', 'screening')
            ->distinct('lab_agent_id')
            ->count('lab_agent_id');
        $activeAgentStatuses = ['draft', 'queued', 'screening', 'training', 'full_queued', 'full_validation'];
        // Lifecycle terminality and evidence coverage are separate facts. A
        // technically quarantined child may have no screen run at all; it is
        // safe to close its stale Laravel batch projection, but that absence
        // must still prevent FINAL/promotion evidence.
        $allAgentsTerminal = $generation->agents->isNotEmpty()
            && $generation->agents->whereIn('lifecycle_status', $activeAgentStatuses)->isEmpty();
        $screenEvidenceCoverage = $screenRunCount >= count($agentIds);

        $reconciled = [];
        if ($apply && $queueTerminal && $activeRunCount === 0 && $allAgentsTerminal && $batchRows->isNotEmpty()) {
            $finishedAt = now()->timestamp;
            foreach ($batchRows as $batch) {
                if ($batch->finished_at !== null || $batch->cancelled_at !== null) continue;
                if ((int) ($batch->pending_jobs ?? 0) <= 0) {
                    // A zero-pending row with no finished marker is the stale
                    // Laravel projection this service is designed to close.
                    DB::table('job_batches')->where('id', $batch->id)->update(['finished_at' => $finishedAt]);
                    $reconciled[] = (string) $batch->id;
                    continue;
                }
                // No Redis pending/reserved/delayed row and no active run is
                // sufficient evidence that the remaining DB count is stale.
                // Preserve failed_jobs; technical failure remains visible.
                DB::table('job_batches')->where('id', $batch->id)->update([
                    'pending_jobs' => 0,
                    'finished_at' => $finishedAt,
                ]);
                $reconciled[] = (string) $batch->id;
            }
            if ($reconciled !== []) {
                $batchRows = DB::table('job_batches')->whereIn('id', $batchIds)->get();
            }
        }

        $dbPending = $batchRows->filter(fn ($batch): bool => $batch->finished_at === null
            && $batch->cancelled_at === null
            && (int) ($batch->pending_jobs ?? 0) > 0)->count();
        $dbUnfinished = $batchRows->filter(fn ($batch): bool => $batch->finished_at === null && $batch->cancelled_at === null)->count();
        $dbTerminal = $dbUnfinished === 0;
        $allowed = $queueTerminal && $activeRunCount === 0 && $allAgentsTerminal
            && $screenEvidenceCoverage && $dbTerminal;

        return [
            'protocol' => self::PROTOCOL,
            'generation_id' => (int) $generation->id,
            'scope' => 'generation_agents_and_handoff_batches',
            'batch_ids' => $batchIds,
            'reconciled_batch_ids' => $reconciled,
            'redis_queue_available' => $queueAvailable,
            'redis_queue_total' => $queue['total'] ?? null,
            'redis_reserved_or_pending_rows' => $queueRows->count(),
            'redis_terminal' => $queueTerminal,
            'active_run_count' => $activeRunCount,
            'screen_run_count' => $screenRunCount,
            'screen_evidence_coverage' => $screenEvidenceCoverage,
            'all_agents_terminal' => $allAgentsTerminal,
            'db_pending_batches' => $dbPending,
            'db_unfinished_batches' => $dbUnfinished,
            'db_batch_terminal' => $dbTerminal,
            'finality' => [
                'allowed' => $allowed,
                'redis_queue_terminal' => $queueTerminal,
                'reserved_jobs_terminal' => $queueTerminal,
                'db_batch_terminal' => $dbTerminal,
                'worker_runs_terminal' => $activeRunCount === 0,
                'agents_terminal' => $allAgentsTerminal,
                'screen_evidence_coverage' => $screenEvidenceCoverage,
                'rule' => 'Evidence is FINAL only when Redis pending/reserved/delayed rows, active runs and DB batch projections are all terminal.',
            ],
            'promotion_evidence' => false,
        ];
    }
}
