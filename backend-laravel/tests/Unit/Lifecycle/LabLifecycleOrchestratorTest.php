<?php

namespace Tests\Unit\Lifecycle;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Models\LabLearningLaneDispatch;
use App\Services\LabAgentEvaluationService;
use App\Services\LabAgentPreflightService;
use App\Services\LabLifecycleErrorLogger;
use App\Services\LabLifecycleOrchestrator;
use App\Services\LabPopulationService;
use App\Services\LabQueueJobInspector;
use App\Services\LabQueueStateService;
use App\Services\LearningProtocolSafetyService;
use App\Services\LearningVelocityGateService;
use App\Services\SystemLogService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery as m;
use Tests\TestCase;

class LabLifecycleOrchestratorTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanLogDir();
        config(['services.lifecycle_orchestrator.max_failed_jobs' => 999]);
        // AI probe: by default return idle, healthy.
        $this->fakeAiIdle(true);
        Cache::put('system:scheduler-heartbeat', now()->toIso8601String(), now()->addMinute());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        m::close();
        $this->cleanLogDir();
    }

    public function test_healthy_empty_state_creates_exactly_one_valid_generation(): void
    {
        $this->seedLaboratory();
        $this->bindPopulation($paused = false);

        $orchestrator = app(LabLifecycleOrchestrator::class);
        $result = $orchestrator->run('XAUUSD', 'H1', 'tc-001');

        $this->assertSame('completed', $result['status']);
        $this->assertGreaterThanOrEqual(1, $result['data']['generation_id'] ?? 0);
        $this->assertCount(1, LabGeneration::all());
    }

    public function test_repeated_cycle_does_not_duplicate_anything(): void
    {
        $this->seedLaboratory();
        $this->bindPopulation($paused = false);
        $orchestrator = app(LabLifecycleOrchestrator::class);

        $first = $orchestrator->run('XAUUSD', 'H1', 'tc-002a');
        $second = $orchestrator->run('XAUUSD', 'H1', 'tc-002b');

        $this->assertSame('completed', $first['status']);
        $this->assertSame('completed', $second['status']);
        $this->assertCount(1, LabGeneration::all());
    }

    public function test_strategy_deadlock_blocks_normal_generation_and_uses_recovery_path(): void
    {
        $this->seedLaboratory();
        $this->bindPopulation($paused = true, pendingDojo: 5);

        $orchestrator = app(LabLifecycleOrchestrator::class);
        $result = $orchestrator->run('XAUUSD', 'H1', 'tc-003');

        // Deadlock => normal generation is blocked; only bounded recovery runs.
        $this->assertSame(LabLifecycleOrchestrator::PHASE_LEARNING_RECOVERY, $result['stage']);
        $this->assertCount(0, LabGeneration::all());
    }

    public function test_daily_budget_blocks_new_allocation_but_not_an_existing_retry_seat(): void
    {
        $this->seedLaboratory();
        config(['services.lifecycle_orchestrator.max_recovery_dispatch_per_day' => 2]);
        foreach ([1, 2] as $seat) {
            LabLearningLaneDispatch::create([
                'dispatch_key' => 'allocated-seat-'.$seat,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'strategy_family' => 'regime', 'status' => 'retry_ready',
                'stage' => 'micro', 'micro_status' => 'pending',
                'selected_at' => now(),
            ]);
        }
        $this->bindPopulation($paused = true, pendingDojo: 2);

        $result = app(LabLifecycleOrchestrator::class)->run('XAUUSD', 'H1', 'tc-003-budget');

        $this->assertSame(LabLifecycleOrchestrator::PHASE_LEARNING_RECOVERY, $result['stage']);
        $this->assertSame(
            'daily_budget_full_existing_retry',
            data_get($result, 'data.records.reconciliation_skipped_reason'),
        );
        $this->assertSame(2, data_get($result, 'data.records.allocated_micro_seats'));
    }

    public function test_typed_transport_timeout_uses_separate_bounded_technical_recovery(): void
    {
        $lab = $this->seedLaboratory();
        LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 94,
            'status' => 'screened',
            'population_size' => 20,
            'data_fingerprint' => 'g94-frozen',
            'trigger_type' => 'test',
            'trigger_context' => [],
        ]);
        config([
            'services.lifecycle_orchestrator.autonomous_technical_recovery_enabled' => true,
            'services.lifecycle_orchestrator.autonomous_technical_recovery_daily_limit' => 2,
        ]);
        $this->bindPopulation(
            paused: true,
            pendingDojo: 0,
            expectBuild: false,
            velocityStatus: 'blocked_technical_recovery',
        );

        $result = app(LabLifecycleOrchestrator::class)->run('XAUUSD', 'H1', 'tc-technical-recovery');

        $this->assertSame('running', $result['status']);
        $this->assertSame(LabLifecycleOrchestrator::PHASE_TECHNICAL_RECOVERY, $result['stage']);
        $this->assertSame(2, data_get($result, 'data.dispatched'));
        $this->assertSame([1786, 1787], data_get($result, 'data.records.agent_ids'));
    }

    public function test_runtime_outage_fails_closed(): void
    {
        $this->seedLaboratory();
        $this->bindPopulation($paused = false, expectBuild: false);
        // No internal token -> AI probe fails closed (runtime outage).
        config(['services.internal_api.token' => '']);

        $orchestrator = app(LabLifecycleOrchestrator::class);
        $result = $orchestrator->run('XAUUSD', 'H1', 'tc-004');

        $this->assertSame('blocked', $result['status']);
        $this->assertStringContainsStringIgnoringCase('runtime', (string) $result['summary']);
        $this->assertCount(0, LabGeneration::all());
    }

    public function test_failed_build_writes_an_error_jsonl_entry(): void
    {
        $this->seedLaboratory();
        $this->bindPopulation($paused = false, throwOnBuild: true);

        $orchestrator = app(LabLifecycleOrchestrator::class);
        $result = $orchestrator->run('XAUUSD', 'H1', 'tc-005');

        $this->assertSame('blocked', $result['status']);
        $this->assertTrue($this->errorLogExists(), 'An error JSONL entry must have been written.');
    }

    public function test_concurrent_cycle_lock_prevents_double_run(): void
    {
        $this->seedLaboratory();
        $this->bindPopulation($paused = false, expectBuild: false);

        Cache::lock('lifecycle-cycle:XAUUSD:H1', 120)->get(true);

        $orchestrator = app(LabLifecycleOrchestrator::class);
        $result = $orchestrator->run('XAUUSD', 'H1', 'tc-006');

        $this->assertSame('paused', $result['status']);
        $this->assertTrue($result['data']['locked'] ?? false);
    }

    public function test_error_logs_never_include_secrets(): void
    {
        $logger = new LabLifecycleErrorLogger();
        $logger->record('tc-007', 'XAUUSD', 'H1', 'generation',
            new \RuntimeException('Auth failed ?token=SUPERSECRET123&apiKey=sk-live-abc'));

        $files = File::allFiles(storage_path('logs/neurotrader/lifecycle-errors'));
        $this->assertNotEmpty($files);
        $content = collect($files)->map(fn (\SplFileInfo $f) => File::get($f->getRealPath()))->implode("\n");
        $this->assertStringNotContainsString('SUPERSECRET123', $content);
        $this->assertStringNotContainsString('sk-live-abc', $content);
        $this->assertStringContainsString('[REDACTED]', $content);
    }

    // ---- helpers ----

    private function seedLaboratory(): AiLaboratory
    {
        return AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'XAUUSD H1 lighthouse',
            'timeframe' => 'H1', 'strategy_families' => ['regime', 'volatility'],
            'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
    }

    private function bindPopulation(bool $paused = false, int $pendingDojo = 0, bool $throwOnBuild = false, bool $expectBuild = true, ?string $velocityStatus = null): void
    {
        $safety = m::mock(LearningProtocolSafetyService::class);
        $safety->shouldReceive('generationCreationPaused')->andReturn($paused);

        $velocity = m::mock(LearningVelocityGateService::class);
        $velocityStatus ??= $paused ? 'strategy_deadlock' : 'healthy';
        $velocity->shouldReceive('inspect')->andReturn($paused ? [
            'allowed' => false,
            'status' => $velocityStatus,
            'learning_starvation' => [
                'starved' => $pendingDojo > 0,
                'actionable_pending_dojo' => $pendingDojo,
                'active_dispatches' => 0,
            ],
            'health_layers' => ['strategy_deadlock' => ['active' => true]],
        ] : [
            'allowed' => true,
            'status' => 'healthy',
            'learning_starvation' => ['starved' => false, 'actionable_pending_dojo' => 0, 'active_dispatches' => 0],
            'health_layers' => ['strategy_deadlock' => ['active' => false]],
        ]);

        $queue = m::mock(LabQueueStateService::class);
        $queue->shouldReceive('snapshot')->andReturn([
            'backend' => 'redis', 'available' => true,
            'total' => $pendingDojo, 'queues' => ['lab-learning' => $pendingDojo],
        ]);
        $queue->shouldReceive('hasAgentJob')->andReturn(false);

        $queueJobs = m::mock(LabQueueJobInspector::class);
        $queueJobs->shouldReceive('hasAgentJob')->andReturn(false);

        $population = m::mock(LabPopulationService::class);
        if ($throwOnBuild) {
            $population->shouldReceive('build')->andThrow(new \RuntimeException('Simulated build failure', 500));
        } elseif (! $paused && $expectBuild) {
            $laboratoryId = (int) AiLaboratory::where('symbol', 'XAUUSD')->value('id');
            $population->shouldReceive('build')
                ->zeroOrMoreTimes()
                ->with('XAUUSD', 'new_data', false, 'H1')
                ->andReturn(LabGeneration::create([
                    'ai_laboratory_id' => $laboratoryId,
                    'generation' => 9999, 'status' => 'draft',
                    'population_size' => 20, 'data_fingerprint' => 'test',
                    'trigger_type' => 'new_data', 'trigger_context' => [],
                ]));
        } elseif (! $expectBuild) {
            $population->shouldReceive('build')->never();
        }

        $evaluation = m::mock(LabAgentEvaluationService::class);
        $evaluation->shouldReceive('screen')->andReturn(['passed' => true]);
        $evaluation->shouldReceive('evaluate')->andReturn(['passed' => true]);

        $preflight = m::mock(LabAgentPreflightService::class);
        $preflight->shouldReceive('admit')->andReturn(true);

        // Recovery path invokes trade:reconcile-learning-recovery via Artisan::call.
        // Fake the artisan call/output so no real command runs in unit tests.
        Artisan::shouldReceive('call')
            ->zeroOrMoreTimes()
            ->with(m::on(fn ($cmd) => $cmd === 'trading:reconcile-learning-recovery'), m::type('array'))
            ->andReturn(0);
        $learningDispatch = Artisan::shouldReceive('call')
            ->with(
                m::on(fn ($cmd) => $cmd === 'trading:dispatch-learning-lane'),
                m::on(fn ($arguments) => is_array($arguments)
                    && ($arguments['--autonomous'] ?? false) === true
                    && ($arguments['--retry-queued'] ?? false) === true),
            )
            ->andReturn(0);
        if ($paused && $pendingDojo > 0) {
            // An existing actionable backlog must be dispatched even when
            // reconciliation creates zero new retry_ready rows this cycle.
            $learningDispatch->once();
        } else {
            $learningDispatch->zeroOrMoreTimes();
        }
        $technicalRecovery = Artisan::shouldReceive('call')
            ->with(
                m::on(fn ($cmd) => $cmd === 'trading:recover-lab-evaluation-errors'),
                m::on(fn ($arguments) => is_array($arguments)
                    && ($arguments['--autonomous'] ?? false) === true
                    && ($arguments['--after-timeout-budget-repair'] ?? false) === true
                    && ($arguments['--generation'] ?? null) === 94),
            )
            ->andReturn(0);
        if ($velocityStatus === 'blocked_technical_recovery') {
            $technicalRecovery->once();
        } else {
            $technicalRecovery->zeroOrMoreTimes();
        }
        Artisan::shouldReceive('output')
            ->zeroOrMoreTimes()
            ->andReturn(json_encode($velocityStatus === 'blocked_technical_recovery'
                ? ['protocol' => 'autonomous_technical_recovery_v1', 'dispatched' => 2, 'agent_ids' => [1786, 1787]]
                : ['dojo_diagnostic_only' => 2, 'dispatched' => 2]));

        app()->instance(LearningProtocolSafetyService::class, $safety);
        app()->instance(LearningVelocityGateService::class, $velocity);
        app()->instance(LabQueueStateService::class, $queue);
        app()->instance(LabQueueJobInspector::class, $queueJobs);
        app()->instance(LabPopulationService::class, $population);
        app()->instance(LabAgentEvaluationService::class, $evaluation);
        app()->instance(LabAgentPreflightService::class, $preflight);
        app()->forgetInstance(LabLifecycleOrchestrator::class);
    }

    private function fakeAiIdle(bool $idle): void
    {
        Http::preventStrayRequests(false);
        Http::fake([
            '*/api/replay-status' => Http::response($idle
                ? ['protocol' => 'replay_liveness_probe_v1', 'active_requests' => 0]
                : ['protocol' => 'replay_liveness_probe_v1', 'active_requests' => 1],
                $idle ? 200 : 503),
        ]);
    }

    private function errorLogExists(): bool
    {        $dir = storage_path('logs/neurotrader/lifecycle-errors');
        if (! is_dir($dir)) return false;
        foreach (File::allFiles($dir) as $f) {
            if (filesize($f->getRealPath()) > 0) return true;
        }
        return false;
    }

    private function cleanLogDir(): void
    {
        $dir = storage_path('logs/neurotrader/lifecycle-errors');
        if (is_dir($dir)) {
            foreach (File::allFiles($dir) as $f) {
                @File::delete($f->getRealPath());
            }
        }
    }
}
