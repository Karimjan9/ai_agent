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
            $job->release(15);
            return;
        }

        $next($job);
    }

    private function fullValidationIsWaiting(): bool
    {
        return DB::table('jobs')->where('queue', 'lab-full-validation')->exists()
            || DB::table('job_batches')
                ->whereIn('name', ['Portfolio member full validation', 'Global full validation'])
                ->whereNull('finished_at')
                ->exists();
    }
}
