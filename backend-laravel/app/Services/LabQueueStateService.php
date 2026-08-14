<?php

namespace App\Services;

use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only queue transport adapter used by lab monitoring and fairness.
 *
 * The application may use either the legacy database transport or Redis.
 * Reading only the jobs table after switching to Redis makes every monitor
 * report an empty queue and makes recovery decisions unsafe.  This service
 * keeps the transport difference in one place; it never changes a queue.
 */
class LabQueueStateService
{
    public function backend(): string
    {
        return (string) config('queue.default', 'database');
    }

    public function isRedis(): bool
    {
        return $this->backend() === 'redis';
    }

    /**
     * @param array<int, string> $queues
     * @return array<string, mixed>
     */
    public function snapshot(array $queues): array
    {
        $queues = array_values(array_unique(array_filter(array_map('strval', $queues))));
        if ($queues === []) {
            return ['backend' => $this->backend(), 'total' => 0, 'queues' => [], 'rows' => []];
        }

        return $this->isRedis()
            ? $this->redisSnapshot($queues)
            : $this->databaseSnapshot($queues);
    }

    /** @return array<string, mixed> */
    private function databaseSnapshot(array $queues): array
    {
        if (! Schema::hasTable('jobs')) {
            return ['backend' => 'database', 'total' => 0, 'queues' => [], 'rows' => []];
        }

        $rows = DB::table('jobs')
            ->whereIn('queue', $queues)
            ->orderBy('id')
            ->limit(5000)
            ->get(['id', 'queue', 'attempts', 'reserved_at', 'available_at', 'created_at', 'payload']);

        $queueStats = [];
        foreach ($queues as $queue) {
            $queueRows = $rows->where('queue', $queue);
            $queueStats[$queue] = $this->statsFromRows($queueRows->all());
        }

        return [
            'backend' => 'database',
            'total' => (int) $rows->count(),
            'queues' => array_map(fn (array $stats): int => (int) $stats['total'], $queueStats),
            'stats' => $queueStats,
            'rows' => $rows->map(fn (object $row): array => (array) $row)->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function redisSnapshot(array $queues): array
    {
        $queue = app('queue')->connection('redis');
        if (! $queue instanceof RedisQueue) {
            // A custom queue connector must not be guessed at.  Returning an
            // unknown state makes callers fail closed instead of claiming
            // that Redis is idle.
            return [
                'backend' => 'redis', 'available' => false, 'total' => null,
                'queues' => [], 'stats' => [], 'rows' => [],
            ];
        }

        $stats = [];
        $rows = [];
        foreach ($queues as $name) {
            $pending = (int) $queue->pendingSize($name);
            $delayed = (int) $queue->delayedSize($name);
            $reserved = (int) $queue->reservedSize($name);
            $pendingPayloads = $this->redisList($queue, $queue->getQueue($name));
            $delayedPayloads = $this->redisSortedSet($queue, $queue->getQueue($name).':delayed');
            $reservedPayloads = $this->redisSortedSet($queue, $queue->getQueue($name).':reserved');
            $payloads = [...$pendingPayloads, ...$delayedPayloads, ...$reservedPayloads];
            $queueStats = $this->statsFromRedisPayloads($payloads, $pending, $delayed, $reserved, $queue->creationTimeOfOldestPendingJob($name));
            $stats[$name] = $queueStats;
            foreach ([
                ...array_map(static fn (string $payload): array => [$payload, 'pending'], $pendingPayloads),
                ...array_map(static fn (string $payload): array => [$payload, 'delayed'], $delayedPayloads),
                ...array_map(static fn (string $payload): array => [$payload, 'reserved'], $reservedPayloads),
            ] as [$payload, $state]) {
                $decoded = json_decode($payload, true);
                if (is_array($decoded)) {
                    $rows[] = [
                        'id' => (string) ($decoded['id'] ?? ''),
                        'queue' => $name,
                        'attempts' => (int) ($decoded['attempts'] ?? 0),
                        'reserved_at' => $state === 'reserved' ? $this->redisReservedScore($queue, $queue->getQueue($name).':reserved', $payload) : null,
                        'available_at' => null,
                        'created_at' => isset($decoded['createdAt']) ? (int) $decoded['createdAt'] : null,
                        'redis_state' => $state,
                        'payload' => $payload,
                    ];
                }
            }
        }

        $total = array_sum(array_map(fn (array $row): int => (int) $row['total'], $stats));

        return [
            'backend' => 'redis', 'available' => true, 'total' => $total,
            'queues' => array_map(fn (array $row): int => (int) $row['total'], $stats),
            'stats' => $stats, 'rows' => $rows,
        ];
    }

    /** @return array<int, string> */
    public function payloads(array $queues): array
    {
        $snapshot = $this->snapshot($queues);

        return array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['payload'] ?? ''),
            (array) ($snapshot['rows'] ?? []),
        )));
    }

    /**
     * Atomically move one pending Redis payload between lab priority queues.
     * The payload is removed only if it is still pending; a worker that won
     * the race makes the operation a harmless no-op instead of duplicating a
     * replay.
     */
    public function movePendingPayload(string $from, string $to, string $payload): bool
    {
        $queue = app('queue')->connection('redis');
        if (! $queue instanceof RedisQueue) return false;

        $result = $queue->getConnection()->eval(<<<'LUA'
local removed = redis.call('lrem', KEYS[1], 1, ARGV[1])
if removed == 1 then
    redis.call('rpush', KEYS[2], ARGV[1])
    redis.call('rpush', KEYS[3], 1)
    return 1
end
return 0
LUA, 3, $queue->getQueue($from), $queue->getQueue($to), $queue->getQueue($to).':notify', $payload);

        return (int) $result === 1;
    }

    /** Move one reserved Redis payload back to its pending queue. */
    public function releaseReservedPayload(string $queueName, string $payload): bool
    {
        $queue = app('queue')->connection('redis');
        if (! $queue instanceof RedisQueue) return false;

        $result = $queue->getConnection()->eval(<<<'LUA'
local removed = redis.call('zrem', KEYS[1], ARGV[1])
if removed == 1 then
    redis.call('rpush', KEYS[2], ARGV[1])
    redis.call('rpush', KEYS[3], 1)
    return 1
end
return 0
LUA, 3, $queue->getQueue($queueName).':reserved', $queue->getQueue($queueName), $queue->getQueue($queueName).':notify', $payload);

        return (int) $result === 1;
    }

    /** @return array<string, int|null> */
    private function statsFromRows(array $rows): array
    {
        $reserved = array_values(array_filter($rows, static fn (object|array $row): bool => data_get($row, 'reserved_at') !== null));
        $now = now()->timestamp;
        $delayed = array_values(array_filter($rows, static fn (object|array $row): bool => data_get($row, 'reserved_at') === null
            && self::timestamp(data_get($row, 'available_at')) !== null
            && (int) self::timestamp(data_get($row, 'available_at')) > $now));
        $pending = array_values(array_filter($rows, static fn (object|array $row): bool => data_get($row, 'reserved_at') === null
            && ! in_array($row, $delayed, true)));
        $created = array_values(array_filter(array_map(static fn (object|array $row): ?int => self::timestamp(data_get($row, 'created_at')), $rows)));
        $reservedAt = array_values(array_filter(array_map(static fn (object|array $row): ?int => self::timestamp(data_get($row, 'reserved_at')), $reserved)));
        $attempts = array_map(static fn (object|array $row): int => (int) data_get($row, 'attempts', 0), $rows);

        return [
            'total' => count($rows), 'pending' => count($pending), 'delayed' => count($delayed),
            'reserved' => count($reserved), 'oldest_created_at' => $created === [] ? null : min($created),
            'oldest_reserved_at' => $reservedAt === [] ? null : min($reservedAt),
            'max_attempts' => $attempts === [] ? 0 : max($attempts),
        ];
    }

    /** @param array<int, string> $payloads @return array<string, int|null> */
    private function statsFromRedisPayloads(array $payloads, int $pending, int $delayed, int $reserved, mixed $oldestPending): array
    {
        $created = [];
        $attempts = [];
        foreach ($payloads as $payload) {
            $decoded = json_decode($payload, true);
            if (! is_array($decoded)) continue;
            if (isset($decoded['createdAt'])) $created[] = (int) $decoded['createdAt'];
            $attempts[] = (int) ($decoded['attempts'] ?? 0);
        }

        return [
            'total' => $pending + $delayed + $reserved, 'pending' => $pending,
            'delayed' => $delayed, 'reserved' => $reserved,
            'oldest_created_at' => $oldestPending !== null ? (int) $oldestPending : ($created === [] ? null : min($created)),
            'oldest_reserved_at' => null,
            'max_attempts' => $attempts === [] ? 0 : max($attempts),
        ];
    }

    /** @return array<int, string> */
    private function redisList(RedisQueue $queue, string $key): array
    {
        return array_values(array_filter(array_map('strval', (array) $queue->getConnection()->lrange($key, 0, -1))));
    }

    /** @return array<int, string> */
    private function redisSortedSet(RedisQueue $queue, string $key): array
    {
        return array_values(array_filter(array_map('strval', (array) $queue->getConnection()->zrange($key, 0, -1))));
    }

    private function redisReservedScore(RedisQueue $queue, string $key, string $payload): ?int
    {
        $scores = (array) $queue->getConnection()->zrange($key, 0, -1, ['withscores' => true]);
        if (isset($scores[$payload])) return (int) $scores[$payload];

        return null;
    }

    private static function timestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) return (int) $value;
        $parsed = strtotime((string) $value);

        return $parsed === false ? null : $parsed;
    }
}
