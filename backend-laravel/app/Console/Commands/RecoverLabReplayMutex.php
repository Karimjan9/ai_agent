<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\LabEvaluationRun;
use App\Services\LabImmutableEvidenceService;

/**
 * Removes only an orphaned evaluator overlap lock.
 *
 * A worker can be terminated after acquiring the lock but before Laravel's
 * middleware releases it.  We must never clear it while a queue job is
 * actually reserved, because that would allow two calls into the single
 * Python evaluator.  The database queue reservation is the conservative
 * liveness signal used here.
 */
class RecoverLabReplayMutex extends Command
{
    protected $signature = 'trading:recover-lab-replay-mutex
        {--force-stale : Requeue only reservations proven stale after a worker restart; never deletes the job}
        {--stale-after=120 : Minimum reservation age in seconds for the explicit stale recovery}';

    protected $description = 'Recover an orphaned single-lane lab replay mutex safely';

    public function handle(): int
    {
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
        if (! $lock && ! $forceStale) {
            return self::SUCCESS;
        }

        $reservedJobs = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->whereIn('queue', [
                'lab-xauusd',
                'lab-eurusd',
                'lab-gbpusd',
                'lab-full-validation',
            ])
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
            // projection.  The evaluator may therefore report zero active
            // requests while the reserved full job is still legitimately
            // completing. Never interrupt that post-processing window with
            // the short screening recovery threshold.
            $hasFullReplay = $reservedJobs->contains(
                fn ($job): bool => $job->queue === 'lab-full-validation'
            );
            $minimumStaleAfter = $hasFullReplay ? 900 : 120;
            $staleAfter = max($minimumStaleAfter, $requestedStaleAfter);
            $cutoff = now()->timestamp - $staleAfter;
            if ($reservedJobs->isEmpty()) {
                $this->line('No reserved evaluator job is present; nothing to recover.');
                return self::SUCCESS;
            }

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

            $stale = $reservedJobs->filter(fn ($job): bool =>
                (int) $job->reserved_at <= $cutoff || (int) $job->attempts >= 10
            );
            if ($stale->count() !== $reservedJobs->count()) {
                $this->error('Refusing stale recovery: reservation is recent and has not crossed the contention threshold.');
                return self::FAILURE;
            }

            $staleAgentIds = $stale->map(fn ($job): ?int => $this->labAgentIdFromPayload((string) $job->payload))
                ->filter()
                ->unique()
                ->values();

            DB::transaction(function () use ($stale, $key): void {
                DB::table('jobs')->whereIn('id', $stale->pluck('id')->all())->update([
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
            $this->warn('Requeued '. $stale->count().' stale evaluator reservation(s); no job or evidence was deleted.');
            return self::SUCCESS;
        }

        $activeReplay = $reservedJobs->isNotEmpty();

        if ($activeReplay) {
            $this->line('Evaluator mutex is held by a reserved replay; leaving it untouched.');
            return self::SUCCESS;
        }

        $deleted = DB::table('cache_locks')->where('key', $key)->delete();
        if ($deleted > 0) {
            $this->warn('Removed orphaned evaluator replay mutex; no reserved lab replay was present.');
        }

        return self::SUCCESS;
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
