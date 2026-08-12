<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\LabQueueJobInspector;
use App\Services\OperatorApprovalService;
use RuntimeException;

class RecoverStaleLabBatches extends Command
{
    protected $signature = 'trading:recover-stale-lab-batches
        {--older-than=180 : Minimum age in minutes before a batch can be considered stale}
        {--limit=50 : Maximum number of stale batches to inspect}
        {--dry-run : Report candidates without cancelling them}
        {--apply : Cancel proven stale batches after queue drain and operator approval}
        {--approved-by=}
        {--approval-reason=}';

    protected $description = 'Safely quarantine unfinished lab batches that have no queued job and no active replay';

    public function handle(LabQueueJobInspector $queue, OperatorApprovalService $approvals): int
    {
        $olderThan = max(30, (int) $this->option('older-than'));
        $limit = min(200, max(1, (int) $this->option('limit')));
        $cutoff = now()->subMinutes($olderThan)->timestamp;
        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply || (bool) $this->option('dry-run');

        if ($apply) {
            $backlog = $queue->labQueueBacklog();
            if ($backlog['total'] > 0) {
                $this->warn(sprintf(
                    'Stale batch apply deferred: %d lab job(s) remain in %s.',
                    $backlog['total'],
                    implode(', ', array_keys($backlog['queues'])),
                ));

                return self::SUCCESS;
            }
            try {
                $approvals->requireForApply('recover-stale-lab-batches', $this->option('approved-by'), $this->option('approval-reason'), [
                    'older_than_minutes' => $olderThan,
                    'limit' => $limit,
                ]);
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        // A popped database job is temporarily absent from `jobs` while its
        // worker is executing. Never cancel stale metadata while the single
        // Python replay lane reports an active request or an unknown state.
        $lane = $this->replayLaneState();
        if ($lane['safe'] !== true) {
            $this->line('Stale batch recovery skipped: replay lane is '.($lane['reason'] ?? 'unknown').'.');
            return self::SUCCESS;
        }

        $batches = DB::table('job_batches')
            ->whereNull('finished_at')
            ->where('created_at', '<=', $cutoff)
            ->where(function ($query): void {
                $query->where('pending_jobs', '>', 0)->orWhere('failed_jobs', '>', 0);
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $cancelled = 0;
        $skipped = 0;
        foreach ($batches as $batch) {
            $hasQueuedJob = DB::table('jobs')
                ->where('payload', 'like', '%'.$batch->id.'%')
                ->exists();

            if ($hasQueuedJob) {
                $skipped++;
                continue;
            }

            $payload = [
                'batch_id' => $batch->id,
                'name' => $batch->name,
                'created_at' => $batch->created_at,
                'pending_jobs' => $batch->pending_jobs,
                'failed_jobs' => $batch->failed_jobs,
            ];

            if ($dryRun) {
                $this->line('Would cancel stale batch '.$batch->id.' ('.$batch->name.').');
                continue;
            }

            // BatchRepository.cancel() writes cancelled_at and finished_at;
            // the batch row remains available for audit and is never deleted.
            app('Illuminate'.chr(92).'Bus'.chr(92).'BatchRepository')->cancel($batch->id);
            Log::warning('LAB_STALE_BATCH_CANCELLED', $payload + [
                'older_than_minutes' => $olderThan,
                'reason' => 'unfinished_batch_without_job_or_active_replay',
            ]);
            $cancelled++;
        }

        $this->info(sprintf(
            'Stale lab batches: inspected=%d cancelled=%d skipped_with_job=%d.',
            $batches->count(),
            $cancelled,
            $skipped,
        ));

        return self::SUCCESS;
    }

    /** @return array{safe: bool, reason: string} */
    private function replayLaneState(): array
    {
        $url = rtrim((string) config('services.ai_service.url'), '/').'/api/replay-status';
        $token = (string) config('services.internal_api.token');

        if ($url === '/api/replay-status' || $token === '') {
            return ['safe' => false, 'reason' => 'configuration_unknown'];
        }

        try {
            $response = Http::connectTimeout(2)
                ->timeout(4)
                ->withHeaders(['X-Internal-Token' => $token])
                ->get($url);

            if ($response->failed()) {
                return ['safe' => false, 'reason' => 'health_unavailable'];
            }

            $body = $response->json();
            $protocol = (string) data_get($body, 'protocol', '');
            $active = (int) data_get($body, 'active_requests', -1);
            if ($protocol === '' || $active < 0) {
                return ['safe' => false, 'reason' => 'health_unknown'];
            }
            if ($active > 0) {
                return ['safe' => false, 'reason' => 'active_replay'];
            }

            return ['safe' => true, 'reason' => 'idle'];
        } catch (\Throwable $exception) {
            return ['safe' => false, 'reason' => 'health_exception'];
        }
    }
}
