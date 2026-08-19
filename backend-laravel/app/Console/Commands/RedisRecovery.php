<?php

namespace App\Console\Commands;

use App\Services\RuntimeMonitoringService;
use App\Console\Commands\Concerns\OperationalCommand;

/**
 * Produces an explicit, non-mutating Redis failover/recovery decision.
 *
 * Transport changes are intentionally left to the operator/runbook: changing
 * queue or session drivers in a live process can duplicate a long replay or
 * invalidate an authenticated browser session.
 */
class RedisRecovery extends OperationalCommand
{
    protected $signature = 'system:redis-recovery
        {--json : Emit the runbook decision as JSON}
        {--strict : Return failure while Redis is unavailable}';

    protected $description = 'Check Redis and print the controlled fallback or recovery profile';

    public function handle(RuntimeMonitoringService $monitor): int
    {
        $runtime = $monitor->inspect(persist: true);
        $redis = (array) ($runtime['checks']['redis'] ?? []);
        $healthy = ($redis['status'] ?? 'critical') === 'ok';
        $profile = [
            'cache_store' => (string) config('services.redis_availability.cache_failover_store', 'redis_failover'),
            'queue_connection' => (string) config('services.redis_availability.queue_failover_connection', 'redis_failover'),
            'session_driver' => (string) config('services.redis_availability.session_failover_driver', 'database'),
        ];
        $report = [
            'protocol' => 'redis_recovery_v1',
            'checked_at' => now()->toIso8601String(),
            'redis' => $redis,
            'decision' => $healthy ? 'primary_healthy' : 'controlled_failover_required',
            'current_transport' => [
                'cache_store' => config('cache.default'),
                'queue_connection' => config('queue.default'),
                'session_driver' => config('session.driver'),
            ],
            'fallback_profile' => $profile,
            'runbook' => 'docs/operations/redis-recovery.md',
        ];

        if ($this->option('json')) {
            $this->writeJson($report);
        } elseif ($healthy) {
            $this->info('Redis is healthy. Keep the primary transport profile active.');
        } else {
            $this->error('Redis is unavailable. Do not switch a live worker in place. Drain/stop workers and follow docs/operations/redis-recovery.md.');
            $this->line('Fallback profile: '.json_encode($profile, JSON_UNESCAPED_SLASHES));
        }

        return $this->option('strict') && ! $healthy ? self::FAILURE : self::SUCCESS;
    }
}
