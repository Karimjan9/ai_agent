<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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
        $key = 'neurotrader-lab-cache-laravel-queue-overlap:App\\Jobs\\EvaluateLabAgentJob:neurotrader-ai-heavy-replay';
        $lock = DB::table('cache_locks')->where('key', $key)->first();

        if (! $lock) {
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
            ->get(['id', 'queue', 'reserved_at', 'attempts']);

        // A worker restart can leave both the queue reservation and the
        // overlap lock behind.  The normal path remains conservative.  The
        // explicit flag is intended for an operator who has already verified
        // that the old worker is gone and the AI service has no active replay;
        // it requeues the exact jobs and never deletes evidence.
        if ((bool) $this->option('force-stale')) {
            $staleAfter = max(120, (int) $this->option('stale-after'));
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

            DB::transaction(function () use ($stale, $key): void {
                DB::table('jobs')->whereIn('id', $stale->pluck('id')->all())->update([
                    'reserved_at' => null,
                    'available_at' => now()->timestamp,
                ]);
                DB::table('cache_locks')->where('key', $key)->delete();
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
}
