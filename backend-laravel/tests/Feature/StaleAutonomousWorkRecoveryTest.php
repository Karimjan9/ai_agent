<?php

namespace Tests\Feature;

use App\Jobs\RunScheduledArtisanCommandJob;
use App\Models\LabLifecycleCycle;
use App\Models\ResearchLoopDecision;
use App\Services\LabPopulationService;
use App\Services\LabLifecycleOrchestrator;
use App\Services\StaleAutonomousWorkRecoveryService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StaleAutonomousWorkRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dead_local_constructor_releases_fenced_leases_and_closes_orphaned_work(): void
    {
        $lockKey = 'lab-population-constructor:XAUUSD:H1:v1';
        $ownerKey = $lockKey.':owner';
        $constructorLock = Cache::lock($lockKey, LabPopulationService::CONSTRUCTOR_LOCK_TTL_SECONDS);
        $this->assertTrue($constructorLock->get());
        Cache::put($ownerKey, [
            'protocol' => 'lab_population_constructor_owner_v1',
            'operation' => 'build', 'trigger' => 'learning_confirmation',
            'command' => base_path('artisan').' trading:run-lifecycle-cycle --symbol=XAUUSD --json',
            'pid' => 999999, 'hostname' => (string) (gethostname() ?: php_uname('n')),
            'acquired_at' => now()->subMinutes(10)->toIso8601String(),
            'heartbeat_at' => now()->subMinutes(10)->toIso8601String(),
            'promotion_evidence' => false,
        ], now()->addHour());
        $decision = ResearchLoopDecision::create([
            'decision_key' => hash('sha256', 'stale-autonomous-decision'),
            'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'action' => 'OPEN_CAUSAL_LEARNING_CONFIRMATION',
            'status' => 'dispatched', 'priority' => 92,
            'evidence_hash' => hash('sha256', 'stale-autonomous-evidence'),
            'command' => 'trading:run-lifecycle-cycle', 'queue' => 'scheduler-constructor',
            'arguments' => ['--symbol' => 'XAUUSD', '--json' => true],
            'reason_codes' => ['TARGET_ALIGNED_CAUSAL_LESSON_HAS_GENERATION_PRIORITY'],
            'evidence_snapshot' => [], 'contract' => [], 'dispatched_at' => now()->subMinutes(10),
        ]);
        LabLifecycleCycle::create([
            'cycle_id' => 'stale-local-constructor-cycle',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'status' => 'running', 'stage' => 'preflight',
            'context' => ['protocol' => 'lifecycle_checkpoint_v1'],
            'started_at' => now()->subMinutes(11),
            'heartbeat_at' => now()->subMinutes(11),
        ]);
        $job = new RunScheduledArtisanCommandJob(
            (string) $decision->command,
            (array) $decision->arguments,
            (string) $decision->queue,
            (int) $decision->id,
        );
        $unique = new UniqueLock(Cache::store());
        $this->assertTrue($unique->acquire($job));

        $result = app(StaleAutonomousWorkRecoveryService::class)->reconcile('XAUUSD', 'H1');

        $this->assertSame('recovered', $result['status']);
        $this->assertTrue((bool) data_get($result, 'constructor.recovered'));
        $this->assertSame(1, $result['decisions_reconciled']);
        $this->assertSame(1, $result['cycles_reconciled']);
        $this->assertSame('failed', $decision->fresh()->status);
        $this->assertSame('interrupted', LabLifecycleCycle::query()->sole()->status);
        $this->assertFalse(app(LabPopulationService::class)->constructorIsActive('XAUUSD', 'H1'));
        $this->assertTrue($unique->acquire($job));
        $unique->release($job);
    }

    public function test_live_constructor_owner_is_never_preempted(): void
    {
        $lockKey = 'lab-population-constructor:XAUUSD:H1:v1';
        $ownerKey = $lockKey.':owner';
        $constructorLock = Cache::lock($lockKey, LabPopulationService::CONSTRUCTOR_LOCK_TTL_SECONDS);
        $this->assertTrue($constructorLock->get());
        Cache::put($ownerKey, [
            'protocol' => 'lab_population_constructor_owner_v1',
            'command' => base_path('artisan').' trading:run-lifecycle-cycle --symbol=XAUUSD',
            'pid' => getmypid(), 'hostname' => (string) (gethostname() ?: php_uname('n')),
            'acquired_at' => now()->subMinutes(10)->toIso8601String(),
            'heartbeat_at' => now()->subMinutes(10)->toIso8601String(),
        ], now()->addHour());

        $result = app(StaleAutonomousWorkRecoveryService::class)->reconcile('XAUUSD', 'H1');

        $this->assertSame('no_recovery', $result['status']);
        $this->assertSame('owner_process_running', data_get($result, 'constructor.status'));
        $this->assertTrue(app(LabPopulationService::class)->constructorIsActive('XAUUSD', 'H1'));
        $constructorLock->release();
        Cache::forget($ownerKey);
    }

    public function test_legacy_ownerless_lifecycle_lock_uses_dead_constructor_receipt(): void
    {
        $lockKey = 'lifecycle-cycle:XAUUSD:H1';
        $lifecycleLock = Cache::lock($lockKey, LabLifecycleOrchestrator::LOCK_TTL_SECONDS);
        $this->assertTrue($lifecycleLock->get());
        LabLifecycleCycle::create([
            'cycle_id' => 'legacy-ownerless-lifecycle-cycle',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'status' => 'interrupted', 'stage' => 'preflight',
            'summary' => 'Dead constructor recovered.',
            'context' => [
                'protocol' => StaleAutonomousWorkRecoveryService::PROTOCOL,
                'reason_code' => 'DEAD_LOCAL_CONSTRUCTOR_OWNER_RECOVERED',
                'promotion_evidence' => false,
            ],
            'started_at' => now()->subMinutes(10),
            'heartbeat_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
        ]);

        $result = app(LabLifecycleOrchestrator::class)
            ->recoverStaleLifecycleLease('XAUUSD', 'H1');

        $this->assertSame('recovered_legacy_dead_constructor_receipt', $result['status']);
        $this->assertTrue($result['recovered']);
        $probe = Cache::lock($lockKey, 5);
        $this->assertTrue($probe->get());
        $probe->release();
    }

    public function test_live_lifecycle_owner_is_never_preempted(): void
    {
        $lockKey = 'lifecycle-cycle:XAUUSD:H1';
        $ownerKey = $lockKey.':owner';
        $lifecycleLock = Cache::lock($lockKey, LabLifecycleOrchestrator::LOCK_TTL_SECONDS);
        $this->assertTrue($lifecycleLock->get());
        Cache::put($ownerKey, [
            'protocol' => 'lab_lifecycle_owner_v1',
            'cycle_id' => 'live-lifecycle-owner',
            'command' => base_path('artisan').' trading:run-lifecycle-cycle --symbol=XAUUSD',
            'pid' => getmypid(), 'hostname' => (string) (gethostname() ?: php_uname('n')),
            'acquired_at' => now()->subMinutes(10)->toIso8601String(),
            'heartbeat_at' => now()->subMinutes(10)->toIso8601String(),
        ], now()->addHour());

        $result = app(LabLifecycleOrchestrator::class)
            ->recoverStaleLifecycleLease('XAUUSD', 'H1');

        $this->assertSame('owner_process_running', $result['status']);
        $this->assertFalse($result['recovered']);
        $probe = Cache::lock($lockKey, 5);
        $this->assertFalse($probe->get());
        $lifecycleLock->release();
        Cache::forget($ownerKey);
    }
}
