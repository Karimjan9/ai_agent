<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\LabGeneration;
use App\Services\LabPopulationService;
use App\Services\LabQueueJobInspector;
use App\Services\ReplayLivenessProbeService;
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

    public function handle(
        LabQueueJobInspector $queue,
        OperatorApprovalService $approvals,
        ReplayLivenessProbeService $liveness,
    ): int
    {
        $olderThan = max(30, (int) $this->option('older-than'));
        $limit = min(200, max(1, (int) $this->option('limit')));
        $cutoff = now()->subMinutes($olderThan)->timestamp;
        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply || (bool) $this->option('dry-run');

        if ($apply) {
            $backlog = $queue->labQueueBacklog();
            if ($backlog['total'] === null || $backlog['total'] > 0) {
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
        $lane = $liveness->probe();
        if (($lane['status'] ?? 'unknown') !== 'ok') {
            $this->line('Stale batch recovery skipped: replay lane is '.($lane['reason'] ?? 'unknown').'.');
            return self::SUCCESS;
        }

        $queueSnapshot = $queue->queueSnapshot();
        if (($queueSnapshot['available'] ?? true) === false) {
            $this->line('Stale batch recovery skipped: queue state is unknown.');

            return self::SUCCESS;
        }
        $queuedPayloads = collect((array) ($queueSnapshot['rows'] ?? []))->pluck('payload')->filter()->values();
        // A stale Bus batch can be missing from `jobs` while its generation
        // still has non-terminal evaluation runs. Never cancel that batch:
        // it needs evidence recovery/retry, not lifecycle erasure. Orphan
        // learning batches remain eligible for the bounded recovery below.
        $activeGenerations = LabGeneration::query()
            ->with('laboratory')
            ->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)
            ->get();
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
            $hasQueuedJob = $queuedPayloads->contains(fn (string $payload): bool => str_contains($payload, (string) $batch->id));

            if ($hasQueuedJob) {
                $skipped++;
                continue;
            }

            // A continuation batch can be temporarily absent from Redis
            // while its worker is between queue admission and immutable run
            // creation. Resolve the generation from the durable batch name
            // before applying any stale cancellation. This is deliberately
            // independent of the scheduler process' cached status constants:
            // an open draft/queued/screening agent or run always owns the
            // batch, even if an older resident scheduler saw a stale status
            // projection during a reload.
            if ($this->batchBelongsToOpenEvidenceGeneration((string) $batch->name)) {
                $skipped++;
                $this->line(sprintf(
                    'Skip stale batch %s: durable generation still owns draft/queued/screening evidence.',
                    $batch->id,
                ));
                continue;
            }

            $activeGeneration = $activeGenerations->first(
                fn (LabGeneration $generation): bool => $this->batchBelongsToActiveGeneration((string) $batch->name, $generation),
            );
            if ($activeGeneration) {
                $skipped++;
                $this->line(sprintf(
                    'Skip stale batch %s: active generation G%s still owns incomplete evidence.',
                    $batch->id,
                    $activeGeneration->generation,
                ));
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

            // job_batches uses Unix integer lifecycle columns in this
            // project. Keep the recovery path explicit and type-safe instead
            // of delegating to a framework repository whose schema contract
            // can differ between Laravel versions. The row remains available
            // for audit and is never deleted.
            $cancelledAt = now()->timestamp;
            $updated = DB::table('job_batches')
                ->where('id', $batch->id)
                ->whereNull('cancelled_at')
                ->whereNull('finished_at')
                ->update([
                    'cancelled_at' => $cancelledAt,
                    'finished_at' => $cancelledAt,
                ]);
            if ($updated === 0) {
                $skipped++;
                continue;
            }
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

    private function batchBelongsToActiveGeneration(string $batchName, LabGeneration $generation): bool
    {
        $lab = $generation->laboratory;
        if (! $lab) return false;

        $needle = strtolower(sprintf(
            '%s %s lab g%s',
            (string) $lab->symbol,
            (string) $lab->timeframe,
            (int) $generation->generation,
        ));

        return str_contains(strtolower($batchName), $needle);
    }

    private function batchBelongsToOpenEvidenceGeneration(string $batchName): bool
    {
        if (preg_match('/^([^\s]+)\s+([^\s]+)\s+lab\s+g(\d+)/i', trim($batchName), $matches) !== 1) {
            return false;
        }

        $symbol = strtoupper((string) $matches[1]);
        $timeframe = strtoupper((string) $matches[2]);
        $generationNumber = (int) $matches[3];
        $generation = LabGeneration::query()
            ->where('generation', $generationNumber)
            ->whereHas('laboratory', fn ($query) => $query
                ->where('symbol', $symbol)
                ->where('timeframe', $timeframe))
            ->first();
        if (! $generation) return false;

        $openAgent = $generation->agents()
            ->whereIn('lifecycle_status', [
                'draft', 'queued', 'training', 'screening', 'full_queued', 'full_validation',
            ])
            ->exists();
        if ($openAgent) return true;

        return DB::table('lab_evaluation_runs')
            ->where('lab_generation_id', $generation->id)
            ->whereIn('status', ['queued', 'started', 'running', 'processing'])
            ->exists();
    }

}
