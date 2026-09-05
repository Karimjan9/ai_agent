<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Fail-closed production admission for every expensive Edge-to-Mastery step. */
class EdgeToMasteryAdmissionService
{
    public const PROTOCOL = 'edge_to_mastery_admission_v2';

    public function __construct(
        private RuntimeMonitoringService $runtime,
        private LabQueueJobInspector $queues,
        private FailureDojoService $dojo,
    ) {}

    /** @return array<string,mixed> */
    public function assess(string $symbol, string $timeframe, bool $observe = false): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $runtime = $this->runtime->inspect(false);
        $queue = $this->queues->queueSnapshot();
        $runnable = $this->queues->runnableLabQueueBacklog();
        $blockers = [];

        $critical = collect(['redis', 'queue', 'ai_service', 'scheduler'])
            ->filter(fn (string $key): bool => data_get($runtime, "checks.{$key}.status") !== 'ok')
            ->values()->all();
        if ($critical !== []) $blockers[] = 'RUNTIME_NOT_HEALTHY';
        if (($queue['available'] ?? true) === false || ($runnable['total'] ?? null) === null) {
            $blockers[] = 'QUEUE_STATE_UNAVAILABLE';
        } elseif ((int) ($runnable['total'] ?? 0) > 0) {
            $blockers[] = 'CANONICAL_REPLAY_QUEUE_NOT_IDLE';
        }

        $retryStorm = $this->retryStorm($queue);
        if ($retryStorm['detected']) $blockers[] = 'QUEUE_RETRY_STORM';

        $ai = (array) data_get($runtime, 'checks.ai_service.metrics', []);
        $aiIdle = (int) ($ai['active_requests'] ?? 0) === 0
            && (int) ($ai['screening_active'] ?? 0) === 0
            && (int) ($ai['full_active'] ?? 0) === 0;
        if (! $aiIdle) $blockers[] = 'AI_REPLAY_LANE_NOT_IDLE';

        $dojo = $this->dojoHealth($symbol, $timeframe);
        if (! $dojo['healthy']) $blockers[] = 'FAILURE_DOJO_NOT_HEALTHY';

        $idleStability = $this->idleStability($symbol, $timeframe, $aiIdle && (int) ($runnable['total'] ?? 1) === 0, $ai, $observe);
        if (! $idleStability['stable']) $blockers[] = 'REPLAY_IDLE_STABILITY_PENDING';

        return [
            'protocol' => self::PROTOCOL,
            'admitted' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'runtime' => $runtime,
            'queue' => $runnable,
            'retry_storm' => $retryStorm,
            'ai_replay_idle' => ['idle' => $aiIdle, 'metrics' => $ai],
            'idle_stability' => $idleStability,
            'failure_dojo' => $dojo,
            'confirmed_cartridge_required' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function dojoHealth(string $symbol, string $timeframe): array
    {
        try {
            $summary = $this->dojo->summary($symbol, $timeframe);
            $available = ($summary['available'] ?? false) === true;
            $legacyInvalid = (int) ($summary['legacy_invalid_pending'] ?? 0);
            $projectionMismatch = 0;
            if (Schema::hasTable('settlement_watermarks')) {
                $projectionMismatch = DB::table('settlement_watermarks')
                    ->where('symbol', $symbol)->where('timeframe', $timeframe)
                    ->where(function ($query): void {
                        $query->where(function ($nested): void {
                            $nested->where('terminal', true)->whereNotIn('disposition', SettlementWatermarkService::TERMINAL);
                        })->orWhere(function ($nested): void {
                            $nested->where('terminal', false)->whereIn('disposition', SettlementWatermarkService::TERMINAL);
                        });
                    })->count();
            }

            return [
                'healthy' => $available && $legacyInvalid === 0 && $projectionMismatch === 0,
                'query_operational' => true,
                'canonical_invalid_pending' => $legacyInvalid,
                'monitor_exception' => false,
                'settlement_projection_consistent' => $projectionMismatch === 0,
                'settlement_projection_mismatches' => $projectionMismatch,
                'actionable_pending' => (int) ($summary['actionable_pending'] ?? 0),
                'actionable_pending_blocks_genesis' => false,
            ];
        } catch (Throwable $exception) {
            return [
                'healthy' => false,
                'query_operational' => false,
                'canonical_invalid_pending' => null,
                'monitor_exception' => true,
                'settlement_projection_consistent' => false,
                'error_class' => $exception::class,
            ];
        }
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function retryStorm(array $snapshot): array
    {
        $attemptThreshold = max(2, (int) config('services.edge_director.retry_storm_attempts', 5));
        $rows = collect((array) ($snapshot['rows'] ?? []));
        $highAttempts = $rows->filter(fn (array $row): bool => (int) ($row['attempts'] ?? 0) >= $attemptThreshold);
        $leaseChurn = $rows->filter(fn (array $row): bool => ($row['redis_state'] ?? null) === 'reserved'
            && (int) ($row['attempts'] ?? 0) >= max(2, $attemptThreshold - 1));
        $duplicateSignatures = $rows->groupBy(function (array $row): string {
            $payload = (array) json_decode((string) ($row['payload'] ?? '{}'), true);
            return (string) ($row['queue'] ?? '').'|'.(string) data_get($payload, 'displayName', data_get($payload, 'job', 'unknown'));
        })->filter(fn ($group): bool => $group->count() >= 3 && $group->max('attempts') >= 3);
        $recentFailed = 0;
        if (Schema::hasTable('failed_jobs')) {
            $recentFailed = DB::table('failed_jobs')->whereIn('queue', $this->queues->labQueues())
                ->where('failed_at', '>=', now()->subMinutes(10))->count();
        }
        $failedVelocityLimit = max(1, (int) config('services.edge_director.failed_jobs_per_ten_minutes', 3));

        return [
            'detected' => $highAttempts->isNotEmpty() || $leaseChurn->isNotEmpty()
                || $duplicateSignatures->isNotEmpty() || $recentFailed >= $failedVelocityLimit,
            'attempt_threshold' => $attemptThreshold,
            'high_attempt_jobs' => $highAttempts->count(),
            'lease_churn_jobs' => $leaseChurn->count(),
            'duplicate_payload_signatures' => $duplicateSignatures->count(),
            'failed_jobs_last_ten_minutes' => $recentFailed,
            'delayed_jobs_are_not_a_storm_by_themselves' => true,
        ];
    }

    /** @param array<string,mixed> $ai @return array<string,mixed> */
    private function idleStability(string $symbol, string $timeframe, bool $idle, array $ai, bool $observe): array
    {
        $minimumSeconds = max(0, (int) config('services.edge_director.idle_stability_seconds', 10));
        $maximumSeconds = max($minimumSeconds + 1, (int) config('services.edge_director.idle_stability_max_age_seconds', 180));
        $key = 'edge-director:idle-probe:'.$symbol.':'.$timeframe;
        if (! $idle) {
            if ($observe) Cache::forget($key);
            return ['stable' => false, 'observations' => 0, 'minimum_seconds' => $minimumSeconds];
        }
        if ($minimumSeconds === 0) {
            return ['stable' => true, 'observations' => 2, 'elapsed_seconds' => 0, 'minimum_seconds' => 0];
        }

        $fingerprint = hash('sha256', json_encode([
            'service_pid' => $ai['service_pid'] ?? null,
            'screening_capacity' => $ai['screening_capacity'] ?? null,
        ], JSON_UNESCAPED_SLASHES));
        $prior = (array) Cache::get($key, []);
        // Carbon 3 returns a signed difference by default. The stored probe
        // is in the past, so `now()->diffInSeconds($probe)` is negative;
        // clamping that value directly to zero would make production wait
        // forever even though two distinct idle observations exist.
        $elapsed = isset($prior['observed_at'])
            ? (int) abs(now()->diffInSeconds($prior['observed_at']))
            : 0;
        $same = ($prior['fingerprint'] ?? null) === $fingerprint;
        $stable = $same && $elapsed >= $minimumSeconds && $elapsed <= $maximumSeconds;
        // Preserve the first observation while the same runtime fingerprint
        // remains idle. Rapid manual probes or concurrent monitors must not
        // keep moving the stability window forward forever. A changed or
        // expired fingerprint starts a genuinely new observation window.
        if ($observe && ! $stable && (! $same || $elapsed > $maximumSeconds)) {
            Cache::put($key, ['fingerprint' => $fingerprint, 'observed_at' => now()], now()->addSeconds($maximumSeconds));
        }

        return [
            'stable' => $stable,
            'observations' => $same ? 2 : 1,
            'elapsed_seconds' => $elapsed,
            'minimum_seconds' => $minimumSeconds,
            'maximum_age_seconds' => $maximumSeconds,
            'probe_persisted' => $observe && ! $stable,
        ];
    }
}
