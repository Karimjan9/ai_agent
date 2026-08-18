<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\LabEvaluationRun;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabQueueStateService;
use App\Services\OperatorApprovalService;
use App\Services\ReplayLivenessProbeService;
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

    public function handle(OperatorApprovalService $approvals, LabQueueStateService $queueState, ReplayLivenessProbeService $liveness): int
    {
        if ((string) config('queue.default', 'database') === 'redis') {
            return $this->handleRedis($approvals, $queueState, $liveness);
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
            $status = $liveness->probe();
            if (($status['status'] ?? 'unknown') !== 'ok') {
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
                    if (($status['status'] ?? 'unknown') !== 'ok') {
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

    private function handleRedis(OperatorApprovalService $approvals, LabQueueStateService $queueState, ReplayLivenessProbeService $liveness): int
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

        $status = $liveness->probe();
        if (($status['status'] ?? 'unknown') !== 'ok') {
            $this->error('Refusing Redis stale recovery: evaluator reports an active or unknown replay.');

            return self::FAILURE;
        }

        $hasFull = $reserved->contains(fn (array $row): bool => ($row['queue'] ?? null) === config('services.lab_queue.full_validation_queue', 'lab-full-validation'));
        $timeout = $hasFull
            ? max(60, (int) config('services.lab_selection.full_replay_timeout_seconds', 3900)) + max(300, (int) config('services.lab_selection.full_replay_post_processing_grace_seconds', 900))
            : max(60, (int) config('services.lab_selection.screen_timeout_seconds', 900)) + max(300, (int) config('services.lab_selection.screen_replay_post_processing_grace_seconds', 300));
        $staleAfter = max($timeout, (int) $this->option('stale-after'));
        $nowTimestamp = now()->timestamp;

        // RedisQueue stores the reserved sorted-set score as the visibility
        // expiry (reserved_at + retry_after), not as the moment the worker
        // reserved the job. Comparing that score with `now - staleAfter`
        // makes an already-expired long replay look recent and strands it
        // forever. First identify expired reservations; the open immutable
        // run age check below still requires the full replay budget plus
        // post-processing grace before anything can be released.
        $stale = $reserved->filter(fn (array $row): bool =>
            (((int) ($row['reserved_at'] ?? 0)) > 0 && (int) $row['reserved_at'] <= $nowTimestamp)
            || (int) ($row['attempts'] ?? 0) >= 10
        )->values();
        // An unexpired reservation may still be recoverable when its exact
        // recorded PHP worker has died. Include all reservations in the
        // owner lookup for that restart-proof path; expiry-based recovery
        // remains restricted to the $stale subset below.
        // Use every reservation for owner identity lookup.  A stale screen
        // row and an unexpired terminal full row may coexist; limiting the
        // lookup to the former would hide the safe terminal cleanup path.
        $candidateReservations = $reserved;
        $agentIds = $candidateReservations
            ->flatMap(fn (array $row): array => $this->labAgentIdsFromPayload((string) ($row['payload'] ?? '')))
            ->filter(fn ($id): bool => (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()->values();
        $openRuns = LabEvaluationRun::query()
            ->whereIn('lab_agent_id', $agentIds->all())
            ->where('status', 'started')
            ->get(['lab_agent_id', 'started_at', 'worker_pid']);
        $openRunAgentIds = $openRuns->pluck('lab_agent_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $staleOwners = $stale->filter(function (array $row) use ($openRunAgentIds): bool {
            return collect($this->labAgentIdsFromPayload((string) ($row['payload'] ?? '')))
                ->contains(fn (int $agentId): bool => $openRunAgentIds->contains($agentId));
        })->values();

        // An expired Redis reservation alone is not enough: a healthy full
        // replay may exceed retry_after while Laravel is still persisting
        // its immutable evidence. Require the exact owner's open run to be
        // older than the same timeout/grace budget before release.
        $staleOwners = $staleOwners->filter(function (array $row) use ($openRuns, $staleAfter, $nowTimestamp): bool {
            return $openRuns
                ->whereIn('lab_agent_id', $this->labAgentIdsFromPayload((string) ($row['payload'] ?? '')))
                ->contains(function (object $run) use ($staleAfter, $nowTimestamp): bool {
                    $startedAt = $run->started_at ? strtotime((string) $run->started_at) : false;

                    return $startedAt !== false && $startedAt <= ($nowTimestamp - $staleAfter);
                });
        })->values();

        // A PM2/queue-worker restart can strand a still-reserved Redis job
        // before Redis' visibility score expires. In that case the normal
        // expiry test above cannot see the orphan for another retry_after
        // window. The immutable run records the exact PHP worker PID; when
        // that PID is gone and the AI evaluator is already idle, the job has
        // no live owner and may be safely requeued after the short operator
        // grace period. This is deliberately narrower than an age-only
        // override: it requires an identified agent, an open run, a dead
        // recorded worker, and an idle evaluator.
        $workerRestartGrace = max(120, (int) $this->option('stale-after'));
        $orphanedOwners = $reserved->filter(function (array $row) use ($openRuns, $workerRestartGrace, $nowTimestamp): bool {
            return $openRuns
                ->whereIn('lab_agent_id', $this->labAgentIdsFromPayload((string) ($row['payload'] ?? '')))
                ->contains(function (object $run) use ($workerRestartGrace, $nowTimestamp): bool {
                    $startedAt = $run->started_at ? strtotime((string) $run->started_at) : false;
                    $workerPid = (int) ($run->worker_pid ?? 0);

                    return $startedAt !== false
                        && $startedAt <= ($nowTimestamp - $workerRestartGrace)
                        && $workerPid > 0
                        && ! $this->workerProcessExists($workerPid);
                });
        })->values();
        if ($orphanedOwners->isNotEmpty()) {
            $staleOwners = $staleOwners
                ->concat($orphanedOwners)
                ->unique(fn (array $row): string => (string) ($row['id'] ?? ''))
                ->values();
        }

        // A worker can die after it has persisted a terminal immutable run
        // but before Redis acknowledges the reserved queue payload. Releasing
        // that payload back to pending would replay a completed candidate and
        // create duplicate evidence. Remove it only when every payload agent
        // has a terminal latest run, the recorded owner PID is dead, and the
        // short restart grace has elapsed.
        $terminalStatuses = ['completed', 'technical_error', 'retry_released', 'skipped', 'legacy_snapshot'];
        $latestRuns = LabEvaluationRun::query()
            ->whereIn('lab_agent_id', $agentIds->all())
            ->orderByDesc('id')
            ->get(['lab_agent_id', 'status', 'finished_at', 'worker_pid'])
            ->groupBy('lab_agent_id')
            ->map(fn ($runs): ?LabEvaluationRun => $runs->first());
        $terminalOrphans = $reserved->filter(function (array $row) use ($latestRuns, $terminalStatuses, $workerRestartGrace, $nowTimestamp): bool {
            $ids = $this->labAgentIdsFromPayload((string) ($row['payload'] ?? ''));
            if ($ids === []) return false;

            return collect($ids)->every(function (int $agentId) use ($latestRuns, $terminalStatuses, $workerRestartGrace, $nowTimestamp): bool {
                $run = $latestRuns->get($agentId);
                $finishedAt = $run?->finished_at ? strtotime((string) $run->finished_at) : false;

                return $run !== null
                    && in_array((string) $run->status, $terminalStatuses, true)
                    && $finishedAt !== false
                    && $finishedAt <= ($nowTimestamp - $workerRestartGrace)
                    && (int) ($run->worker_pid ?? 0) > 0
                    && ! $this->workerProcessExists((int) $run->worker_pid);
            });
        })->values();
        $recoverable = $staleOwners
            ->concat($terminalOrphans)
            ->unique(fn (array $row): string => (string) ($row['id'] ?? ''))
            ->values();
        // Keep the legacy fallback only for payloads that cannot identify an
        // agent at all. A payload with an agent id must pass the open-run age
        // proof above; otherwise a currently running replay could be released
        // merely because its Redis visibility window expired.
        $allPayloadsWithoutAgentId = $stale->every(fn (array $row): bool =>
            $this->labAgentIdsFromPayload((string) ($row['payload'] ?? '')) === []
        );
        if ($staleOwners->isEmpty() && $stale->count() === $reserved->count() && $allPayloadsWithoutAgentId) {
            $staleOwners = $stale;
        }
        if ($recoverable->isEmpty()) {
            $this->error($stale->isEmpty()
                ? 'Refusing Redis stale recovery: no expired reservation or dead-worker owner was proven.'
                : 'Refusing Redis stale recovery: no stale reservation has an open or terminal owner proof.');

            return self::FAILURE;
        }
        if ($dryRun) {
            $this->table(['queue', 'job', 'reserved_at', 'attempts', 'action'], $recoverable->map(function (array $row) use ($terminalOrphans): array {
                $terminal = $terminalOrphans->pluck('id')->contains($row['id']);

                return [$row['queue'], $row['id'], $row['reserved_at'], $row['attempts'], $terminal ? 'would_remove_terminal_reservation' : 'would_release_to_pending'];
            })->all());

            return self::SUCCESS;
        }
        if (! $this->approve($approvals, [
            'mode' => 'redis_stale_reservation',
            'reservation_ids' => $recoverable->pluck('id')->values()->all(),
            'stale_after_seconds' => $staleAfter,
            'backend' => 'redis',
        ])) return self::FAILURE;

        $released = 0;
        foreach ($staleOwners as $row) {
            if ($queueState->releaseReservedPayload((string) $row['queue'], (string) $row['payload'])) $released++;
        }
        $removedTerminal = 0;
        foreach ($terminalOrphans as $row) {
            if ($queueState->removeCompletedReservedPayload((string) $row['queue'], (string) $row['payload'])) $removedTerminal++;
        }
        $staleAgentIds = $staleOwners
            ->flatMap(fn (array $row): array => $this->labAgentIdsFromPayload((string) ($row['payload'] ?? '')))
            ->filter(fn ($id): bool => (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()->values();
        $evidence = app(LabImmutableEvidenceService::class);
        LabEvaluationRun::query()->whereIn('lab_agent_id', $staleAgentIds->all())->where('status', 'started')->get()->each(function (LabEvaluationRun $run) use ($evidence): void {
            $evidence->finishIfOpen($run, 'retry_released', null, [], [
                'reason_code' => 'STALE_REDIS_QUEUE_RESERVATION_RECOVERED',
                'recovery_protocol' => 'orphaned_replay_mutex_redis_v1',
                'promotion_evidence' => false,
            ]);
        });

        // Screening batches have slot-scoped overlap locks, not the heavy
        // full-replay mutex. If a dead worker owned the stale batch, release
        // only that slot when no other reserved job still claims it; a
        // recent contender in the same slot must keep its lock untouched.
        $remainingReserved = $reserved->reject(fn (array $row): bool =>
            $staleOwners->pluck('id')->contains($row['id'])
        )->values();
        foreach ($staleOwners->where('queue', (string) config('services.lab_queue.screening_queue', 'lab-screening')) as $row) {
            $slot = $this->screeningSlotFromPayload((string) ($row['payload'] ?? ''));
            if ($slot === null) continue;
            $slotClaimed = $remainingReserved->contains(function (array $other) use ($slot): bool {
                return (string) ($other['queue'] ?? '') === (string) config('services.lab_queue.screening_queue', 'lab-screening')
                    && $this->screeningSlotFromPayload((string) ($other['payload'] ?? '')) === $slot;
            });
            if (! $slotClaimed) {
                Cache::lock(
                    'laravel-queue-overlap:'.(string) config('services.lab_queue.screening_mutex_key', 'neurotrader-ai-screening-replay').":slot{$slot}"
                )->forceRelease();
            }
        }

        // The lock is only force-released after the evaluator is idle and the
        // exact stale reservation was moved atomically. A recent reserved
        // contender keeps its lock until its own middleware exits.
        if ($reserved->count() === ($released + $removedTerminal)) {
            Cache::lock('laravel-queue-overlap:'.(string) config('services.lab_queue.replay_mutex_key', 'neurotrader-ai-heavy-replay'))->forceRelease();
        }
        $this->warn("Released {$released} stale Redis reservation(s) and removed {$removedTerminal} terminal orphan reservation(s); no evidence was deleted.");

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
        return $this->labAgentIdsFromPayload($payload)[0] ?? null;
    }

    /**
     * Extract both single-agent and bounded-batch payload identities.  A
     * screening batch may already have terminal evidence for some members;
     * callers therefore use this list only to prove/release the exact open
     * member runs, never to manufacture a second strategy verdict.
     *
     * @return array<int, int>
     */
    private function labAgentIdsFromPayload(string $payload): array
    {
        $ids = [];
        if (preg_match('/labAgentIds.*?a:\d+:\{(.*?)\}/s', $payload, $batch) === 1) {
            preg_match_all('/i:\d+;i:(\d+)/', (string) ($batch[1] ?? ''), $matches);
            $ids = array_map('intval', (array) ($matches[1] ?? []));
        }
        if ($ids === [] && preg_match('/labAgentId(?!s)[^0-9]{1,24}(\d+)/', $payload, $single) === 1) {
            $ids = [(int) $single[1]];
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    private function screeningSlotFromPayload(string $payload): ?int
    {
        if (preg_match('/screeningSlot[^0-9]{1,24}(\d+)/', $payload, $matches) !== 1) {
            return null;
        }

        return abs((int) $matches[1]) % 2;
    }

    private function workerProcessExists(int $pid): bool
    {
        if ($pid <= 0) return false;

        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            $exitCode = 1;
            @exec('tasklist /FI "PID eq '.$pid.'" /FO CSV /NH', $output, $exitCode);

            return $exitCode === 0
                && preg_match('/"'.preg_quote((string) $pid, '/').'"/', implode("\n", $output)) === 1;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return is_dir('/proc/'.$pid);
    }
}
