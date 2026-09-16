<?php

namespace App\Services;

use App\Jobs\RunScheduledArtisanCommandJob;
use App\Models\LabLifecycleCycle;
use App\Models\ResearchLoopDecision;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Recovers a killed autonomous child without permitting concurrent owners. */
class StaleAutonomousWorkRecoveryService
{
    public const PROTOCOL = 'stale_autonomous_work_recovery_v1';

    /** @return array<string,mixed> */
    public function reconcile(string $symbol, string $timeframe): array
    {
        $constructor = app(LabPopulationService::class)
            ->recoverStaleConstructorLease($symbol, $timeframe);
        if (($constructor['recovered'] ?? false) !== true) {
            return [
                'protocol' => self::PROTOCOL,
                'status' => 'no_recovery',
                'constructor' => $constructor,
                'decisions_reconciled' => 0,
                'cycles_reconciled' => 0,
                'promotion_evidence' => false,
            ];
        }

        $owner = (array) ($constructor['owner'] ?? []);
        $ownerCommand = (string) ($owner['command'] ?? '');
        $ownerHeartbeat = (string) ($owner['heartbeat_at'] ?? $owner['acquired_at'] ?? '');
        try {
            $staleBefore = CarbonImmutable::parse($ownerHeartbeat)->utc();
        } catch (\Throwable) {
            $staleBefore = now()->utc()->subMinutes(3);
        }

        $decisions = Schema::hasTable('research_loop_decisions')
            ? ResearchLoopDecision::query()
                ->where('symbol', strtoupper($symbol))
                ->where('timeframe', strtoupper($timeframe))
                ->whereIn('status', ['selected', 'dispatched', 'running'])
                ->where('queue', 'scheduler-constructor')
                ->orderBy('id')
                ->get()
                ->filter(fn (ResearchLoopDecision $decision): bool => filled($decision->command)
                    && str_contains($ownerCommand, (string) $decision->command))
                ->values()
            : collect();

        $uniqueIds = [];
        foreach ($decisions as $decision) {
            $job = new RunScheduledArtisanCommandJob(
                (string) $decision->command,
                (array) $decision->arguments,
                (string) $decision->queue,
                (int) $decision->id,
            );
            if (! in_array($job->uniqueId(), $uniqueIds, true)) {
                (new UniqueLock(Cache::store()))->release($job);
                $uniqueIds[] = $job->uniqueId();
            }
            Cache::put($job->statusCacheKey(), [
                'protocol' => self::PROTOCOL,
                'command' => $job->command,
                'lane' => $job->lane,
                'status' => 'recovered_interrupted',
                'stale_owner_pid' => data_get($owner, 'pid'),
                'stale_owner_heartbeat_at' => $ownerHeartbeat,
                'updated_at' => now()->utc()->toIso8601String(),
                'promotion_evidence' => false,
            ], now()->addDay());
            $decision->update(['status' => 'failed', 'completed_at' => now()]);
        }

        $cycles = 0;
        if (Schema::hasTable('lab_lifecycle_cycles')) {
            $cycles = LabLifecycleCycle::query()
                ->where('symbol', strtoupper($symbol))
                ->where('timeframe', strtoupper($timeframe))
                ->where('status', 'running')
                ->where('heartbeat_at', '<=', $staleBefore)
                ->update([
                    'status' => 'interrupted',
                    'summary' => 'Dead local constructor owner was automatically fenced; lifecycle will resume from durable evidence.',
                    'context' => [
                        'protocol' => self::PROTOCOL,
                        'reason_code' => 'DEAD_LOCAL_CONSTRUCTOR_OWNER_RECOVERED',
                        'stale_owner_pid' => data_get($owner, 'pid'),
                        'promotion_evidence' => false,
                    ],
                    'heartbeat_at' => now()->utc(),
                    'finished_at' => now()->utc(),
                ]);
        }

        Log::warning('Reconciled stale autonomous constructor work.', [
            'symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe),
            'decisions_reconciled' => $decisions->count(),
            'cycles_reconciled' => $cycles, 'constructor' => $constructor,
        ]);

        return [
            'protocol' => self::PROTOCOL,
            'status' => 'recovered',
            'constructor' => $constructor,
            'decisions_reconciled' => $decisions->count(),
            'cycles_reconciled' => $cycles,
            'released_unique_job_ids' => $uniqueIds,
            'promotion_evidence' => false,
        ];
    }
}
