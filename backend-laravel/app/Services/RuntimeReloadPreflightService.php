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
    public function inspect(?int $terminalProjectionRecoveryGenerationId = null): array
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
        if ($terminalProjectionRecoveryGenerationId !== null) {
            return $this->inspectTerminalProjectionRecovery($terminalProjectionRecoveryGenerationId, $backlog, $activeGenerations);
        }
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

    /** Explicit cold maintenance only; the default rolling-reload contract is unchanged. */
    private function inspectTerminalProjectionRecovery(int $generationId, array $backlog, int $activeGenerations): array
    {
        $result = ['protocol' => self::PROTOCOL, 'safe' => false,
            'mode' => 'observed_council_terminal_projection_cold_maintenance_v1',
            'reason' => 'TERMINAL_PROJECTION_RECOVERY_REFUSED', 'generation_id' => $generationId,
            'reserved_queue' => $backlog, 'active_generations' => $activeGenerations,
            'rolling_reload_allowed' => false, 'replay_idle_probe_required' => true,
            'scientific_evidence' => false, 'promotion_evidence' => false];
        foreach (['total', 'pending', 'delayed'] as $key) {
            if (! is_int($backlog[$key] ?? null) || $backlog[$key] < 0) {
                return [...$result, 'reason' => 'QUEUE_STATE_UNKNOWN'];
            }
            if ($backlog[$key] !== 0) return [...$result, 'reason' => 'QUEUE_WORK_REMAINS'];
        }
        $generation = $generationId > 0 ? LabGeneration::with('laboratory')->find($generationId) : null;
        if ($activeGenerations !== 1 || ! $generation
            || ! in_array($generation->status, ['screening', 'full_validation'], true)) {
            return [...$result, 'reason' => 'TERMINAL_PROJECTION_RECOVERY_TARGET_NOT_SOLE_ACTIVE'];
        }
        $control = app(AutonomousModeService::class)->status($generation->laboratory->symbol, $generation->laboratory->timeframe);
        if (($control['enabled'] ?? null) !== false || ! in_array($control['state'] ?? null, ['draining', 'stopped'], true)) {
            return [...$result, 'reason' => 'DRAIN_FIRST_STOP_REQUIRED'];
        }
        try {
            $proof = app(ObservedCouncilEpisodeDispositionService::class)->inspectGeneration($generation);
        } catch (\Throwable) {
            return [...$result, 'reason' => 'TERMINAL_PROJECTION_RECOVERY_PROOF_UNAVAILABLE'];
        }
        if (($proof['allowed'] ?? null) !== true || ($proof['promotion_evidence'] ?? null) !== false
            || ! is_array($proof['proof'] ?? null) || $proof['proof'] === []) {
            return [...$result, 'reason' => 'TERMINAL_PROJECTION_RECOVERY_PROOF_REFUSED'];
        }
        return [...$result, 'safe' => true, 'reason' => 'TERMINAL_PROJECTION_COLD_MAINTENANCE_READY',
            'terminal_projection_proof' => $proof['proof']];
    }
}
