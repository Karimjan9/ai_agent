<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\LabEvaluationRun;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabQueueStateService;
use App\Services\OperatorApprovalService;
use RuntimeException;

/**
 * Removes only an orphaned evaluator overlap lock.
 *
 * A worker can be terminated after acquiring the lock but before Laravel's
 * middleware releases it. We must never clear it while an unproven reserved
 * job may still be inside the single Python evaluator. A stale reservation
 * with an open immutable run is the owner proof; recent contenders can remain
 * reserved while that proven stale owner is requeued.
 */
class RecoverLabReplayMutex extends Command
{
    protected $signature = 'trading:recover-lab-replay-mutex
        {--force-stale : Requeue only reservations proven stale after a worker restart; never deletes the job}
        {--stale-after=120 : Minimum reservation age in seconds for the explicit stale recovery}
        {--dry-run : Report the proven stale owner without requeueing or deleting a lock}
        {--apply : Requeue/remove only after explicit operator approval}
        {--approved-by=}
        {--approval-reason=}';

    protected $description = 'Recover an orphaned single-lane lab replay mutex safely';

    public function handle(OperatorApprovalService $approvals, LabQueueStateService $queueState): int
    {
        if ((string) config('queue.default', 'database') === 'redis') {
            return $this->handleRedis($approvals, $queueState);
        }

        // EvaluateLabAgentJob uses WithoutOverlapping::shared(), so Laravel
        // stores the cross-job mutex without the job-class hash. Derive the
        // store prefix instead of freezing an environment-specific prefix.
        $key = Cache::getStore()->getPrefix()
            .'laravel-queue-overlap:'
            .(string) config('services.lab_queue.replay_mutex_key', 'neurotrader-ai-heavy-replay');
        $lock = DB::table('cache_locks')->where('key', $key)->first();

        // A worker can die after Laravel has reserved the queue row but before
        // WithoutOverlapping persists (or after it has already released) the
        // cache lock. Explicit stale recovery must therefore inspect the
        // reservation even when the lock row is absent. The normal operator
        // path remains a no-op when there is no mutex to recover.
        $forceStale = (bool) $this->option('force-stale');
        $apply = (bool) $this->option('apply') && ! (bool) $this->option('dry-run');
        // Recovery is fail-closed by default. --force-stale without --apply
        // remains a report-only probe, even when --dry-run is omitted.
        $dryRun = ! $apply;
        if (! $lock && ! $forceStale) {
            return self::SUCCESS;
        }

        $reservedJobs = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->whereIn('queue', array_values(array_unique(array_merge(
                [(string) config('services.lab_queue.screening_queue', 'lab-screening')],
                [(string) config('services.lab_queue.frontier_queue', 'lab-frontier')],
                (array) config('services.lab_queue.legacy_screening_queues', []),
                ['lab-full-validation'],
            ))))
            ->get(['id', 'queue', 'reserved_at', 'attempts', 'payload']);

        // A worker restart can leave both the queue reservation and the
        // overlap lock behind.  The normal path remains conservative.  The
        // explicit flag is intended for an operator who has already verified
        // that the old worker is gone and the AI service has no active replay;
        // it requeues the exact jobs and never deletes evidence.
        if ($forceStale) {
            $requestedStaleAfter = max(120, (int) $this->option('stale-after'));
            // A full replay can finish its Python request before Laravel has
            // persisted the immutable ledger, gate decisions, and lifecycle
            // projection. The evaluator may therefore report zero active
            // requests while the reserved full job is still legitimately
            // completing. Derive the recovery floor from the same transport
            // budget plus an explicit post-processing grace period; a fixed
            // short threshold can close valid evidence as retry_released.
            $hasFullReplay = $reservedJobs->contains(
                fn ($job): bool => $job->queue === 'lab-full-validation'
            );
            $fullReplayTimeout = max(60, (int) config('services.lab_selection.full_replay_timeout_seconds', 3900));
            $postProcessingGrace = max(300, (int) config('services.lab_selection.full_replay_post_processing_grace_seconds', 900));
            $screenReplayTimeout = max(60, (int) config('services.lab_selection.screen_timeout_seconds', 900));
            $screenPostProcessingGrace = max(300, (int) config('services.lab_selection.screen_replay_post_processing_grace_seconds', 300));
            $minimumStaleAfter = $hasFullReplay
                ? $fullReplayTimeout + $postProcessingGrace
                : $screenReplayTimeout + $screenPostProcessingGrace;
            $staleAfter = max($minimumStaleAfter, $requestedStaleAfter);
            $cutoff = now()->timestamp - $staleAfter;
            // Age alone cannot distinguish a long legitimate replay from a
            // contender that has been released repeatedly. Ask the AI lane
            // itself; force recovery is allowed only when the evaluator
            // explicitly reports zero active replay requests.
            try {
                $status = Http::connectTimeout(2)->timeout(5)->acceptJson()
                    ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                    ->get(rtrim((string) config('services.ai_service.url'), '/').'/api/replay-status');
            } catch (\Throwable) {
                $this->error('Refusing stale recovery: evaluator liveness probe is unreachable.');
                return self::FAILURE;
            }
            if (! $status->successful() || (int) $status->json('active_requests', -1) !== 0) {
                $this->error('Refusing stale recovery: evaluator reports an active or unknown replay.');
                return self::FAILURE;
            }

            // A worker can finish a recovery replay and lose the queue row
            // before the old middleware attempt is closed. If a newer
            // terminal run for the same agent exists, the old open row is
            // provably superseded and must not remain an apparent active
            // replay or become a second evidence boundary. Close only this
            // exact, idempotent case; an orphan without a newer terminal run
            // remains fail-closed for operator investigation.
            $supersededOpenRuns = LabEvaluationRun::query()
                ->where('status', 'started')
                ->get()
                ->filter(function (LabEvaluationRun $run): bool {
                    return LabEvaluationRun::query()
                        ->where('lab_agent_id', $run->lab_agent_id)
                        ->where('id', '>', $run->id)
                        ->whereIn('status', ['completed', 'technical_error', 'retry_released', 'skipped', 'legacy_snapshot'])
                        ->exists();
                })
                ->values();

            if ($supersededOpenRuns->isNotEmpty()) {
                if ($dryRun) {
                    $this->line('Would close '.$supersededOpenRuns->count().' superseded open evaluator run(s); newer terminal evidence is preserved.');
                } elseif (! $this->approve($approvals, [
                    'mode' => 'superseded_open_runs',
                    'run_ids' => $supersededOpenRuns->pluck('id')->values()->all(),
                    'agent_ids' => $supersededOpenRuns->pluck('lab_agent_id')->unique()->values()->all(),
                ])) {
                    return self::FAILURE;
                } else {
                    $evidence = app(LabImmutableEvidenceService::class);
                    $supersededOpenRuns->each(function (LabEvaluationRun $run) use ($evidence): void {
                        $evidence->finishIfOpen(
                            $run,
                            'retry_released',
                            null,
                            [],
                            [
                                'reason_code' => 'SUPERSEDED_BY_NEWER_TERMINAL_REPLAY',
                                'recovery_protocol' => 'orphaned_replay_mutex_v1',
                                'promotion_evidence' => false,
                            ],
                        );
                    });
                    $this->warn('Closed '.$supersededOpenRuns->count().' superseded open evaluator run(s); no evidence was deleted.');
                }
            }

            if ($reservedJobs->isEmpty()) {
                // A worker can die after the queue reservation expires (or
                // after a recovery command requeues it) while the shared
                // cache lock remains. In that state there is no job row left
                // to prove ownership, so leaving the lock in place creates a
                // release storm for every newly queued screen. The AI lane
                // probe above is the final safety proof: only an idle
                // evaluator with no reserved lab replay may lose this lock.
                if ($lock) {
                    if (! $status->successful() || (int) $status->json('active_requests', -1) !== 0) {
                        $this->error('Refusing orphan-lock recovery: evaluator reports an active or unknown replay.');

                        return self::FAILURE;
                    }

                    if ($dryRun) {
                        $this->line('Would remove orphaned evaluator replay mutex; no reserved evaluator job is present.');
                    } else {
                        if (! $this->approve($approvals, ['mode' => 'orphan_lock', 'lock_key' => $key])) {
                            return self::FAILURE;
                        }
                        $deleted = DB::table('cache_locks')->where('key', $key)->delete();
                        if ($deleted > 0) {
                            $this->warn('Removed orphaned evaluator replay mutex; no reserved evaluator job was present.');
                        }
                    }
                }
                $this->line('No reserved evaluator job is present; nothing to recover.');
                return self::SUCCESS;
            }

            $stale = $reservedJobs->filter(fn ($job): bool =>
                (int) $job->reserved_at <= $cutoff || (int) $job->attempts >= 10
            );
            if ($stale->isEmpty()) {
                $this->error('Refusing stale recovery: no reservation has crossed the contention threshold.');
                return self::FAILURE;
            }

            // A recent contender can coexist with an orphaned owner. Only
            // requeue stale rows whose agent still has an open immutable run;
            // that run is the durable proof that the worker entered the job.
            // This avoids the old all-or-nothing guard, which let a release
            // storm keep an orphan lock alive indefinitely.
            $staleAgentIds = $stale->map(fn ($job): ?int => $this->labAgentIdFromPayload((string) $job->payload))
                ->filter()
                ->unique()
                ->values();
            $openRunAgentIds = LabEvaluationRun::query()
                ->whereIn('lab_agent_id', $staleAgentIds->all())
                ->where('status', 'started')
                ->pluck('lab_agent_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();
            $staleOwners = $stale->filter(function ($job) use ($openRunAgentIds): bool {
                $agentId = $this->labAgentIdFromPayload((string) $job->payload);

                return $agentId !== null && $openRunAgentIds->contains($agentId);
            });

            // Preserve the legacy serialized-job recovery path when every
            // reservation is stale but its payload predates the immutable
            // run contract and therefore cannot prove an agent id.
            if ($staleOwners->isEmpty() && $stale->count() === $reservedJobs->count()) {
                $staleOwners = $stale;
            }
            if ($staleOwners->isEmpty()) {
                $this->error('Refusing stale recovery: stale reservation has no open evaluator run owner.');
                return self::FAILURE;
            }
            $staleAgentIds = $staleOwners->map(fn ($job): ?int => $this->labAgentIdFromPayload((string) $job->payload))
                ->filter()
                ->unique()
                ->values();

            if ($dryRun) {
                $this->line('Would requeue '.$staleOwners->count().' stale evaluator reservation(s); recent contenders remain untouched.');

                return self::SUCCESS;
            }

            if (! $this->approve($approvals, [
                'mode' => 'stale_reservation',
                'reservation_ids' => $staleOwners->pluck('id')->values()->all(),
                'stale_after_seconds' => $staleAfter,
            ])) {
                return self::FAILURE;
            }

            DB::transaction(function () use ($staleOwners, $key): void {
                DB::table('jobs')->whereIn('id', $staleOwners->pluck('id')->all())->update([
                    'reserved_at' => null,
                    'available_at' => now()->timestamp,
                ]);
                DB::table('cache_locks')->where('key', $key)->delete();
            });

            // The evaluator is proven idle and the worker reservation is
            // proven stale, so any open run for these exact agents belongs to
            // the interrupted attempt. Close it as operational retry evidence
            // before the requeued job can open a fresh attempt.
            $evidence = app(LabImmutableEvidenceService::class);
            LabEvaluationRun::query()
                ->whereIn('lab_agent_id', $staleAgentIds->all())
                ->where('status', 'started')
                ->get()
                ->each(function (LabEvaluationRun $run) use ($evidence): void {
                    $evidence->finishIfOpen(
                        $run,
                        'retry_released',
                        null,
                        [],
                        [
                            'reason_code' => 'STALE_QUEUE_RESERVATION_RECOVERED',
                            'recovery_protocol' => 'orphaned_replay_mutex_v1',
                            'promotion_evidence' => false,
                        ],
                    );
                });
            $untouched = $reservedJobs->count() - $staleOwners->count();
            $this->warn('Requeued '. $staleOwners->count().' stale evaluator reservation(s)'.($untouched > 0 ? "; left {$untouched} recent contender(s) untouched" : '').'; no job or evidence was deleted.');
            return self::SUCCESS;
        }

        $activeReplay = $reservedJobs->isNotEmpty();

        if ($activeReplay) {
            $this->line('Evaluator mutex is held by a reserved replay; leaving it untouched.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line('Would remove orphaned evaluator replay mutex; no reserved lab replay was present.');
        } elseif ($this->approve($approvals, ['mode' => 'orphan_lock', 'lock_key' => $key])) {
            $deleted = DB::table('cache_locks')->where('key', $key)->delete();
            if ($deleted > 0) {
                $this->warn('Removed orphaned evaluator replay mutex; no reserved lab replay was present.');
            }
        } else {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function handleRedis(OperatorApprovalService $approvals, LabQueueStateService $queueState): int
    {
        $queues = array_values(array_unique(array_merge(
            [(string) config('services.lab_queue.screening_queue', 'lab-screening')],
            [(string) config('services.lab_queue.frontier_queue', 'lab-frontier')],
            (array) config('services.lab_queue.legacy_screening_queues', []),
            [(string) config('services.lab_queue.full_validation_queue', 'lab-full-validation')],
        )));
        $snapshot = $queueState->snapshot($queues);
        if (($snapshot['available'] ?? false) !== true) {
            $this->error('Refusing Redis recovery: queue state is unavailable.');

            return self::FAILURE;
        }

        $forceStale = (bool) $this->option('force-stale');
        $apply = (bool) $this->option('apply') && ! (bool) $this->option('dry-run');
        $dryRun = ! $apply;
        $reserved = collect((array) ($snapshot['rows'] ?? []))
            ->filter(fn (array $row): bool => ($row['redis_state'] ?? null) === 'reserved')
            ->values();
        if (! $forceStale) {
            if ($reserved->isNotEmpty()) $this->line('Redis has '.$reserved->count().' reserved lab replay(s); leaving them untouched.');

            return self::SUCCESS;
        }

        try {
            $status = Http::connectTimeout(2)->timeout(5)->acceptJson()
                ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                ->get(rtrim((string) config('services.ai_service.url'), '/').'/api/replay-status');
        } catch (\Throwable) {
            $this->error('Refusing Redis stale recovery: evaluator liveness probe is unreachable.');

            return self::FAILURE;
        }
        if (! $status->successful() || (int) $status->json('active_requests', -1) !== 0) {
            $this->error('Refusing Redis stale recovery: evaluator reports an active or unknown replay.');

            return self::FAILURE;
        }

        $hasFull = $reserved->contains(fn (array $row): bool => ($row['queue'] ?? null) === config('services.lab_queue.full_validation_queue', 'lab-full-validation'));
        $timeout = $hasFull
            ? max(60, (int) config('services.lab_selection.full_replay_timeout_seconds', 3900)) + max(300, (int) config('services.lab_selection.full_replay_post_processing_grace_seconds', 900))
            : max(60, (int) config('services.lab_selection.screen_timeout_seconds', 900)) + max(300, (int) config('services.lab_selection.screen_replay_post_processing_grace_seconds', 300));
        $staleAfter = max($timeout, (int) $this->option('stale-after'));
        $cutoff = now()->timestamp - $staleAfter;
        $stale = $reserved->filter(fn (array $row): bool => ((int) ($row['reserved_at'] ?? 0) <= $cutoff) || (int) ($row['attempts'] ?? 0) >= 10)->values();
        if ($stale->isEmpty()) {
            $this->error('Refusing Redis stale recovery: no reservation crossed the contention threshold.');

            return self::FAILURE;
        }

        $agentIds = $stale->map(fn (array $row): ?int => $this->labAgentIdFromPayload((string) ($row['payload'] ?? '')))->filter()->unique()->values();
        $openRunAgentIds = LabEvaluationRun::query()->whereIn('lab_agent_id', $agentIds->all())->where('status', 'started')->pluck('lab_agent_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $staleOwners = $stale->filter(function (array $row) use ($openRunAgentIds): bool {
            $agentId = $this->labAgentIdFromPayload((string) ($row['payload'] ?? ''));

            return $agentId !== null && $openRunAgentIds->contains($agentId);
        })->values();
        if ($staleOwners->isEmpty() && $stale->count() === $reserved->count()) $staleOwners = $stale;
        if ($staleOwners->isEmpty()) {
            $this->error('Refusing Redis stale recovery: no stale reservation has an open evaluator run owner.');

            return self::FAILURE;
        }
        if ($dryRun) {
            $this->table(['queue', 'job', 'reserved_at', 'attempts', 'action'], $staleOwners->map(fn (array $row): array => [$row['queue'], $row['id'], $row['reserved_at'], $row['attempts'], 'would_release_to_pending'])->all());

            return self::SUCCESS;
        }
        if (! $this->approve($approvals, [
            'mode' => 'redis_stale_reservation',
            'reservation_ids' => $staleOwners->pluck('id')->values()->all(),
            'stale_after_seconds' => $staleAfter,
            'backend' => 'redis',
        ])) return self::FAILURE;

        $released = 0;
        foreach ($staleOwners as $row) {
            if ($queueState->releaseReservedPayload((string) $row['queue'], (string) $row['payload'])) $released++;
        }
        $staleAgentIds = $staleOwners->map(fn (array $row): ?int => $this->labAgentIdFromPayload((string) ($row['payload'] ?? '')))->filter()->unique()->values();
        $evidence = app(LabImmutableEvidenceService::class);
        LabEvaluationRun::query()->whereIn('lab_agent_id', $staleAgentIds->all())->where('status', 'started')->get()->each(function (LabEvaluationRun $run) use ($evidence): void {
            $evidence->finishIfOpen($run, 'retry_released', null, [], [
                'reason_code' => 'STALE_REDIS_QUEUE_RESERVATION_RECOVERED',
                'recovery_protocol' => 'orphaned_replay_mutex_redis_v1',
                'promotion_evidence' => false,
            ]);
        });

        // The lock is only force-released after the evaluator is idle and the
        // exact stale reservation was moved atomically. A recent reserved
        // contender keeps its lock until its own middleware exits.
        if ($reserved->count() === $released) {
            Cache::lock('laravel-queue-overlap:'.(string) config('services.lab_queue.replay_mutex_key', 'neurotrader-ai-heavy-replay'))->forceRelease();
        }
        $this->warn("Released {$released} stale Redis reservation(s); no job or evidence was deleted.");

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $scope */
    private function approve(OperatorApprovalService $approvals, array $scope): bool
    {
        try {
            $approvals->requireForApply('recover-lab-replay-mutex', $this->option('approved-by'), $this->option('approval-reason'), $scope);

            return true;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return false;
        }
    }

    private function labAgentIdFromPayload(string $payload): ?int
    {
        // Queue payloads are JSON-wrapped PHP-serialized commands, so the
        // inner property quotes may be escaped as `\"`.  The old exact
        // serialized-fragment regex missed those payloads and requeued the
        // job without closing its open immutable run.  Anchor on the unique
        // property name while accepting either representation.
        if (preg_match('/labAgentId[^0-9]{1,24}(\d+)/', $payload, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
