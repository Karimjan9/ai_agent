<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Give sealed full-validation evidence a fair turn on the single AI lane.
 *
 * Market screening workers are intentionally kept online, but they must not
 * repeatedly acquire the shared mutex while a full replay is waiting. This is
 * queue fairness only: it changes execution order, never candidate metrics or
 * any quality/promotion gate.
 */
class PreferFullValidationQueue
{
    public function __construct(private readonly string $mode)
    {
    }

    public function handle(object $job, Closure $next): void
    {
        if ($this->mode === 'screen' && $this->fullValidationIsWaiting()) {
            // This middleware runs before attempt evidence is opened, so a
            // fairness-only yield does not create a fake evaluation run. A
            // small serialized deferral budget prevents a screen from
            // polling the full queue forever while still giving full replay
            // priority during the short handoff window.
            $maxDeferrals = max(0, (int) config('services.lab_queue.fairness_max_deferrals', 2));
            $deferrals = (int) ($job->fullValidationDeferrals ?? 0);
            if ($deferrals < $maxDeferrals) {
                $job->fullValidationDeferrals = $deferrals + 1;
                $delay = max(30, (int) config('services.lab_queue.fairness_release_seconds', 300));
                $job->release($delay);
                return;
            }
        }

        $next($job);
    }

    private function fullValidationIsWaiting(): bool
    {
        $now = now()->timestamp;

        return DB::table('jobs')
            ->where('queue', 'lab-full-validation')
            // A delayed full replay is not waiting yet. Reserved jobs are
            // included because they may be inside middleware or running in
            // the shared lane even though their available_at is in the past.
            ->where(function ($query) use ($now): void {
                $query->whereNotNull('reserved_at')
                    ->orWhere('available_at', '<=', $now);
            })
            ->exists()
            || DB::table('job_batches')
                ->whereIn('name', ['Portfolio member full validation', 'Global full validation'])
                ->whereNull('finished_at')
                ->where('pending_jobs', '>', 0)
                ->exists();
    }
}
