<?php

namespace App\Jobs\Middleware;

use Closure;
use App\Services\LabQueueJobInspector;

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
        return app(LabQueueJobInspector::class)->fullValidationIsWaiting();
    }
}
