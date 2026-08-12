<?php

namespace App\Console\Commands;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Services\LabImmutableEvidenceService;
use App\Services\OperatorApprovalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PromoteLabFrontier extends Command
{
    protected $signature = 'trading:promote-lab-frontier
        {--agent= : Promote one exact agent id}
        {--generation= : Restrict promotion to one generation id}
        {--limit= : Maximum number of frontier jobs; defaults to the configured frontier backlog limit}
        {--apply : Move eligible jobs after explicit operator approval}
        {--approved-by= : Operator approving the queue-priority change}
        {--approval-reason= : Auditable reason for the queue-priority change}';

    protected $description = 'Promote incomplete recoveries and the bounded role cohort ahead of ordinary screening FIFO';

    public function handle(LabImmutableEvidenceService $evidence, OperatorApprovalService $approvals): int
    {
        if (! DB::getSchemaBuilder()->hasTable('jobs')) {
            $this->info('Queue table is not available; frontier promotion skipped.');

            return self::SUCCESS;
        }

        $lock = Cache::lock('trading:promote-lab-frontier:v1', 90);
        if (! $lock->get()) {
            $this->info('Frontier promotion already active; this invocation was safely deferred.');

            return self::SUCCESS;
        }

        try {
            $screeningQueue = (string) config('services.lab_queue.screening_queue', 'lab-screening');
            $frontierQueue = (string) config('services.lab_queue.frontier_queue', 'lab-frontier');
            $frontierLimit = max(1, (int) ($this->option('limit') ?: config('services.lab_queue.frontier_backlog_limit', 4)));
            $existingFrontier = (int) DB::table('jobs')->where('queue', $frontierQueue)->count();
            $capacity = max(0, $frontierLimit - $existingFrontier);
            if ($capacity === 0) {
                $this->info("Frontier queue already has {$existingFrontier}/{$frontierLimit} jobs.");

                return self::SUCCESS;
            }

            $jobs = DB::table('jobs')
                ->where('queue', $screeningQueue)
                ->whereNull('reserved_at')
                ->orderBy('available_at')
                ->orderBy('id')
                ->get(['id', 'queue', 'payload', 'attempts', 'available_at']);

            $candidates = [];
            foreach ($jobs as $job) {
                if (count($candidates) >= $capacity) break;

                $agentId = $this->agentIdFromPayload((string) $job->payload);
                if ($agentId === null) continue;
                if ($this->option('agent') !== null && (int) $this->option('agent') !== $agentId) continue;

                $agent = LabAgent::query()->with(['generation', 'modelVersion'])->find($agentId);
                $frontierReason = $agent ? $this->frontierReason($agent) : null;
                if ($frontierReason === null) continue;
                if ($this->option('generation') !== null && (int) $this->option('generation') !== (int) $agent->lab_generation_id) continue;

                $candidates[] = compact('job', 'agent', 'frontierReason');
            }

            if ($candidates === []) {
                $this->info('No eligible incomplete frontier recovery job was found.');

                return self::SUCCESS;
            }

            if (! (bool) $this->option('apply')) {
                $this->table(['agent', 'generation', 'job', 'reason', 'action'], array_map(
                    fn (array $candidate): array => [
                        $candidate['agent']->id,
                        $candidate['agent']->lab_generation_id,
                        $candidate['job']->id,
                        $candidate['frontierReason'],
                        'would_promote_after_operator_approval',
                    ],
                    $candidates,
                ));
                $this->info('Dry-run only: no queue priority changed. Use --apply with --approved-by and --approval-reason for an explicit bounded promotion.');

                return self::SUCCESS;
            }

            try {
                $approvals->requireForApply('promote-lab-frontier', $this->option('approved-by'), $this->option('approval-reason'), [
                    'agent_ids' => array_map(fn (array $candidate): int => (int) $candidate['agent']->id, $candidates),
                    'generation_ids' => array_values(array_unique(array_map(fn (array $candidate): int => (int) $candidate['agent']->lab_generation_id, $candidates))),
                    'queue_from' => $screeningQueue,
                    'queue_to' => $frontierQueue,
                ]);
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $promoted = 0;
            foreach ($candidates as $candidate) {
                $job = $candidate['job'];
                $agent = $candidate['agent'];
                $frontierReason = $candidate['frontierReason'];
                $changed = DB::table('jobs')
                    ->where('id', $job->id)
                    ->where('queue', $screeningQueue)
                    ->whereNull('reserved_at')
                    ->update(['queue' => $frontierQueue]);
                if ($changed !== 1) continue;

                $promoted++;
                $evidence->recordLifecycle($agent, 'frontier_queue_promoted', [
                    'reason_code' => $frontierReason,
                    'queue_from' => $screeningQueue,
                    'queue_to' => $frontierQueue,
                    'job_id' => (int) $job->id,
                    'attempts_at_promotion' => (int) $job->attempts,
                    'rule' => $frontierReason === 'ROLE_COMPLETE_COHORT_PRIORITY'
                        ? 'The four complementary role seats are prioritized as one bounded frontier cohort; no second worker or duplicate job is created.'
                        : 'A missing request/response/trace/ledger boundary is prioritized without deleting or duplicating the immutable recovery job.',
                    'promotion_evidence' => false,
                ], 'screening', null, null, self::class);

                $this->info("Agent {$agent->id}: job {$job->id} promoted to {$frontierQueue}.");
            }

            $this->info("Promoted {$promoted} frontier job(s) after operator approval.");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    private function frontierReason(LabAgent $agent): ?string
    {
        if ($agent->lifecycle_status !== 'queued' || $agent->generation?->status !== 'screening') {
            return null;
        }

        $run = LabEvaluationRun::query()
            ->where('lab_agent_id', $agent->id)
            ->where('phase', 'screening')
            ->latest('id')
            ->first();

        // A retry release with no response artifact is the precise recovery
        // boundary. It always outranks a fresh role cohort.
        if ($run !== null
            && $run->status === 'retry_released'
            && blank($run->response_hash)) {
            return 'INCOMPLETE_SCREENING_EVIDENCE_RECOVERY';
        }

        // A role-complete generation is itself a deliberately bounded
        // frontier experiment. Once dispatched, move its four fresh seats
        // ahead of ordinary pair screening so the complementary cohort is
        // evaluated together instead of being diluted by an older FIFO tail.
        if ((bool) data_get($agent->generation?->trigger_context, 'role_complete_council', false)
            && data_get($agent->modelVersion?->metadata, 'role_complete_council.protocol') === 'role_complete_council_v1'
            && data_get($agent->modelVersion?->metadata, 'role_complete_council.full_replay_required') === true) {
            return 'ROLE_COMPLETE_COHORT_PRIORITY';
        }

        return null;
    }

    private function agentIdFromPayload(string $payload): ?int
    {
        $decoded = json_decode($payload, true);
        $command = (string) data_get($decoded, 'data.command', '');
        if ($command === '') return null;

        return preg_match('/s:\\d+:"labAgentId";i:(\\d+)/', $command, $matches)
            ? (int) $matches[1]
            : null;
    }
}
