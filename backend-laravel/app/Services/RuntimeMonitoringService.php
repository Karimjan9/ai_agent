<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\ServiceHealthCheck;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lightweight operational snapshot for the long-running local runtime.
 *
 * Durable strategy/evidence state remains in MySQL. Redis is checked as the
 * transport for queues, locks, cache and sessions; this monitor never
 * mutates queue payloads or agent/gate state.
 */
class RuntimeMonitoringService
{
    /**
     * @return array<string, mixed>
     */
    public function inspect(bool $persist = false): array
    {
        $checks = [
            'redis' => $this->redisCheck(),
            'queue' => $this->queueCheck(),
            'ai_service' => $this->aiServiceCheck(),
            'scheduler' => $this->schedulerCheck(),
            'agents' => $this->agentCheck(),
        ];

        if ($persist && Schema::hasTable('service_health_checks')) {
            $this->persistChecks($checks);
        }

        $critical = collect($checks)->where('status', 'critical')->count();
        $warning = collect($checks)->where('status', 'warning')->count();

        return [
            'protocol' => 'runtime_monitor_v1',
            'checked_at' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'overall' => $critical > 0 ? 'critical' : ($warning > 0 ? 'warning' : 'ok'),
            'critical' => $critical,
            'warning' => $warning,
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function redisCheck(): array
    {
        $cacheStore = (string) config('cache.default', 'file');
        $queueBackend = (string) config('queue.default', 'sync');
        $sessionDriver = (string) config('session.driver', 'file');
        // A failover profile still probes Redis first; marking it optional
        // would hide a degraded primary until the database fallback also
        // failed.
        $required = in_array($cacheStore, ['redis', 'redis_failover'], true)
            || in_array($queueBackend, ['redis', 'redis_failover'], true)
            || $sessionDriver === 'redis';

        if (! $required) {
            return [
                'status' => 'ok',
                'score' => 100,
                'message' => 'Redis is not required by the current environment profile.',
                'last_ok_at' => now()->toIso8601String(),
                'metrics' => [
                    'required' => false,
                    'cache_store' => $cacheStore,
                    'queue_backend' => $queueBackend,
                    'session_driver' => $sessionDriver,
                ],
            ];
        }

        $connections = ['default'];
        if ($cacheStore === 'redis') {
            $connections[] = (string) config('cache.stores.redis.connection', 'cache');
        }
        if ($sessionDriver === 'redis') {
            $connections[] = (string) config('session.connection', 'session');
        }
        $connections = array_values(array_unique(array_filter($connections)));
        $connectionMetrics = [];

        try {
            foreach ($connections as $name) {
                $started = microtime(true);
                $connection = app('redis')->connection($name);
                $pong = strtoupper((string) $connection->ping());
                if (! in_array($pong, ['PONG', '1'], true)) {
                    throw new \RuntimeException("Redis connection {$name} returned {$pong}.");
                }

                $connectionMetrics[$name] = [
                    'ping' => 'PONG',
                    'latency_ms' => round((microtime(true) - $started) * 1000, 2),
                    'dbsize' => (int) $connection->dbsize(),
                ];
            }

            if ($cacheStore === 'redis') {
                $probeKey = 'runtime-monitor:'.Str::uuid()->toString();
                $store = Cache::store('redis');
                $store->put($probeKey, 'ok', now()->addSeconds(15));
                $cacheRoundTrip = $store->get($probeKey) === 'ok';
                $store->forget($probeKey);
                if (! $cacheRoundTrip) {
                    throw new \RuntimeException('Redis cache round-trip failed.');
                }
            }

            $info = (array) app('redis')->connection('default')->info();
            $server = (array) ($info['Server'] ?? []);
            $memory = (array) ($info['Memory'] ?? []);
            $clients = (array) ($info['Clients'] ?? []);

            return [
                'status' => 'ok',
                'score' => 100,
                'message' => 'Redis is reachable and configured for the active transport layers.',
                'last_ok_at' => now()->toIso8601String(),
                'metrics' => [
                    'required' => true,
                    'cache_store' => $cacheStore,
                    'queue_backend' => $queueBackend,
                    'session_driver' => $sessionDriver,
                    'redis_version' => $server['redis_version'] ?? null,
                    'uptime_seconds' => isset($server['uptime_in_seconds']) ? (int) $server['uptime_in_seconds'] : null,
                    'used_memory_bytes' => isset($memory['used_memory']) ? (int) $memory['used_memory'] : null,
                    'connected_clients' => isset($clients['connected_clients']) ? (int) $clients['connected_clients'] : null,
                    'connections' => $connectionMetrics,
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'critical',
                'score' => 0,
                'message' => 'Redis health check failed: '.$this->safeError($exception),
                'last_ok_at' => null,
                'metrics' => [
                    'required' => true,
                    'cache_store' => $cacheStore,
                    'queue_backend' => $queueBackend,
                    'session_driver' => $sessionDriver,
                    'connections_checked' => $connections,
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function queueCheck(): array
    {
        $backend = (string) config('queue.default', 'sync');
        if ($backend !== 'redis') {
            $status = app()->environment('testing') ? 'ok' : 'critical';

            return [
                'status' => $status,
                'score' => $status === 'ok' ? 100 : 0,
                'message' => $status === 'ok'
                    ? "Testing profile uses {$backend} queue intentionally."
                    : "Production queue backend is {$backend}; Redis is required for lab workers.",
                'last_ok_at' => $status === 'ok' ? now()->toIso8601String() : null,
                'metrics' => ['backend' => $backend, 'queues' => []],
            ];
        }

        $queues = array_values(array_unique(array_filter([
            (string) config('services.lab_queue.screening_queue', 'lab-screening'),
            (string) config('services.lab_queue.frontier_queue', 'lab-frontier'),
            (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation'),
            (string) config('services.lab_queue.learning_queue', 'lab-learning'),
            ...((array) config('services.lab_queue.legacy_screening_queues', [])),
        ])));

        try {
            $snapshot = app(LabQueueJobInspector::class)->queueSnapshot($queues);
            if (($snapshot['available'] ?? true) === false) {
                return [
                    'status' => 'critical',
                    'score' => 0,
                    'message' => 'Redis queue state is unavailable; recovery must fail closed.',
                    'last_ok_at' => null,
                    'metrics' => ['backend' => 'redis', 'queues' => [], 'available' => false],
                ];
            }

            $failedRecent = null;
            if (Schema::hasTable('failed_jobs')) {
                $failedRecent = (int) DB::table('failed_jobs')
                    ->where('failed_at', '>=', now()->subHour())
                    ->count();
            }

            $stats = (array) ($snapshot['stats'] ?? []);
            $total = (int) ($snapshot['total'] ?? 0);

            return [
                'status' => 'ok',
                'score' => 100,
                'message' => "Redis queue is available; {$total} lab job(s) are pending/reserved/delayed.",
                'last_ok_at' => now()->toIso8601String(),
                'metrics' => [
                    'backend' => 'redis',
                    'available' => true,
                    'total' => $total,
                    'failed_last_hour' => $failedRecent,
                    'queues' => collect($stats)->map(fn (array $row): array => [
                        'pending' => (int) ($row['pending'] ?? 0),
                        'reserved' => (int) ($row['reserved'] ?? 0),
                        'delayed' => (int) ($row['delayed'] ?? 0),
                        'total' => (int) ($row['total'] ?? 0),
                    ])->all(),
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'critical',
                'score' => 0,
                'message' => 'Redis queue inspection failed: '.$this->safeError($exception),
                'last_ok_at' => null,
                'metrics' => ['backend' => 'redis', 'queues' => []],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function aiServiceCheck(): array
    {
        if (app()->environment('testing')) {
            return [
                'status' => 'ok',
                'score' => 100,
                'message' => 'AI service probe is skipped in the testing profile.',
                'last_ok_at' => now()->toIso8601String(),
                'metrics' => ['required' => false],
            ];
        }

        $url = rtrim((string) config('services.ai_service.url', 'http://127.0.0.1:9000'), '/').'/health';

        try {
            $started = microtime(true);
            $response = Http::connectTimeout(1)->timeout(4)->get($url);
            $body = (array) $response->json();
            $healthy = $response->successful()
                && data_get($body, 'status') === 'ok'
                && data_get($body, 'service') === 'neurotrader-ai-service';
            if (! $healthy) {
                return [
                    'status' => 'critical',
                    'score' => 0,
                    'message' => 'AI service health endpoint is not healthy.',
                    'last_ok_at' => null,
                    'metrics' => [
                        'url' => $url,
                        'http_status' => $response->status(),
                        'body_status' => data_get($body, 'status'),
                    ],
                ];
            }

            return [
                'status' => 'ok',
                'score' => 100,
                'message' => 'AI service is healthy and its replay lane is observable.',
                'last_ok_at' => now()->toIso8601String(),
                'metrics' => [
                    'url' => $url,
                    'latency_ms' => round((microtime(true) - $started) * 1000, 2),
                    'service_pid' => data_get($body, 'replay_liveness.service_pid'),
                    'active_requests' => (int) data_get($body, 'replay_liveness.active_requests', 0),
                    'screening_active' => (int) data_get($body, 'replay_liveness.screening_active', 0),
                    'screening_capacity' => (int) data_get($body, 'replay_liveness.screening_capacity', 0),
                    'full_active' => (int) data_get($body, 'replay_liveness.full_active', 0),
                    'last_replay_termination' => data_get($body, 'replay_liveness.last_replay_termination'),
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'critical',
                'score' => 0,
                'message' => 'AI service probe failed: '.$this->safeError($exception),
                'last_ok_at' => null,
                'metrics' => ['url' => $url],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulerCheck(): array
    {
        try {
            $heartbeat = Cache::get('system:scheduler-heartbeat');
            $lease = Cache::get('system:scheduler-lease');
            if (! $heartbeat) {
                return [
                    'status' => app()->environment('testing') ? 'ok' : 'warning',
                    'score' => app()->environment('testing') ? 100 : 55,
                    'message' => app()->environment('testing')
                        ? 'Scheduler heartbeat is not required in the testing profile.'
                        : 'Scheduler has not published a heartbeat yet.',
                    'last_ok_at' => app()->environment('testing') ? now()->toIso8601String() : null,
                    'metrics' => ['heartbeat' => null, 'lease' => $lease],
                ];
            }

            $heartbeatAt = Carbon::parse((string) $heartbeat);
            $age = max(0, now()->diffInSeconds($heartbeatAt));
            $criticalAfter = max(900, (int) config('services.scheduler.lease_seconds', 900));
            $warningAfter = min(600, max(300, intdiv($criticalAfter, 2)));
            $status = $age > $criticalAfter ? 'critical' : ($age > $warningAfter ? 'warning' : 'ok');

            return [
                'status' => $status,
                'score' => $status === 'ok' ? 100 : ($status === 'warning' ? 60 : 0),
                'message' => "Scheduler heartbeat age: {$age}s.",
                'last_ok_at' => $status === 'ok' ? now()->toIso8601String() : null,
                'metrics' => [
                    'heartbeat' => $heartbeatAt->toIso8601String(),
                    'heartbeat_age_seconds' => $age,
                    'lease' => is_array($lease) ? [
                        'pid' => $lease['pid'] ?? null,
                        'started_at' => $lease['started_at'] ?? null,
                        'lease_key' => $lease['lease_key'] ?? null,
                    ] : null,
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'critical',
                'score' => 0,
                'message' => 'Scheduler heartbeat check failed: '.$this->safeError($exception),
                'last_ok_at' => null,
                'metrics' => [],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function agentCheck(): array
    {
        if (! Schema::hasTable('lab_agents')) {
            return [
                'status' => app()->environment('testing') ? 'ok' : 'warning',
                'score' => app()->environment('testing') ? 100 : 55,
                'message' => 'Lab agent table is not available yet.',
                'last_ok_at' => app()->environment('testing') ? now()->toIso8601String() : null,
                'metrics' => ['statuses' => []],
            ];
        }

        try {
            $statusRows = LabAgent::query()
                ->selectRaw('lifecycle_status, COUNT(*) as total')
                ->groupBy('lifecycle_status')
                ->pluck('total', 'lifecycle_status')
                ->map(fn (mixed $total): int => (int) $total)
                ->all();
            $errorStatuses = collect($statusRows)
                ->filter(fn (int $total, string $status): bool => $total > 0 && preg_match('/error|failed|quarantine/i', $status) === 1)
                ->all();
            $linkedModelIds = LabAgent::query()
                ->whereNotNull('model_version_id')
                ->distinct()
                ->pluck('model_version_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values();
            $allModelStatuses = Schema::hasTable('model_versions')
                ? ModelVersion::query()
                    ->selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status')
                    ->map(fn (mixed $total): int => (int) $total)
                    ->all()
                : [];
            // Keep the agent projection cardinality aligned with the model
            // projection used for evolution dashboards. Directly-created
            // infrastructure/test models remain visible separately and must
            // not create a false 1262-vs-1266 lifecycle discrepancy.
            $modelStatuses = Schema::hasTable('model_versions')
                ? ModelVersion::query()
                    ->whereIn('id', $linkedModelIds->all())
                    ->selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status')
                    ->map(fn (mixed $total): int => (int) $total)
                    ->all()
                : [];
            $performanceStatuses = Schema::hasTable('model_market_performance')
                ? ModelMarketPerformance::query()
                    ->selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status')
                    ->map(fn (mixed $total): int => (int) $total)
                    ->all()
                : [];
            $championModels = (int) ($allModelStatuses['champion'] ?? 0);
            $championPerformances = (int) ($performanceStatuses['champion'] ?? 0);
            $championReady = $championModels > 0 || $championPerformances > 0;
            $activeStatuses = ['queued', 'screening', 'full_queued', 'training'];
            $staleActive = Schema::hasColumn('lab_agents', 'updated_at')
                ? (int) LabAgent::query()
                    ->whereIn('lifecycle_status', $activeStatuses)
                    ->where('updated_at', '<', now()->subHours(3))
                    ->count()
                : 0;
            $championWarning = ! app()->environment('testing') && ! $championReady;
            $status = ($staleActive > 0 || $championWarning) ? 'warning' : 'ok';
            $message = $staleActive > 0
                ? "{$staleActive} active agent(s) have not changed for three hours."
                : ($championWarning
                    ? 'No champion evidence is ready yet; evolutionary replay remains in progress.'
                    : 'Agent lifecycle state is readable; champion evidence is present.');

            return [
                'status' => $status,
                'score' => $status === 'ok' ? 100 : 60,
                'message' => $message.' Error/quarantine counts are exposed for review.',
                'last_ok_at' => $status === 'ok' ? now()->toIso8601String() : null,
                'metrics' => [
                    'total' => array_sum($statusRows),
                    'statuses' => $statusRows,
                    'error_statuses' => $errorStatuses,
                    'model_statuses' => $modelStatuses,
                    'all_model_statuses' => $allModelStatuses,
                    'unlinked_model_count' => max(0, array_sum($allModelStatuses) - array_sum($modelStatuses)),
                    'performance_statuses' => $performanceStatuses,
                    'champion_models' => $championModels,
                    'champion_performances' => $championPerformances,
                    'champion_ready' => $championReady,
                    'stale_active' => $staleActive,
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'critical',
                'score' => 0,
                'message' => 'Agent lifecycle inspection failed: '.$this->safeError($exception),
                'last_ok_at' => null,
                'metrics' => [],
            ];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $checks
     */
    private function persistChecks(array $checks): void
    {
        $labels = [
            'redis' => 'Runtime Redis',
            'queue' => 'Runtime Queue',
            'ai_service' => 'Runtime AI Service',
            'scheduler' => 'Runtime Scheduler',
            'agents' => 'Runtime Agents',
        ];

        foreach ($checks as $key => $check) {
            $serviceKey = 'runtime:'.$key;
            $existing = ServiceHealthCheck::query()->where('service_key', $serviceKey)->first();
            ServiceHealthCheck::updateOrCreate(
                ['service_key' => $serviceKey],
                [
                    'service_label' => $labels[$key] ?? Str::headline($key),
                    'status' => $check['status'],
                    'health_score' => (float) ($check['score'] ?? 0),
                    'last_ok_at' => $check['status'] === 'ok' ? now() : $existing?->last_ok_at,
                    'last_checked_at' => now(),
                    'stale_after_seconds' => $key === 'scheduler' ? 900 : 300,
                    'message' => (string) ($check['message'] ?? ''),
                    'metrics' => (array) ($check['metrics'] ?? []),
                ],
            );
        }
    }

    private function safeError(Throwable $exception): string
    {
        $message = preg_replace(
            '/((?:password|passwd|token|secret|apikey|api_key)[=:])[^\s&]+/i',
            '$1[REDACTED]',
            $exception->getMessage(),
        ) ?: get_class($exception);

        return Str::limit($message, 240);
    }
}
