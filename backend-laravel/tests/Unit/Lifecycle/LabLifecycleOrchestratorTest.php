<?php

namespace Tests\Unit\Lifecycle;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLaneDispatch;
use App\Models\ModelVersion;
use App\Services\GenerationAdmissionDecisionService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabAgentPreflightService;
use App\Services\LabLifecycleErrorLogger;
use App\Services\LabLifecycleOrchestrator;
use App\Services\LabPopulationService;
use App\Services\LabQueueJobInspector;
use App\Services\LabQueueStateService;
use App\Services\LearningProtocolSafetyService;
use App\Services\LearningVelocityGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery as m;
use Tests\TestCase;

class LabLifecycleOrchestratorTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_xauusd_timeframe_alias_routes_to_one_organism_and_creates_exactly_one_generation(): void
    {
        $this->seedLaboratory();
        $this->bindPopulation($paused = false);

        $orchestrator = app(LabLifecycleOrchestrator::class);
        $result = $orchestrator->run('XAUUSD', 'M15', 'tc-001');

        $this->assertSame('completed', $result['status']);
        $this->assertSame('H1', $result['timeframe']);
        $this->assertGreaterThanOrEqual(1, $result['data']['generation_id'] ?? 0);
        $this->assertCount(1, LabGeneration::all());

        $status = $orchestrator->status('XAUUSD', 'M5');
        $this->assertSame('XAUUSD', data_get($status, 'organism.scope'));
        $this->assertSame('symbol', data_get($status, 'organism.population_scope'));
        $this->assertSame('H1', data_get($status, 'organism.laboratory_storage_timeframe'));
        $this->assertSame('M5', data_get($status, 'organism.execution_timeframe'));
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

    public function test_active_generation_resumes_without_recomputing_successor_admission(): void
    {
        $lab = $this->seedLaboratory();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 220,
            'status' => 'screening',
            'population_size' => 20,
            'data_fingerprint' => 'active-generation-fast-path',
            'trigger_type' => 'learning_confirmation',
            'trigger_context' => [],
        ]);
        $this->bindPopulation(paused: false, expectBuild: false);

        $admission = m::mock(GenerationAdmissionDecisionService::class);
        $admission->shouldReceive('decide')->never();
        app()->instance(GenerationAdmissionDecisionService::class, $admission);
        app()->forgetInstance(LabLifecycleOrchestrator::class);

        $result = app(LabLifecycleOrchestrator::class)->run('XAUUSD', 'H1', 'tc-active-fast-path');

        $this->assertSame('completed', $result['status']);
        $this->assertSame($generation->id, data_get($result, 'data.generation_id'));
        $this->assertCount(1, LabGeneration::all());
    }

    public function test_explicit_recovery_owns_its_screen_dispatch_without_blocking_ordinary_or_descendant_models(): void
    {
        $method = new \ReflectionMethod(LabLifecycleOrchestrator::class, 'isExplicitTechnicalRecoveryDispatch');
        $method->setAccessible(true);
        $orchestrator = app(LabLifecycleOrchestrator::class);

        $ordinary = new LabAgent;
        $ordinary->created_at = now()->subHour();
        $ordinary->setRelation('modelVersion', new ModelVersion(['metadata' => []]));

        $recovery = new LabAgent;
        $recovery->created_at = now()->subHour();
        $recovery->setRelation('modelVersion', new ModelVersion(['metadata' => [
            'evaluator_recovery_attempts' => 1,
            'last_evaluator_recovery_at' => now()->toIso8601String(),
        ]]));

        $descendant = new LabAgent;
        $descendant->created_at = now();
        $descendant->setRelation('modelVersion', new ModelVersion(['metadata' => [
            'evaluator_recovery_attempts' => 1,
            'last_evaluator_recovery_at' => now()->subDay()->toIso8601String(),
        ]]));

        $this->assertFalse($method->invoke($orchestrator, $ordinary));
        $this->assertTrue($method->invoke($orchestrator, $recovery));
        $this->assertFalse(
            $method->invoke($orchestrator, $descendant),
            'Inherited recovery history must not suppress an ordinary descendant model.',
        );
    }

    public function test_audited_terminal_generation_routes_the_successor_to_the_data_edge_root_portfolio(): void
    {
        $lab = $this->seedLaboratory();
        LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 210,
            'status' => 'screened',
            'population_size' => 20,
            'data_fingerprint' => 'audited-terminal-generation',
            'trigger_type' => 'new_data',
            'trigger_context' => [
                'data_edge_audit' => ['protocol' => 'data_edge_audit_v1'],
                'latest_generation_report' => ['next_action' => 'data_edge_audit_completed'],
            ],
        ]);
        $this->bindPopulation(paused: false, expectedTrigger: 'data_edge_audit');

        $result = app(LabLifecycleOrchestrator::class)->run('XAUUSD', 'H1', 'tc-data-edge-successor');

        $this->assertSame('completed', $result['status'], json_encode($result, JSON_PRETTY_PRINT));
        $this->assertSame('data_edge_audit', LabGeneration::query()->latest('generation')->value('trigger_type'));
        $this->assertCount(2, LabGeneration::all());
    }

    public function test_fresh_incomplete_draft_is_not_concurrently_resumed_by_a_scheduler_tick(): void
    {
        $lab = $this->seedLaboratory();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 204,
            'status' => 'draft',
            'population_size' => 20,
            'data_fingerprint' => 'live-constructor',
            'trigger_type' => 'operator_successor',
            'trigger_context' => [
                'generation_plan' => array_fill(0, 20, ['family' => 'hybrid']),
            ],
        ]);
        $this->bindPopulation(paused: false, expectBuild: false);
        app(LabPopulationService::class)->shouldReceive('continueInterruptedConstruction')->never();

        $result = app(LabLifecycleOrchestrator::class)->run('XAUUSD', 'M15', 'tc-live-constructor');

        $this->assertSame('paused', $result['status']);
        $this->assertSame('H1', $result['timeframe']);
        $this->assertSame('GENERATION_CONSTRUCTION_ACTIVE', data_get($result, 'data.generation_outcome.reason_code'));
        $this->assertSame($generation->id, data_get($result, 'data.generation_outcome.generation_id'));
        $this->assertCount(1, LabGeneration::all());
    }

    public function test_fresh_incomplete_draft_without_a_constructor_lease_is_resumed(): void
    {
        $lab = $this->seedLaboratory();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 205,
            'status' => 'draft',
            'population_size' => 20,
            'data_fingerprint' => 'orphan-constructor',
            'trigger_type' => 'candidate_handoff',
            'trigger_context' => [
                'generation_plan' => array_fill(0, 20, ['family' => 'hybrid']),
            ],
        ]);
        $this->bindPopulation(paused: false, expectBuild: false);
        $population = app(LabPopulationService::class);
        $population->shouldReceive('constructorIsActive')->once()->andReturnFalse();
        $population->shouldReceive('continueInterruptedConstruction')->once()->with($generation->id, 4)->andReturn([
            'status' => 'partial',
            'generation' => $generation,
            'created_slots' => [1, 2, 3, 4],
            'completed_slots' => [1, 2, 3, 4],
            'failures' => [],
        ]);

        $result = app(LabLifecycleOrchestrator::class)->run('XAUUSD', 'H1', 'tc-orphan-constructor');

        $this->assertSame('paused', $result['status']);
        $this->assertSame('GENERATION_CONSTRUCTION_IN_PROGRESS', data_get($result, 'data.generation_outcome.reason_code'));
    }

    public function test_incomplete_quarantined_plan_owns_constructor_before_new_learning_admission(): void
    {
        $lab = $this->seedLaboratory();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 213,
            'status' => 'technical_quarantine',
            // The mutable projection can reflect only constructed seats. The
            // immutable plan remains the source of truth for the 20-seat cohort.
            'population_size' => 16,
            'data_fingerprint' => 'incomplete-causal-constructor',
            'trigger_type' => 'candidate_handoff',
            'trigger_context' => [
                'generation_plan' => array_fill(0, 20, ['family' => 'hybrid']),
            ],
        ]);
        $this->bindPopulation(paused: false, expectBuild: false);
        $population = app(LabPopulationService::class);
        $population->shouldReceive('continueInterruptedConstruction')->once()->with($generation->id, 4)->andReturn([
            'status' => 'partial',
            'generation' => $generation,
            'created_slots' => [17, 18, 19],
            'completed_slots' => [17, 18, 19],
            'failures' => [['slot' => 20, 'reason' => 'bounded_retry_pending']],
        ]);

        $admission = m::mock(GenerationAdmissionDecisionService::class);
        $admission->shouldReceive('decide')->never();
        app()->instance(GenerationAdmissionDecisionService::class, $admission);
        app()->forgetInstance(LabLifecycleOrchestrator::class);

        $result = app(LabLifecycleOrchestrator::class)->run('XAUUSD', 'H1', 'tc-incomplete-before-learning');

        $this->assertSame('paused', $result['status']);
        $this->assertSame('GENERATION_CONSTRUCTION_IN_PROGRESS', data_get($result, 'data.generation_outcome.reason_code'));
        $this->assertSame($generation->id, data_get($result, 'data.generation_outcome.generation_id'));
        $this->assertCount(1, LabGeneration::all());
    }

    public function test_older_incomplete_quarantine_cannot_hide_the_newer_active_generation(): void
    {
        $lab = $this->seedLaboratory();
        $older = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 205,
            'status' => 'technical_quarantine',
            'population_size' => 10,
            'data_fingerprint' => 'superseded-partial-constructor',
            'trigger_type' => 'candidate_handoff',
            'trigger_context' => [
                'generation_plan' => array_fill(0, 20, ['family' => 'hybrid']),
            ],
        ]);
        $active = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 206,
            'status' => 'queued',
            'population_size' => 20,
            'data_fingerprint' => 'active-successor',
            'trigger_type' => 'candidate_handoff',
            // A legacy empty plan is dispatchable and keeps this fixture
            // focused on lineage-head selection rather than construction.
            'trigger_context' => [],
        ]);
        $this->bindPopulation(paused: false, expectBuild: false);
        app(LabPopulationService::class)->shouldReceive('continueInterruptedConstruction')->never();

        $result = app(LabLifecycleOrchestrator::class)->run('XAUUSD', 'H1', 'tc-newer-active-wins');

        $this->assertSame('completed', $result['status']);
        $this->assertSame($active->id, data_get($result, 'data.generation_id'));
        $this->assertSame('technical_quarantine', $older->fresh()->status);
        $this->assertCount(2, LabGeneration::all());
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
                'dispatch_key' => 'recovery:dojo:'.$seat,
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

    public function test_normal_replays_do_not_consume_the_learning_recovery_budget(): void
    {
        $this->seedLaboratory();
        config(['services.lifecycle_orchestrator.max_recovery_dispatch_per_day' => 2]);
        foreach ([1, 2] as $seat) {
            LabLearningLaneDispatch::create([
                'dispatch_key' => 'normal-full-replay-'.$seat,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'strategy_family' => 'regime', 'status' => 'completed',
                'stage' => 'full_replay', 'micro_status' => 'research_admitted',
                'selected_at' => now(), 'completed_at' => now(),
            ]);
        }
        $this->bindPopulation($paused = true, pendingDojo: 2);

        $result = app(LabLifecycleOrchestrator::class)->run('XAUUSD', 'H1', 'tc-003-normal-budget');

        $this->assertSame(LabLifecycleOrchestrator::PHASE_LEARNING_RECOVERY, $result['stage']);
        $this->assertNotSame('daily_recovery_budget_exhausted', data_get($result, 'data.paused_reason'));
    }

    public function test_target_aligned_lesson_opens_causal_generation_instead_of_empty_dojo_recovery(): void
    {
        $lab = $this->seedLaboratory();
        LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 214,
            'status' => 'technical_quarantine',
            'population_size' => 20,
            'data_fingerprint' => 'superseded-causal-constructor',
            'trigger_type' => 'learning_confirmation',
            'trigger_context' => [],
        ]);
        $this->bindPopulation(paused: false, expectedTrigger: 'learning_confirmation');

        $admission = m::mock(GenerationAdmissionDecisionService::class);
        $admission->shouldReceive('decide')->once()->andReturn([
            'decision' => GenerationAdmissionDecisionService::DISPATCH_LEARNING,
            'allowed' => false,
            'reason_codes' => ['TARGET_ALIGNED_CAUSAL_LESSON_HAS_GENERATION_PRIORITY'],
            'causal_confirmation_priority' => [
                'lesson_id' => 2387,
                'target' => 'regime_coverage',
                'gene_key' => 'trend_down_roc_threshold',
                'promotion_evidence' => false,
            ],
            'learning_velocity' => [
                'learning_starvation' => ['actionable_pending_dojo' => 0],
            ],
        ]);
        app()->instance(GenerationAdmissionDecisionService::class, $admission);
        app()->forgetInstance(LabLifecycleOrchestrator::class);

        $result = app(LabLifecycleOrchestrator::class)->run('XAUUSD', 'H1', 'tc-causal-generation-routing');

        $this->assertSame('completed', $result['status'], json_encode($result, JSON_PRETTY_PRINT));
        $this->assertSame('learning_confirmation', LabGeneration::query()->latest('generation')->value('trigger_type'));
        $this->assertSame(LabLifecycleOrchestrator::PHASE_FORWARD, $result['stage']);
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

    public function test_exact_retry_budget_exhaustion_uses_one_shot_autonomous_recovery(): void
    {
        $lab = $this->seedLaboratory();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 94,
            'status' => 'screening',
            'population_size' => 1,
            'data_fingerprint' => 'g94-frozen',
            'trigger_type' => 'learning_confirmation',
            'trigger_context' => [],
        ]);
        $model = ModelVersion::create([
            'name' => 'retry-budget-agent',
            'strategy' => 'regime',
            'version' => 'v94',
            'generation' => 94,
            'status' => 'testing',
            'parameters' => [],
            'metadata' => [],
        ]);
        LabAgent::create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'regime',
            'origin' => 'test',
            'lifecycle_status' => 'evaluation_error',
            'parameter_diff' => [],
            'decision_reason' => 'Bounded screening batch exhausted operational retries; strategy verdict withheld.',
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
            technicalRepairMode: 'retry_budget',
        );

        $result = app(LabLifecycleOrchestrator::class)->run('XAUUSD', 'H1', 'tc-retry-budget-recovery');

        $this->assertSame(LabLifecycleOrchestrator::PHASE_TECHNICAL_RECOVERY, $result['stage']);
        $this->assertSame(2, data_get($result, 'data.dispatched'));
    }

    public function test_runtime_outage_fails_closed(): void
    {
        $this->seedLaboratory();
        $this->bindPopulation($paused = false, expectBuild: false);
        // Both the protected probe and its narrow local fallback are down.
        config(['services.internal_api.token' => '']);
        Http::fake([
            '*/api/replay-status' => Http::response([], 503),
            '*/health' => Http::response([], 503),
        ]);

        $orchestrator = app(LabLifecycleOrchestrator::class);
        $result = $orchestrator->run('XAUUSD', 'H1', 'tc-004');

        $this->assertSame('blocked', $result['status']);
        $this->assertStringContainsStringIgnoringCase('runtime', (string) $result['summary']);
        $this->assertCount(0, LabGeneration::all());
    }

    public function test_active_replay_is_runtime_liveness_not_an_outage(): void
    {
        $this->seedLaboratory();
        $this->bindPopulation($paused = false);
        Http::fake([
            '*/api/replay-status' => Http::response([
                'protocol' => 'replay_liveness_probe_v1',
                'active_requests' => 1,
                'screening_active' => 1,
                'screening_capacity' => 1,
            ], 200),
        ]);

        $result = app(LabLifecycleOrchestrator::class)->run('XAUUSD', 'H1', 'tc-active-replay');

        $this->assertSame('completed', $result['status'], json_encode($result, JSON_PRETTY_PRINT));
        $this->assertCount(1, LabGeneration::all());
    }

    public function test_durably_contained_failed_evaluation_does_not_deadlock_its_own_recovery(): void
    {
        config(['services.lifecycle_orchestrator.max_failed_jobs' => 1]);
        $lab = $this->seedLaboratory();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 212,
            'status' => 'screening',
            'population_size' => 20,
            'trigger_type' => 'shadow_research',
            'trigger_context' => [],
        ]);
        $model = ModelVersion::create([
            'name' => 'contained-failed-evaluation',
            'strategy' => 'hybrid',
            'version' => 'v212',
            'generation' => 212,
            'status' => 'testing',
            'parameters' => [],
            'metadata' => [],
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'origin' => 'test',
            'lifecycle_status' => 'evaluation_error',
            'parameter_diff' => [],
            'decision_reason' => 'Screen queue technical error; strategy verdict withheld.',
        ]);
        LabEvaluationRun::create([
            'run_id' => 'contained-failed-evaluation-run',
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $model->id,
            'phase' => 'screening',
            'mode' => 'screen',
            'status' => 'technical_error',
            'error_class' => 'Illuminate\\Queue\\MaxAttemptsExceededException',
            'error_message' => 'EvaluateLabAgentJob has been attempted too many times.',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
        $command = 'O:28:"App\\Jobs\\EvaluateLabAgentJob":1:{s:10:"labAgentId";i:'.$agent->id.';}';
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'lab-screening',
            'payload' => json_encode([
                'displayName' => EvaluateLabAgentJob::class,
                'data' => ['command' => $command],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'bounded failure fixture',
            'failed_at' => now(),
        ]);

        $method = new \ReflectionMethod(LabLifecycleOrchestrator::class, 'queueRisk');
        $method->setAccessible(true);
        $snapshot = ['rows' => [], 'stats' => []];

        $this->assertNull($method->invoke(
            app(LabLifecycleOrchestrator::class),
            $snapshot,
            'XAUUSD',
            'H1',
        ));

        $agent->update(['lifecycle_status' => 'screened']);
        LabEvaluationRun::create([
            'run_id' => 'contained-failed-evaluation-recovery-run',
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $model->id,
            'phase' => 'screening',
            'mode' => 'screen',
            'status' => 'completed',
            'started_at' => now()->addSecond(),
            'finished_at' => now()->addSeconds(2),
        ]);

        $this->assertNull($method->invoke(
            app(LabLifecycleOrchestrator::class),
            $snapshot,
            'XAUUSD',
            'H1',
        ), 'A later completed immutable run must supersede the retained failed queue row.');

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'lab-screening',
            'payload' => json_encode(['displayName' => 'UnknownCanonicalJob'], JSON_THROW_ON_ERROR),
            'exception' => 'uncontained failure fixture',
            'failed_at' => now(),
        ]);

        $this->assertSame('queue_failed_jobs', $method->invoke(
            app(LabLifecycleOrchestrator::class),
            $snapshot,
            'XAUUSD',
            'H1',
        ));
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
        $logger = new LabLifecycleErrorLogger;
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

    private function bindPopulation(bool $paused = false, int $pendingDojo = 0, bool $throwOnBuild = false, bool $expectBuild = true, ?string $velocityStatus = null, string $technicalRepairMode = 'timeout_budget', string $expectedTrigger = 'new_data'): void
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
        $velocity->shouldReceive('summary')->zeroOrMoreTimes()->andReturn([
            'allowed' => ! $paused,
            'status' => $paused ? $velocityStatus : 'healthy',
            'learning_starvation' => ['actionable_pending_dojo' => $pendingDojo],
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
        $population->shouldReceive('constructorIsActive')->zeroOrMoreTimes()->andReturnTrue()->byDefault();
        $population->shouldReceive('lastBuildOutcome')->zeroOrMoreTimes()->andReturn([
            'status' => 'created',
            'reason_code' => 'GENERATION_CREATED',
            'retryable' => false,
        ]);
        if ($throwOnBuild) {
            $population->shouldReceive('build')->andThrow(new \RuntimeException('Simulated build failure', 500));
        } elseif (! $paused && $expectBuild) {
            $laboratoryId = (int) AiLaboratory::where('symbol', 'XAUUSD')->value('id');
            $population->shouldReceive('build')
                ->zeroOrMoreTimes()
                ->with('XAUUSD', $expectedTrigger, false, 'H1')
                ->andReturnUsing(function () use ($laboratoryId, $expectedTrigger): LabGeneration {
                    return LabGeneration::create([
                        'ai_laboratory_id' => $laboratoryId,
                        'generation' => 9999, 'status' => 'draft',
                        'population_size' => 20, 'data_fingerprint' => 'test',
                        'trigger_type' => $expectedTrigger, 'trigger_context' => [],
                    ]);
                });
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
                    && ($arguments[$technicalRepairMode === 'retry_budget'
                        ? '--after-retry-budget-repair'
                        : '--after-timeout-budget-repair'] ?? false) === true
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
    {
        $dir = storage_path('logs/neurotrader/lifecycle-errors');
        if (! is_dir($dir)) {
            return false;
        }
        foreach (File::allFiles($dir) as $f) {
            if (filesize($f->getRealPath()) > 0) {
                return true;
            }
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
