<?php

namespace App\Console\Commands;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Services\LabImmutableEvidenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PromoteLabFrontier extends Command
{
    protected $signature = 'trading:promote-lab-frontier
        {--agent= : Promote one exact agent id}
        {--generation= : Restrict promotion to one generation id}
        {--limit= : Maximum number of frontier jobs; defaults to the configured frontier backlog limit}';

    protected $description = 'Promote incomplete frontier recovery jobs ahead of ordinary screening FIFO';

    public function handle(LabImmutableEvidenceService $evidence): int
    {
        if (! DB::getSchemaBuilder()->hasTable('jobs')) {
            $this->info('Queue table is not available; frontier promotion skipped.');

            return self::SUCCESS;
        }

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

        $promoted = 0;
        foreach ($jobs as $job) {
            if ($promoted >= $capacity) break;

            $agentId = $this->agentIdFromPayload((string) $job->payload);
            if ($agentId === null) continue;
            if ($this->option('agent') !== null && (int) $this->option('agent') !== $agentId) continue;

            $agent = LabAgent::query()->with(['generation', 'modelVersion'])->find($agentId);
            if (! $agent || ! $this->isFrontierRecovery($agent)) continue;
            if ($this->option('generation') !== null && (int) $this->option('generation') !== (int) $agent->lab_generation_id) continue;

            $changed = DB::table('jobs')
                ->where('id', $job->id)
                ->where('queue', $screeningQueue)
                ->whereNull('reserved_at')
                ->update(['queue' => $frontierQueue]);
            if ($changed !== 1) continue;

            $promoted++;
            $evidence->recordLifecycle($agent, 'frontier_queue_promoted', [
                'reason_code' => 'INCOMPLETE_SCREENING_EVIDENCE_RECOVERY',
                'queue_from' => $screeningQueue,
                'queue_to' => $frontierQueue,
                'job_id' => (int) $job->id,
                'attempts_at_promotion' => (int) $job->attempts,
                'rule' => 'A missing request/response/trace/ledger boundary is prioritized without deleting or duplicating the immutable recovery job.',
                'promotion_evidence' => false,
            ], 'screening', null, null, self::class);

            $this->info("Agent {$agent->id}: job {$job->id} promoted to {$frontierQueue}.");
        }

        if ($promoted === 0) {
            $this->info('No eligible incomplete frontier recovery job was found.');
        }

        return self::SUCCESS;
    }

    private function isFrontierRecovery(LabAgent $agent): bool
    {
        if ($agent->lifecycle_status !== 'queued' || $agent->generation?->status !== 'screening') {
            return false;
        }

        $run = LabEvaluationRun::query()
            ->where('lab_agent_id', $agent->id)
            ->where('phase', 'screening')
            ->latest('id')
            ->first();

        // A retry release with no response artifact is the precise recovery
        // boundary. Do not pull ordinary fresh screens ahead of the queue.
        return $run !== null
            && $run->status === 'retry_released'
            && blank($run->response_hash);
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
