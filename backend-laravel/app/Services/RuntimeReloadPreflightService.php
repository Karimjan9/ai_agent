<?php

namespace App\Services;

use App\Models\LabGeneration;

/**
 * Durable boundary for a PM2 rolling reload.
 *
 * AI liveness alone has a blind spot while a queue worker owns a job but is
 * still preparing its frozen snapshot. A reload in that interval kills the
 * PHP owner and leaves the Redis reservation invisible until retry_after.
 */
class RuntimeReloadPreflightService
{
    public const PROTOCOL = 'runtime_reload_preflight_v1';

    public function __construct(private readonly LabQueueJobInspector $queues) {}

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        $backlog = $this->queues->reservedQueueBacklog([
            'lab-screening', 'lab-frontier', 'lab-full-validation',
            'lab-xauusd', 'lab-eurusd', 'lab-gbpusd', 'lab-learning',
            'market-maintenance', 'scheduler-critical', 'scheduler-ops',
            'scheduler-constructor', 'scheduler-research', 'strategy-lab', 'backtests',
        ]);
        $activeGenerations = LabGeneration::query()->whereIn('status', [
            'draft', 'queued', 'training', 'screening',
            'full_queued', 'full_validation',
        ])->count();
        $queueKnown = ($backlog['total'] ?? null) !== null;
        $safe = $queueKnown
            && (int) ($backlog['total'] ?? 0) === 0
            && $activeGenerations === 0;

        return [
            'protocol' => self::PROTOCOL,
            'safe' => $safe,
            'reason' => ! $queueKnown
                ? 'QUEUE_STATE_UNKNOWN'
                : ((int) ($backlog['total'] ?? 0) > 0
                    ? 'RESERVED_WORKER_JOB_EXISTS'
                    : ($activeGenerations > 0 ? 'ACTIVE_GENERATION_EXISTS' : 'IDLE')),
            'reserved_queue' => $backlog,
            'active_generations' => $activeGenerations,
            'promotion_evidence' => false,
        ];
    }
}
