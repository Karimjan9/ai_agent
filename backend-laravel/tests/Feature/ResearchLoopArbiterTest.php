<?php

namespace Tests\Feature;

use App\Jobs\RunScheduledArtisanCommandJob;
use App\Jobs\SealGenerationAutonomyReceiptJob;
use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningLesson;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\CandidateHandoffEvent;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentWorkItem;
use App\Models\ResearchLoopDecision;
use App\Models\Symbol;
use App\Services\AutonomousLearningProgressDirectorService;
use App\Services\AutonomousModeService;
use App\Services\CausalLearningCohortPlannerService;
use App\Services\CausalLearningCohortService;
use App\Services\GenerationAutonomyReceiptService;
use App\Services\LabDataEdgeAuditService;
use App\Services\LearningLaneService;
use App\Services\LearningVelocityGateService;
use App\Services\MarketDriftDetectionService;
use App\Services\MtfResearchCohortService;
use App\Services\ResearchClosureInvariantService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchLoopArbiterService;
use App\Services\ScheduledCommandOutcomeClassifierService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ResearchLoopArbiterTest extends TestCase
{
    use RefreshDatabase;

    public function test_fidelity_annotations_do_not_replace_readiness_or_authorize_independent_replay(): void
    {
        $owner = app(ResearchLoopArbiterService::class);
        $this->assertNull($owner->fidelityPlanForAction('SETTLE_EXISTING_GENERATION', 'XAUUSD', 'H1', []));
        $independent = $owner->fidelityPlanForAction('EDGE_INDEPENDENT_REPLICATION', 'XAUUSD', 'H1', []);
        $this->assertSame('independent_validation', $independent['kind']);
        $this->assertSame('blocked_dependency', $independent['status']);
        $this->assertFalse($independent['independence_attested']);
        $this->assertTrue($independent['executor_admission_unchanged']);
        $this->assertFalse($independent['promotion_evidence']);
    }

    public function test_existing_dataset_wait_records_actual_source_dependency_without_inventing_measurement_value(): void
    {
        Queue::fake(); $this->lab();
        $owner = app(ResearchLoopArbiterService::class);
        $choose = new \ReflectionMethod($owner, 'decide');
        $source = ['protocol' => 'immutable_historical_candle_gap_dependency_v1', 'run_id' => 'original-run',
            'dataset_hash' => str_repeat('a', 64), 'response_artifact_hash' => str_repeat('b', 64)];
        $decision = $choose->invokeArgs($owner, ['XAUUSD', 'H1', 'WAIT_DATASET_CONTINUITY', 89,
            null, [], null, ['GENERATION_MTF_M5_KNOWN_CANDLE_GAP'], ['data_readiness' => ['allowed' => false,
                'source_dependency' => $source, 'primary_stream_sha256' => str_repeat('c', 64)]], false]);
        $proposal = $decision['evidence_snapshot']['measurement_acquisition_proposal'];
        $this->assertSame('deferred', $decision['status']);
        $this->assertNull($decision['command']);
        $this->assertSame(89, $decision['priority']);
        $this->assertSame($source, $proposal['source_evidence']);
        $this->assertNull($proposal['value_of_measurement']);
        $this->assertSame([], $proposal['hypotheses']);
        $this->assertFalse($proposal['absence_is_market_closure_proof']);
        $this->assertFalse($proposal['paid_api_calls_authorized']);
        $this->assertFalse($proposal['promotion_evidence']);
        $this->assertSame($proposal, ResearchLoopDecision::query()->sole()->evidence_snapshot['measurement_acquisition_proposal']);
        Queue::assertNothingPushed();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // These cases exercise the existing post-champion/live scheduling
        // policy. Archive-first coverage lives in HistoricalResearchAdmissionTest.
        config()->set('services.xauusd_organism.historical_research_until_champion', false);
    }

    public function test_academy_pending_intent_dispatches_same_draft_before_generic_lifecycle(): void
    {
        Queue::fake();
        $generation = LabGeneration::create(['ai_laboratory_id' => $this->lab()->id, 'generation' => 1,
            'trigger_type' => 'academy_experiment', 'status' => 'draft', 'population_size' => 20, 'trigger_context' => []]);
        $this->mock(\App\Services\AcademyExperimentMaterializerService::class, function ($mock) use ($generation): void {
            $mock->shouldReceive('proposal')->andReturn(['status' => 'pending_canonical_admission', 'trial_id' => 7,
                'generation_id' => $generation->id, 'baseline_model_version_id' => 3, 'identity' => ['data_hash' => str_repeat('a', 64)]]);
        });
        $result = app(ResearchLoopArbiterService::class)->tick();
        $this->assertSame('DISPATCH_ACADEMY_EXPERIMENT', $result['action']);
        $this->assertSame('trading:admit-academy-experiment', $result['command']);
        $this->assertSame(['trial' => 7], $result['arguments']);
        $this->assertSame(1, LabGeneration::count());
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
    }

    public function test_academy_outbox_publication_gap_has_two_bounded_transport_retries(): void
    {
        Queue::fake();
        $this->lab();
        $arbiter = app(ResearchLoopArbiterService::class);
        $choose = new \ReflectionMethod($arbiter, 'decide');
        $args = ['XAUUSD', 'H1', 'OPEN_ACADEMY_EXPERIMENT', 81, 'trading:admit-academy-experiment',
            [0 => 7], 'scheduler-constructor', ['READY'], ['academy_proposal' => ['trial_id' => 7]], false];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $result = $choose->invokeArgs($arbiter, $args);
            $this->assertSame('dispatched', $result['status']);
            (new UniqueLock(Cache::store()))->release(new RunScheduledArtisanCommandJob($result['command'],
                $result['arguments'], $result['queue'], $result['decision_id']));
        }
        $this->assertSame('duplicate_suppressed', $choose->invokeArgs($arbiter, $args)['status']);
        $this->assertSame(3, ResearchLoopDecision::count());
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 3);
    }

    public function test_academy_child_refusal_is_not_a_completed_transition(): void
    {
        $classifier = app(ScheduledCommandOutcomeClassifierService::class);
        $this->assertSame('deferred', $classifier->classify('trading:admit-academy-experiment', [], 0, '{"status":"blocked"}')['status']);
        $this->assertSame('completed', $classifier->classify('trading:admit-academy-experiment', [], 0, '{"status":"prepared"}')['status']);
        $this->assertSame('completed', $classifier->classify('trading:admit-academy-experiment', [], 0, '{"status":"admitted"}')['status']);
        $this->assertSame(0, Artisan::call('trading:admit-academy-experiment', ['trial' => 99]));
        $this->assertSame('blocked', json_decode(Artisan::output(), true)['status']);
    }

    public function test_operator_pause_fences_arbiter_and_resume_keeps_the_existing_generation(): void
    {
        Queue::fake();
        $lab = $this->lab();
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'new_data', 'status' => 'screening', 'population_size' => 20,
            'trigger_context' => [], 'started_at' => now()]);

        $this->assertSame(0, Artisan::call('ai:pause', ['--json' => true]));
        $paused = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('paused', $paused['state']);
        $this->assertFalse($paused['enabled']);
        $this->assertSame(0, Artisan::call('ai:runtime-gate', ['--json' => true]));
        $this->assertFalse(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['start_runtime']);
        $this->assertSame('RESEARCH_RUN_PAUSED', app(ResearchLoopArbiterService::class)->tick()['reason']);
        $this->assertDatabaseCount('research_loop_decisions', 0);
        Queue::assertNothingPushed();

        $queuedBeforePause = new RunScheduledArtisanCommandJob(
            'trading:run-lifecycle-cycle', ['--symbol' => 'XAUUSD'], 'scheduler-constructor',
        );
        $queuedBeforePause->handle(app(ScheduledCommandOutcomeClassifierService::class));
        $this->assertSame('deferred', Cache::get($queuedBeforePause->statusCacheKey())['status']);

        $this->assertSame(0, Artisan::call('ai:resume', ['--json' => true]));
        $resumed = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('running', $resumed['state']);
        $this->assertSame(0, Artisan::call('ai:runtime-gate', ['--json' => true]));
        $this->assertTrue(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['start_runtime']);
        $decision = app(ResearchLoopArbiterService::class)->tick();
        $this->assertSame('SETTLE_EXISTING_GENERATION', $decision['action']);
        $this->assertSame($generation->id, LabGeneration::query()->latest('id')->value('id'));
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
    }

    public function test_unchanged_completed_settlement_is_retried_only_twice_then_safety_blocked(): void
    {
        Queue::fake();
        $lab = $this->lab();
        LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'new_data', 'status' => 'screening', 'population_size' => 20,
            'trigger_context' => [], 'started_at' => now()]);
        $arbiter = app(ResearchLoopArbiterService::class);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $decision = $arbiter->tick();
            $this->assertSame('dispatched', $decision['status']);
            ResearchLoopDecision::findOrFail($decision['decision_id'])->update([
                'status' => 'completed', 'completed_at' => now(),
            ]);
            (new UniqueLock(Cache::store()))->release(new RunScheduledArtisanCommandJob(
                (string) $decision['command'], (array) $decision['arguments'],
                (string) $decision['queue'], (int) $decision['decision_id'],
            ));
            $this->assertSame('duplicate_suppressed', $arbiter->tick()['status']);
            $this->travel(6)->minutes();
        }

        $blocked = $arbiter->tick();
        $this->assertSame('safety_blocked', $blocked['status']);
        $this->assertContains('UNCHANGED_GENERATION_AFTER_BOUNDED_SETTLEMENT_RETRIES', $blocked['reason_codes']);
        $this->assertSame('safety_halt', app(AutonomousModeService::class)->status()['state']);
        $this->assertSame('RESEARCH_RUN_SAFETY_HALT', $arbiter->tick()['reason']);
        $this->assertSame(0, Artisan::call('ai:runtime-gate', ['--json' => true]));
        $this->assertFalse(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['start_runtime']);
        $this->assertDatabaseCount('research_loop_decisions', 3);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 3);
    }

    public function test_recent_immutable_replay_waits_without_spending_settlement_retries_then_stale_run_recovers(): void
    {
        Queue::fake();
        $lab = $this->lab();
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'learning_confirmation', 'status' => 'screening', 'population_size' => 3,
            'trigger_context' => [], 'started_at' => now()]);
        $model = ModelVersion::create(['name' => 'bounded-active-replay', 'strategy' => 'hybrid',
            'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'screening', 'parameter_diff' => []]);
        LabEvaluationRun::create(['run_id' => 'bounded-active-replay-run',
            'lab_generation_id' => $generation->id, 'lab_agent_id' => $agent->id,
            'model_version_id' => $model->id, 'phase' => 'screening', 'mode' => 'screen',
            'status' => 'started', 'started_at' => now()]);

        $arbiter = app(ResearchLoopArbiterService::class);
        $first = $arbiter->tick();
        $this->assertSame('WAIT_EXISTING_GENERATION_REPLAY', $first['action']);
        Queue::assertNothingPushed();
        $this->travel(18)->minutes();
        $this->assertSame('duplicate_suppressed', $arbiter->tick()['status']);
        $this->assertSame('running', app(AutonomousModeService::class)->status()['state']);
        $this->assertDatabaseCount('research_loop_decisions', 1);

        $this->travel(58)->minutes();
        $this->assertSame('SETTLE_EXISTING_GENERATION', $arbiter->tick()['action']);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
    }

    public function test_stop_denies_new_work_but_an_active_generation_is_drained_first(): void
    {
        Queue::fake();
        $lab = $this->lab();
        LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'new_data', 'status' => 'screening', 'population_size' => 20,
            'trigger_context' => [], 'started_at' => now()]);
        app(AutonomousModeService::class)->stop('XAUUSD', 'H1', 'test', 'drain');

        $result = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame('SETTLE_EXISTING_GENERATION', $result['action']);
        $this->assertSame('dispatched', $result['status']);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class,
            fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:run-lifecycle-cycle');
        $this->assertDatabaseCount('research_loop_decisions', 1);
    }

    public function test_dispatched_arbiter_decision_is_closed_by_its_child_command_outcome(): void
    {
        Queue::fake();
        $lab = $this->lab();
        LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'new_data', 'status' => 'screening', 'population_size' => 20,
            'trigger_context' => [], 'started_at' => now()]);
        app(AutonomousModeService::class)->stop('XAUUSD', 'H1', 'test', 'drain');

        $result = app(ResearchLoopArbiterService::class)->tick();
        $decision = ResearchLoopDecision::query()->findOrFail($result['decision_id']);
        /** @var RunScheduledArtisanCommandJob $job */
        $job = Queue::pushed(RunScheduledArtisanCommandJob::class)->first();
        $this->assertSame($decision->id, $job->researchLoopDecisionId);
        Artisan::shouldReceive('call')->once()->with($job->command, $job->arguments)->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('generation drained');

        $job->handle(app(ScheduledCommandOutcomeClassifierService::class));

        $decision->refresh();
        $this->assertSame('completed', $decision->status);
        $this->assertNotNull($decision->completed_at);
    }

    public function test_exit_zero_lifecycle_pause_is_deferred_not_a_completed_transition(): void
    {
        $result = app(ScheduledCommandOutcomeClassifierService::class)->classify(
            'trading:run-lifecycle-cycle',
            ['--symbol' => 'XAUUSD', '--learning-confirmation' => true, '--json' => true],
            0,
            json_encode([
                'status' => 'paused',
                'stage' => 'technical_recovery',
                'summary' => 'RECOVER_TECHNICAL',
                'data' => ['dispatched' => 0],
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertSame('deferred', $result['status']);
        $this->assertFalse($result['throw']);
        $this->assertSame('lifecycle_transition_not_achieved', $result['reason']);
    }

    public function test_exit_zero_technical_recovery_dispatch_does_not_complete_successor_decision(): void
    {
        $result = app(ScheduledCommandOutcomeClassifierService::class)->classify(
            'trading:run-lifecycle-cycle',
            ['--symbol' => 'XAUUSD', '--learning-confirmation' => true, '--json' => true],
            0,
            json_encode([
                'status' => 'running',
                'stage' => 'technical_recovery',
                'summary' => 'RECOVER_TECHNICAL',
                'data' => ['dispatched' => 1, 'generation' => 231],
            ], JSON_THROW_ON_ERROR),
        );

        $this->assertSame('deferred', $result['status']);
        $this->assertFalse($result['throw']);
        $this->assertSame('lifecycle_transition_not_achieved', $result['reason']);
    }

    public function test_exit_zero_generation_admission_refusal_is_not_a_completed_writer(): void
    {
        $result = app(ScheduledCommandOutcomeClassifierService::class)->classify(
            'trading:lab-generation',
            ['--trigger' => 'new_data'],
            0,
            'XAUUSD H1: generation blocked (GENERATION_ADMISSION_RECOVER_TECHNICAL); retryable=yes.',
        );

        $this->assertSame('safety_blocked', $result['status']);
        $this->assertSame('generation_admission_withheld', $result['reason']);
        $this->assertFalse($result['throw']);
    }

    public function test_terminal_technical_recovery_preempts_a_new_data_writer(): void
    {
        Queue::fake();
        $lab = $this->lab();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 235,
            'trigger_type' => 'data_edge_audit', 'status' => 'technical_quarantine',
            'population_size' => 20, 'trigger_context' => [], 'completed_at' => now(),
        ]);
        $model = ModelVersion::create([
            'name' => 'timed-out-control', 'strategy' => 'trend', 'version' => 'v1',
            'generation' => 235, 'status' => 'testing', 'parameters' => [], 'metadata' => [],
        ]);
        LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'origin' => 'test', 'lifecycle_status' => 'technical_quarantine',
            'parameter_diff' => [], 'decision_reason' => 'Bounded AI replay exceeded 780s; strategy verdict withheld.',
        ]);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'running');
        config()->set('services.lifecycle_orchestrator.autonomous_technical_recovery_enabled', true);
        $velocity = Mockery::mock(LearningVelocityGateService::class);
        $velocity->shouldReceive('inspect')->once()->andReturn([
            'status' => 'blocked_technical_recovery', 'technical_recovery_agents' => 1,
        ]);
        $this->app->instance(LearningVelocityGateService::class, $velocity);

        $result = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame('RECOVER_LATEST_TECHNICAL_EVIDENCE', $result['action']);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class,
            fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:run-lifecycle-cycle'
                && ($job->arguments['--symbol'] ?? null) === 'XAUUSD');
        Queue::assertNotPushed(RunScheduledArtisanCommandJob::class,
            fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:lab-generation');
    }

    public function test_completed_child_schedules_receipt_only_retry_when_autonomy_seal_throws(): void
    {
        Queue::fake();
        $decision = ResearchLoopDecision::create([
            'decision_key' => hash('sha256', 'receipt-retry'),
            'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'action' => 'RUN_NORMAL_TWENTY_SEAT_LIFECYCLE',
            'status' => 'dispatched', 'priority' => 60,
            'evidence_hash' => hash('sha256', 'receipt-retry-evidence'),
            'command' => 'trading:run-lifecycle-cycle', 'queue' => 'scheduler-constructor',
            'arguments' => ['--symbol' => 'XAUUSD', '--json' => true],
            'reason_codes' => ['TEST'], 'evidence_snapshot' => [],
            'contract' => [
                'protocol' => ResearchLoopArbiterService::PROTOCOL,
                'owner' => ResearchLoopArbiterService::OWNER,
                'selection_cardinality' => 1,
            ],
            'dispatched_at' => now(),
        ]);
        $job = new RunScheduledArtisanCommandJob(
            (string) $decision->command,
            (array) $decision->arguments,
            (string) $decision->queue,
            (int) $decision->id,
        );
        Artisan::shouldReceive('call')->once()->with($job->command, $job->arguments)->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('generation drained');
        $receipts = Mockery::mock(GenerationAutonomyReceiptService::class);
        $receipts->shouldReceive('recordSuccessorDecision')->once()
            ->andThrow(new \RuntimeException('temporary receipt store failure'));
        $this->app->instance(GenerationAutonomyReceiptService::class, $receipts);

        $job->handle(app(ScheduledCommandOutcomeClassifierService::class));

        $this->assertSame('completed', $decision->fresh()->status);
        Queue::assertPushed(SealGenerationAutonomyReceiptJob::class,
            fn (SealGenerationAutonomyReceiptJob $retry): bool => $retry->researchLoopDecisionId === $decision->id);
        Queue::assertNotPushed(RunScheduledArtisanCommandJob::class);
    }

    public function test_stop_drains_an_incomplete_quarantined_constructor_but_not_a_terminal_quarantine(): void
    {
        Queue::fake();
        $lab = $this->lab();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'new_data',
            'status' => 'technical_quarantine',
            'population_size' => 20,
            'trigger_context' => ['generation_plan' => array_fill(0, 20, ['role' => 'candidate'])],
            'started_at' => now(),
        ]);
        app(AutonomousModeService::class)->stop('XAUUSD', 'H1', 'test', 'drain-partial-constructor');

        $partial = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame('SETTLE_EXISTING_GENERATION', $partial['action']);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class,
            fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:run-lifecycle-cycle');

        ResearchLoopDecision::query()->delete();
        $generation->update(['trigger_context' => ['generation_plan' => []]]);
        Queue::fake();
        $terminal = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame('DEFER_AUTONOMY_STOPPED', $terminal['action']);
        Queue::assertNothingPushed();
    }

    public function test_stopped_idle_loop_records_one_stable_defer_and_dispatches_nothing(): void
    {
        Queue::fake();
        $this->lab();
        app(AutonomousModeService::class)->stop('XAUUSD', 'H1', 'test', 'stopped');

        $first = app(ResearchLoopArbiterService::class)->tick();
        $second = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame('DEFER_AUTONOMY_STOPPED', $first['action']);
        $this->assertSame('duplicate_suppressed', $second['status']);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('research_loop_decisions', 1);
    }

    public function test_terminal_unsettled_hypothesis_triplet_dispatches_full_replay_before_new_work(): void
    {
        Queue::fake();
        $lab = $this->lab();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 223,
            'trigger_type' => 'learning_confirmation',
            'status' => 'completed',
            'population_size' => 20,
            'trigger_context' => [],
            'completed_at' => now(),
        ]);
        $agents = collect(['hypothesis_guided', 'blinded', 'frozen_control'])->map(function (string $role, int $index) use ($generation): LabAgent {
            $model = ModelVersion::create([
                'name' => 'causal-'.$role,
                'strategy' => 'hybrid',
                'version' => 'v'.($index + 1),
                'generation' => 223,
                'status' => 'testing',
                'parameters' => [],
                'metadata' => ['causal_learning_cohort' => ['role' => $role]],
            ]);

            return LabAgent::create([
                'lab_generation_id' => $generation->id,
                'model_version_id' => $model->id,
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'strategy_family' => 'hybrid',
                'origin' => 'causal_confirm',
                'lifecycle_status' => 'screened',
                'parameter_diff' => [],
            ]);
        });
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => hash('sha256', 'arbiter-hypothesis-replay'),
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'target' => 'profit_factor',
            'gene_key' => 'entry_threshold',
            'guided_agent_id' => $agents[0]->id,
            'blinded_agent_id' => $agents[1]->id,
            'control_agent_id' => $agents[2]->id,
            'status' => 'ready_for_replay',
            'evidence' => ['construction_validation' => ['status' => 'ready_for_replay']],
        ]);
        app(AutonomousModeService::class)->stop('XAUUSD', 'H1', 'test', 'causal-drain-first');

        $result = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame('SETTLE_CAUSAL_CONFIRMATION_REPLAY', $result['action']);
        $this->assertSame($experiment->id, data_get($result, 'evidence_snapshot.causal_experiment_id'));
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class,
            fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:dispatch-full-validation'
                && $job->lane === 'scheduler-constructor'
                && $job->arguments === [
                    0 => 'XAUUSD',
                    '--timeframe' => 'H1',
                    '--causal-experiment-id' => $experiment->id,
                ]);
    }

    public function test_latest_generation_causal_closure_outranks_historical_causal_backlog(): void
    {
        Queue::fake();
        $lab = $this->lab();
        $makeExperiment = function (int $generationNumber) use ($lab): AgentLearningCausalExperiment {
            $generation = LabGeneration::create([
                'ai_laboratory_id' => $lab->id,
                'generation' => $generationNumber,
                'trigger_type' => 'learning_confirmation',
                'status' => 'screened',
                'population_size' => 3,
                'trigger_context' => [],
                'completed_at' => now(),
            ]);
            $agents = collect(['hypothesis_guided', 'blinded', 'frozen_control'])->map(function (string $role, int $index) use ($generation, $generationNumber): LabAgent {
                $model = ModelVersion::create([
                    'name' => "causal-priority-{$generationNumber}-{$role}",
                    'strategy' => 'hybrid',
                    'version' => "v{$generationNumber}-".($index + 1),
                    'generation' => $generationNumber,
                    'status' => 'testing',
                    'parameters' => [],
                    'metadata' => ['causal_learning_cohort' => ['role' => $role]],
                ]);

                return LabAgent::create([
                    'lab_generation_id' => $generation->id,
                    'model_version_id' => $model->id,
                    'symbol' => 'XAUUSD',
                    'timeframe' => 'H1',
                    'strategy_family' => 'hybrid',
                    'origin' => 'causal_confirm',
                    'lifecycle_status' => 'screened',
                    'parameter_diff' => [],
                ]);
            });

            return AgentLearningCausalExperiment::create([
                'experiment_key' => hash('sha256', 'causal-priority-'.$generationNumber),
                'lab_generation_id' => $generation->id,
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'strategy_family' => 'hybrid',
                'target' => 'profit_factor',
                'gene_key' => 'entry_threshold',
                'guided_agent_id' => $agents[0]->id,
                'blinded_agent_id' => $agents[1]->id,
                'control_agent_id' => $agents[2]->id,
                'status' => 'ready_for_replay',
                'evidence' => ['construction_validation' => ['status' => 'ready_for_replay']],
            ]);
        };
        $historical = $makeExperiment(222);
        $current = $makeExperiment(223);

        $result = app(ResearchLoopArbiterService::class)->tick();

        $this->assertNotSame($historical->id, data_get($result, 'evidence_snapshot.causal_experiment_id'));
        $this->assertSame($current->id, data_get($result, 'evidence_snapshot.causal_experiment_id'));
        Queue::assertPushed(RunScheduledArtisanCommandJob::class,
            fn (RunScheduledArtisanCommandJob $job): bool => $job->lane === 'scheduler-constructor'
                && $job->arguments['--causal-experiment-id'] === $current->id);
    }

    public function test_terminal_causal_technical_arm_preempts_generic_active_generation_settlement(): void
    {
        Queue::fake();
        $lab = $this->lab();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 222,
            'trigger_type' => 'learning_confirmation',
            'status' => 'full_validation',
            'population_size' => 3,
            'trigger_context' => [],
            'started_at' => now()->subMinute(),
        ]);
        $agents = collect(['repair_guided', 'blinded', 'frozen_control'])->map(function (string $role, int $index) use ($generation): LabAgent {
            $model = ModelVersion::create([
                'name' => 'technical-causal-'.$role,
                'strategy' => 'hybrid',
                'version' => 'v'.($index + 1),
                'generation' => 222,
                'status' => 'testing',
                'parameters' => [],
                'metadata' => ['causal_learning_cohort' => ['role' => $role]],
            ]);

            return LabAgent::create([
                'lab_generation_id' => $generation->id,
                'model_version_id' => $model->id,
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'strategy_family' => 'hybrid',
                'origin' => 'causal_confirm',
                'lifecycle_status' => $index === 1 ? 'technical_quarantine' : 'rejected',
                'parameter_diff' => [],
            ]);
        });
        foreach ($agents as $index => $agent) {
            LabEvaluationRun::create([
                'run_id' => 'arbiter-technical-'.$agent->id,
                'lab_generation_id' => $generation->id,
                'lab_agent_id' => $agent->id,
                'model_version_id' => $agent->model_version_id,
                'phase' => 'full_validation',
                'mode' => 'replay',
                'status' => $index === 1 ? 'technical_error' : 'completed',
                'started_at' => now()->subSecond(),
                'finished_at' => now(),
            ]);
        }
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => hash('sha256', 'arbiter-technical-terminal'),
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'target' => 'profit_factor',
            'gene_key' => 'entry_threshold',
            'guided_agent_id' => $agents[0]->id,
            'blinded_agent_id' => $agents[1]->id,
            'control_agent_id' => $agents[2]->id,
            'status' => 'outcomes_pending',
            'evidence' => ['construction_validation' => ['status' => 'ready_for_replay']],
        ]);

        $result = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame('FINALIZE_CAUSAL_TECHNICAL_QUARANTINE', $result['action']);
        $this->assertSame($experiment->id, data_get($result, 'evidence_snapshot.causal_experiment_id'));
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class,
            fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:settle-causal-technical-quarantine'
                && $job->arguments === [0 => $experiment->id, '--json' => true]);
        Queue::assertNotPushed(RunScheduledArtisanCommandJob::class,
            fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:dispatch-full-validation');
    }

    public function test_owned_durable_work_is_leased_and_is_the_only_dispatched_action(): void
    {
        Queue::fake();
        $this->lab();
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'running');
        app(ResearchExperimentConversionKernelService::class)->record(
            $this->contract(), ['settlement_id' => 1], 'INCONCLUSIVE',
            ['type' => 'fixture_executable_continuation', 'identity' => 'fixture'],
        );

        $result = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame('CONSUME_DURABLE_NEXT_WORK', $result['action']);
        $this->assertSame('leased', ResearchExperimentWorkItem::query()->sole()->status);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class,
            fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:consume-research-work');
        $this->assertSame(1, ResearchLoopDecision::query()->count());
    }

    public function test_normal_lifecycle_is_fallback_and_duplicate_tick_cannot_double_dispatch(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-09-11 12:34:20 UTC');
        try {
            $this->lab();
            app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'running');
            $director = Mockery::mock(AutonomousLearningProgressDirectorService::class);
            $director->shouldReceive('advance')->twice()->andReturn([
                'protocol' => AutonomousLearningProgressDirectorService::PROTOCOL,
                'status' => 'blocked', 'reason' => 'NO_CAUSAL_ACTION_READY',
            ]);
            $cohorts = Mockery::mock(MtfResearchCohortService::class);
            $cohorts->shouldReceive('candidate')->twice()->andReturnNull();
            $drift = Mockery::mock(MarketDriftDetectionService::class);
            $drift->shouldReceive('confirmation')->twice()->andReturn(['status' => 'waiting']);
            $arbiter = new ResearchLoopArbiterService(
                app(AutonomousModeService::class),
                app(ResearchClosureInvariantService::class),
                app(ResearchExperimentConversionKernelService::class),
                app(LearningLaneService::class),
                $director,
                $cohorts,
                $drift,
                app(CausalLearningCohortService::class),
            );

            $first = $arbiter->tick();
            $second = $arbiter->tick();

            $this->assertSame('RUN_NORMAL_TWENTY_SEAT_LIFECYCLE', $first['action']);
            $this->assertSame('discovery', data_get($first, 'evidence_snapshot.research_fidelity_plan.kind'));
            $this->assertTrue(data_get($first, 'contract.existing_readiness_priority_and_executor_admission_unchanged'));
            $this->assertSame('duplicate_suppressed', $second['status']);
            Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_confirmed_market_drift_after_zero_pass_uses_fresh_data_admission(): void
    {
        Queue::fake();
        $lab = $this->lab();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'learning_confirmation', 'status' => 'completed',
            'population_size' => 1, 'trigger_context' => [], 'completed_at' => now(),
        ]);
        $model = ModelVersion::create([
            'name' => 'zero-pass-agent', 'strategy' => 'zero-pass-agent',
            'version' => 'v1', 'generation' => 1, 'status' => 'rejected',
            'parameters' => [], 'metadata' => [],
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'rejected', 'parameter_diff' => [],
        ]);
        CandidateGateDecision::create([
            'lab_agent_id' => $agent->id, 'stage' => 'screening', 'decision' => 'failed',
            'reason_codes' => ['FAILED_PROFIT_FACTOR'], 'metrics' => [], 'evaluated_at' => now(),
        ]);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'running');
        $director = Mockery::mock(AutonomousLearningProgressDirectorService::class);
        $director->shouldReceive('advance')->once()->andReturn(['action' => 'WAIT']);
        $cohorts = Mockery::mock(MtfResearchCohortService::class);
        $cohorts->shouldReceive('candidate')->never();
        $drift = Mockery::mock(MarketDriftDetectionService::class);
        $drift->shouldReceive('confirmation')->once()->andReturn([
            'protocol' => 'canonical_drift_confirmation_v1', 'status' => 'confirmed',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'latest_snapshot_id' => 9001,
            'latest_cutoff_at' => now()->utc()->toIso8601String(), 'distinct_data_hashes' => 3,
            'monotonic_cutoffs' => true, 'promotion_evidence' => false,
        ]);
        $arbiter = new ResearchLoopArbiterService(
            app(AutonomousModeService::class),
            app(ResearchClosureInvariantService::class),
            app(ResearchExperimentConversionKernelService::class),
            app(LearningLaneService::class),
            $director,
            $cohorts,
            $drift,
            app(CausalLearningCohortService::class),
        );

        $result = $arbiter->tick();

        $this->assertSame('ACCUMULATE_FRESH_DATA_AFTER_ZERO_PASS', $result['action']);
        $this->assertContains('ZERO_PASS_COHORT_REQUIRES_FRESH_DATA_ADMISSION', $result['reason_codes']);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:lab-generation'
            && ($job->arguments['--trigger'] ?? null) === 'new_data'
            && ($job->arguments['--timeframe'] ?? null) === 'H1');
        Queue::assertNotPushed(RunScheduledArtisanCommandJob::class, fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:lab-generation'
            && ($job->arguments['--trigger'] ?? null) === 'market_drift');
    }

    public function test_final_zero_pass_report_routes_through_lifecycle_audit_then_reselects_data_edge_root(): void
    {
        Queue::fake();
        $lab = $this->lab();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'learning_confirmation',
            'status' => 'completed',
            'population_size' => 1,
            'trigger_context' => [
                'latest_generation_report' => [
                    'protocol' => 'lab_generation_report_v1',
                    'report_state' => 'FINAL',
                    'next_action' => 'data_edge_audit_required',
                    'gate_failures' => ['FAILED_PROFIT_FACTOR' => 1],
                    'kpis' => [
                        'technical_completion_rate' => 100,
                        'pipeline_failure_count' => 0,
                        'screen_pass_rate' => 0,
                    ],
                ],
            ],
            'completed_at' => now(),
        ]);
        $model = ModelVersion::create([
            'name' => 'auditable-zero-pass-agent', 'strategy' => 'auditable-zero-pass-agent',
            'version' => 'v1', 'generation' => 1, 'status' => 'rejected',
            'parameters' => [], 'metadata' => [],
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'rejected', 'parameter_diff' => [],
        ]);
        CandidateGateDecision::create([
            'lab_agent_id' => $agent->id, 'stage' => 'screening', 'decision' => 'failed',
            'reason_codes' => ['FAILED_PROFIT_FACTOR'], 'metrics' => [], 'evaluated_at' => now(),
        ]);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'running');
        $director = Mockery::mock(AutonomousLearningProgressDirectorService::class);
        $director->shouldReceive('advance')->twice()->andReturn(['action' => 'WAIT']);
        $cohorts = Mockery::mock(MtfResearchCohortService::class);
        $cohorts->shouldReceive('candidate')->never();
        $drift = Mockery::mock(MarketDriftDetectionService::class);
        $drift->shouldReceive('confirmation')->twice()->andReturn([
            'protocol' => 'canonical_drift_confirmation_v1', 'status' => 'confirmed',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'latest_snapshot_id' => 9002,
            'latest_cutoff_at' => now()->utc()->toIso8601String(), 'distinct_data_hashes' => 3,
            'monotonic_cutoffs' => true, 'promotion_evidence' => false,
        ]);
        $arbiter = new ResearchLoopArbiterService(
            app(AutonomousModeService::class),
            app(ResearchClosureInvariantService::class),
            app(ResearchExperimentConversionKernelService::class),
            app(LearningLaneService::class),
            $director,
            $cohorts,
            $drift,
            app(CausalLearningCohortService::class),
        );

        $auditSelection = $arbiter->tick();
        $this->assertSame('RECORD_AUTONOMOUS_DATA_EDGE_AUDIT', $auditSelection['action']);
        $this->assertContains('AUDIT_MUST_BE_RECORDED_BY_LIFECYCLE_OWNER', $auditSelection['reason_codes']);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:run-lifecycle-cycle'
            && ($job->arguments['--symbol'] ?? null) === 'XAUUSD'
            && ($job->arguments['--json'] ?? false) === true);

        $audit = app(LabDataEdgeAuditService::class)->recordFromFinalReport($generation);
        $this->assertSame('recorded', $audit['status']);
        $this->assertFalse((bool) data_get($audit, 'audit.promotion_evidence'));

        $rootSelection = $arbiter->tick();
        $this->assertSame('ACCUMULATE_FRESH_DATA_AFTER_ZERO_PASS', $rootSelection['action']);
        $this->assertNotSame($auditSelection['decision_id'], $rootSelection['decision_id']);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:lab-generation'
            && ($job->arguments['--trigger'] ?? null) === 'new_data');
    }

    public function test_next_minute_suppresses_the_same_unchanged_state_decision(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-09-11 12:34:20 UTC');
        try {
            $lab = $this->lab();
            LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
                'trigger_type' => 'new_data', 'status' => 'screening', 'population_size' => 20,
                'trigger_context' => [], 'started_at' => now()]);
            app(AutonomousModeService::class)->stop('XAUUSD', 'H1', 'test', 'drain');

            $first = app(ResearchLoopArbiterService::class)->tick();
            DB::table('jobs')->insert([
                'queue' => 'scheduler-constructor',
                'payload' => json_encode(['displayName' => RunScheduledArtisanCommandJob::class]),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
            CarbonImmutable::setTestNow('2026-09-11 12:35:20 UTC');
            $second = app(ResearchLoopArbiterService::class)->tick();

            $this->assertSame('dispatched', $first['status']);
            $this->assertSame('duplicate_suppressed', $second['status']);
            $this->assertSame($first['decision_id'], $second['decision_id']);
            $this->assertSame($first['state_hash'], $second['state_hash']);
            $this->assertDatabaseCount('research_loop_decisions', 1);
            Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_inherited_data_edge_audit_does_not_suppress_a_new_final_report_audit(): void
    {
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $this->lab()->id,
            'generation' => 235,
            'trigger_type' => 'data_edge_audit',
            'status' => 'screened',
            'population_size' => 20,
            'trigger_context' => [
                'data_edge_audit' => ['protocol' => 'data_edge_audit_v1', 'generation' => 234],
                'latest_generation_report' => [
                    'protocol' => 'lab_generation_report_v1',
                    'report_state' => 'FINAL',
                    'next_action' => 'data_edge_audit_required',
                    'kpis' => ['technical_completion_rate' => 100, 'pipeline_failure_count' => 0],
                ],
            ],
        ]);
        $method = new \ReflectionMethod(ResearchLoopArbiterService::class, 'latestCanAutonomouslyRecordDataEdgeAudit');

        $this->assertTrue($method->invoke(app(ResearchLoopArbiterService::class), $generation));
    }

    public function test_frozen_recovery_terminal_disposition_changes_successor_state_watermark(): void
    {
        $lab = $this->lab();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 231,
            'trigger_type' => 'learning_confirmation', 'status' => 'technical_quarantine',
            'population_size' => 1, 'trigger_context' => [], 'completed_at' => now(),
        ]);
        $model = ModelVersion::create([
            'name' => 'frozen-recovery-agent', 'strategy' => 'hybrid',
            'version' => 'v1', 'generation' => 231, 'status' => 'testing',
            'parameters' => [], 'metadata' => [],
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'causal_confirm', 'lifecycle_status' => 'technical_quarantine',
            'parameter_diff' => [],
        ]);
        $arbiter = app(ResearchLoopArbiterService::class);
        $snapshot = new \ReflectionMethod($arbiter, 'operationalStateSnapshot');
        $evidence = ['generation' => ['id' => $generation->id]];

        $before = $snapshot->invoke($arbiter, $evidence, 'scheduler-constructor');
        $model->update(['metadata' => ['technical_recovery_terminal_disposition' => [
            'protocol' => 'frozen_recovery_contract_terminal_v1',
            'reason_code' => 'FROZEN_RECOVERY_CONTRACT_UNAVAILABLE',
        ]]]);
        $after = $snapshot->invoke($arbiter, $evidence, 'scheduler-constructor');

        $this->assertSame([], $before['terminal_recovery_agent_ids']);
        $this->assertSame([$agent->id], $after['terminal_recovery_agent_ids']);
        $this->assertSame($before['terminal_agent_count'], $after['terminal_agent_count']);
        $this->assertSame($after, $snapshot->invoke($arbiter, $evidence, 'scheduler-constructor'));
    }

    public function test_fresh_data_retry_watermark_changes_only_after_admission_window_is_met(): void
    {
        $lab = $this->lab();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 234,
            'trigger_type' => 'learning_confirmation',
            'status' => 'completed',
            'population_size' => 20,
            'trigger_context' => ['data_count' => 100, 'latest_candle' => '2026-09-01 00:00:00'],
            'completed_at' => now(),
        ]);
        $symbol = Symbol::create([
            'code' => 'XAUUSD', 'display_name' => 'Gold', 'asset_class' => 'metal', 'is_active' => true,
        ]);
        $seedCandles = static function (Symbol $symbol, int $count): void {
            $rows = [];
            $offset = (int) DB::table('candles')->where('symbol_id', $symbol->id)->where('timeframe', 'H1')->count();
            for ($index = $offset; $index < $offset + $count; $index++) {
                $price = 2500 + $index;
                $rows[] = [
                    'symbol_id' => $symbol->id,
                    'timeframe' => 'H1',
                    'time' => CarbonImmutable::parse('2026-09-01 00:00:00')->addHours($index)->toDateTimeString(),
                    'open' => $price,
                    'high' => $price + 1,
                    'low' => $price - 1,
                    'close' => $price,
                    'volume' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('candles')->insert($rows);
        };
        $seedCandles($symbol, 123);

        $arbiter = app(ResearchLoopArbiterService::class);
        $snapshot = new \ReflectionMethod($arbiter, 'operationalStateSnapshot');
        $evidence = ['generation' => ['id' => $generation->id], 'admission_trigger' => 'new_data'];
        $beforeThreshold = $snapshot->invoke($arbiter, $evidence, 'scheduler-constructor', 'XAUUSD', 'H1');
        $this->assertArrayNotHasKey('fresh_data_retry_watermark', $beforeThreshold);

        $seedCandles($symbol, 1);
        $atThreshold = $snapshot->invoke($arbiter, $evidence, 'scheduler-constructor', 'XAUUSD', 'H1');
        $this->assertSame(1, data_get($atThreshold, 'fresh_data_retry_watermark.new_candle_window'));

        $seedCandles($symbol, 1);
        $withinSameWindow = $snapshot->invoke($arbiter, $evidence, 'scheduler-constructor', 'XAUUSD', 'H1');
        $this->assertSame($atThreshold, $withinSameWindow);

        $seedCandles($symbol, 23);
        $nextWindow = $snapshot->invoke($arbiter, $evidence, 'scheduler-constructor', 'XAUUSD', 'H1');
        $this->assertSame(2, data_get($nextWindow, 'fresh_data_retry_watermark.new_candle_window'));
    }

    public function test_running_lifecycle_child_fences_a_second_lifecycle_action_with_different_arguments(): void
    {
        Queue::fake();
        $lab = $this->lab();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'learning_confirmation',
            'status' => 'draft',
            'population_size' => 20,
            'trigger_context' => ['generation_plan' => array_fill(0, 20, ['role' => 'candidate'])],
            'started_at' => now(),
        ]);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'running');
        $owner = ResearchLoopDecision::create([
            'decision_key' => hash('sha256', 'running-lifecycle-owner'),
            'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'action' => 'OPEN_CAUSAL_LEARNING_CONFIRMATION',
            'status' => 'running', 'priority' => 92,
            'evidence_hash' => hash('sha256', 'running-lifecycle-evidence'),
            'command' => 'trading:run-lifecycle-cycle', 'queue' => 'scheduler-constructor',
            'arguments' => ['--symbol' => 'XAUUSD', '--learning-confirmation' => true, '--json' => true],
            'reason_codes' => ['TEST'],
            'evidence_snapshot' => ['generation' => ['id' => $generation->id]],
            'contract' => ['owner' => ResearchLoopArbiterService::OWNER, 'selection_cardinality' => 1],
            'dispatched_at' => now(),
        ]);
        $ownerJob = new RunScheduledArtisanCommandJob(
            (string) $owner->command,
            (array) $owner->arguments,
            (string) $owner->queue,
            (int) $owner->id,
        );
        $this->assertTrue((new UniqueLock(Cache::store()))->acquire($ownerJob));

        $result = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame('SETTLE_EXISTING_GENERATION', $result['action']);
        $this->assertSame('in_flight_suppressed', $result['status']);
        $this->assertSame($owner->id, $result['decision_id']);
        $this->assertDatabaseCount('research_loop_decisions', 1);
        Queue::assertNothingPushed();
        (new UniqueLock(Cache::store()))->release($ownerJob);
    }

    public function test_reconciled_terminal_child_delivery_is_an_explicit_noop(): void
    {
        $decision = ResearchLoopDecision::create([
            'decision_key' => hash('sha256', 'terminal-child-delivery'),
            'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'action' => 'OPEN_CAUSAL_LEARNING_CONFIRMATION',
            'status' => 'failed', 'priority' => 92,
            'evidence_hash' => hash('sha256', 'terminal-child-evidence'),
            'command' => 'trading:run-lifecycle-cycle', 'queue' => 'scheduler-constructor',
            'arguments' => ['--symbol' => 'XAUUSD', '--json' => true],
            'reason_codes' => ['RECOVERED_DEAD_OWNER'],
            'evidence_snapshot' => [], 'contract' => [], 'completed_at' => now(),
        ]);
        $job = new RunScheduledArtisanCommandJob(
            (string) $decision->command,
            (array) $decision->arguments,
            (string) $decision->queue,
            (int) $decision->id,
        );
        Artisan::shouldReceive('call')->never();

        $job->handle(app(ScheduledCommandOutcomeClassifierService::class));

        $this->assertSame('failed', $decision->fresh()->status);
        $this->assertSame(
            'skipped_terminal_decision',
            data_get(Cache::get($job->statusCacheKey()), 'status'),
        );
    }

    public function test_latest_generation_learning_pair_outranks_historical_positive_backlog(): void
    {
        Queue::fake();
        $lab = $this->lab();
        $latest = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 22,
            'trigger_type' => 'scheduled',
            'status' => 'screened',
            'population_size' => 20,
            'trigger_context' => [],
            'completed_at' => now(),
        ]);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'running');
        $historical = $this->learningPair(901, 777, 1901);
        $current = $this->learningPair(902, (int) $latest->id, 1902);
        $learning = Mockery::mock(LearningLaneService::class);
        $learning->shouldReceive('priorityResearchPair')->once()->with('XAUUSD', 'H1')->andReturn($historical);
        $learning->shouldReceive('pendingMicroPairs')->once()->with('XAUUSD', 'H1', null, 500)
            ->andReturn(collect([$historical]));
        $learning->shouldReceive('frontier')->once()->with('XAUUSD', 'H1', null, 500, false)
            ->andReturn(collect([$current, $historical]));
        $arbiter = new ResearchLoopArbiterService(
            app(AutonomousModeService::class),
            app(ResearchClosureInvariantService::class),
            app(ResearchExperimentConversionKernelService::class),
            $learning,
            Mockery::mock(AutonomousLearningProgressDirectorService::class),
            Mockery::mock(MtfResearchCohortService::class),
            Mockery::mock(MarketDriftDetectionService::class),
            app(CausalLearningCohortService::class),
        );

        $result = $arbiter->tick();

        $this->assertSame('PUMP_CANONICAL_LEARNING_PAIR', $result['action']);
        $this->assertSame(902, data_get($result, 'evidence_snapshot.pair_id'));
        $this->assertSame('latest_generation', data_get($result, 'evidence_snapshot.curriculum_scope'));
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
    }

    public function test_historical_learning_backlog_gets_one_maintenance_seat_without_starving_next_generation(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-09-11 12:34:20 UTC');
        try {
            $lab = $this->lab();
            LabGeneration::create([
                'ai_laboratory_id' => $lab->id,
                'generation' => 22,
                'trigger_type' => 'scheduled',
                'status' => 'screened',
                'population_size' => 20,
                'trigger_context' => [],
                'completed_at' => now(),
            ]);
            app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'running');
            $historical = $this->learningPair(903, 777, 1903);
            $learning = Mockery::mock(LearningLaneService::class);
            $learning->shouldReceive('priorityResearchPair')->twice()->with('XAUUSD', 'H1')->andReturn($historical);
            $learning->shouldReceive('pendingMicroPairs')->twice()->with('XAUUSD', 'H1', null, 500)
                ->andReturn(collect([$historical]));
            $learning->shouldReceive('frontier')->twice()->with('XAUUSD', 'H1', null, 500, false)
                ->andReturn(collect([$historical]));
            $planner = Mockery::mock(CausalLearningCohortPlannerService::class);
            $planner->shouldReceive('eligibleLesson')->twice()->with('XAUUSD', 'H1')->andReturnNull();
            $this->app->instance(CausalLearningCohortPlannerService::class, $planner);
            $director = Mockery::mock(AutonomousLearningProgressDirectorService::class);
            $director->shouldReceive('advance')->twice()->andReturn([
                'protocol' => AutonomousLearningProgressDirectorService::PROTOCOL,
                'status' => 'blocked', 'reason' => 'NO_CAUSAL_ACTION_READY',
            ]);
            $cohorts = Mockery::mock(MtfResearchCohortService::class);
            $cohorts->shouldReceive('candidate')->twice()->andReturnNull();
            $drift = Mockery::mock(MarketDriftDetectionService::class);
            $drift->shouldReceive('confirmation')->twice()->andReturn(['status' => 'waiting']);
            $arbiter = new ResearchLoopArbiterService(
                app(AutonomousModeService::class),
                app(ResearchClosureInvariantService::class),
                app(ResearchExperimentConversionKernelService::class),
                $learning,
                $director,
                $cohorts,
                $drift,
                app(CausalLearningCohortService::class),
            );

            $maintenance = $arbiter->tick();
            CarbonImmutable::setTestNow('2026-09-11 12:35:20 UTC');
            $progress = $arbiter->tick();

            $this->assertSame('PUMP_HISTORICAL_LEARNING_PAIR', $maintenance['action']);
            $this->assertSame('historical_maintenance', data_get($maintenance, 'evidence_snapshot.curriculum_scope'));
            $this->assertSame('RUN_NORMAL_TWENTY_SEAT_LIFECYCLE', $progress['action']);
            Queue::assertPushed(RunScheduledArtisanCommandJob::class, 2);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_deferred_targeted_handoff_does_not_monopolise_the_arbiter(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-09-11 12:34:20 UTC');
        try {
            $lab = $this->lab();
            $generation = LabGeneration::create([
                'ai_laboratory_id' => $lab->id,
                'generation' => 1,
                'trigger_type' => 'new_data',
                'status' => 'screened',
                'population_size' => 20,
                'trigger_context' => [],
                'completed_at' => now(),
            ]);
            CandidateHandoffEvent::create([
                'lab_generation_id' => $generation->id,
                'stage' => 'waiting_for_targeted_generation',
                'status' => 'waiting',
                'terminal_reason' => 'TARGETED_GENERATION_RETRY_DEFERRED',
                'payload' => ['targeted_retry' => [
                    'next_retry_at' => CarbonImmutable::now('UTC')->addHour()->toIso8601String(),
                ]],
                'recorded_at' => now(),
            ]);
            app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'running');
            $director = Mockery::mock(AutonomousLearningProgressDirectorService::class);
            $director->shouldReceive('advance')->once()->andReturn([
                'protocol' => AutonomousLearningProgressDirectorService::PROTOCOL,
                'status' => 'blocked', 'reason' => 'NO_CAUSAL_ACTION_READY',
            ]);
            $cohorts = Mockery::mock(MtfResearchCohortService::class);
            $cohorts->shouldReceive('candidate')->once()->andReturnNull();
            $drift = Mockery::mock(MarketDriftDetectionService::class);
            $drift->shouldReceive('confirmation')->once()->andReturn(['status' => 'waiting']);
            $arbiter = new ResearchLoopArbiterService(
                app(AutonomousModeService::class),
                app(ResearchClosureInvariantService::class),
                app(ResearchExperimentConversionKernelService::class),
                app(LearningLaneService::class),
                $director,
                $cohorts,
                $drift,
                app(CausalLearningCohortService::class),
            );

            $result = $arbiter->tick();

            $this->assertSame('RUN_NORMAL_TWENTY_SEAT_LIFECYCLE', $result['action']);
            Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
            Queue::assertNotPushed(RunScheduledArtisanCommandJob::class,
                fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:process-targeted-generations');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_historical_dispatched_targeted_decision_without_a_live_queue_lock_does_not_block_successor_selection(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-09-23 13:10:00 UTC');
        try {
            $lab = $this->lab();
            $generation = LabGeneration::create([
                'ai_laboratory_id' => $lab->id,
                'generation' => 234,
                'trigger_type' => 'learning_confirmation',
                'status' => 'completed',
                'population_size' => 20,
                'trigger_context' => [],
                'completed_at' => now(),
            ]);
            app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'running');
            CandidateHandoffEvent::create([
                'lab_generation_id' => $generation->id,
                'stage' => 'waiting_for_targeted_generation',
                'status' => 'waiting',
                'terminal_reason' => 'NO_ELIGIBLE_CANDIDATE',
                'payload' => [],
                'recorded_at' => now(),
            ]);
            $stale = ResearchLoopDecision::create([
                'decision_key' => hash('sha256', 'stale-targeted-generation-decision'),
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'action' => 'CONSUME_TARGETED_GENERATION_REQUEST',
                'status' => 'dispatched',
                'priority' => 89,
                'evidence_hash' => hash('sha256', 'stale-targeted-generation-evidence'),
                'command' => 'trading:process-targeted-generations',
                'queue' => 'scheduler-constructor',
                'arguments' => [],
                'reason_codes' => ['DURABLE_TARGETED_HANDOFF_READY'],
                'evidence_snapshot' => [],
                'contract' => [],
                'dispatched_at' => now()->subDays(8),
            ]);
            DB::table('research_loop_decisions')->where('id', $stale->id)->update([
                'created_at' => now()->subDays(8),
                'updated_at' => now()->subDays(8),
            ]);
            $director = Mockery::mock(AutonomousLearningProgressDirectorService::class);
            $director->shouldReceive('advance')->never();
            $cohorts = Mockery::mock(MtfResearchCohortService::class);
            $cohorts->shouldReceive('candidate')->never();
            $drift = Mockery::mock(MarketDriftDetectionService::class);
            $drift->shouldReceive('confirmation')->never();
            $planner = Mockery::mock(CausalLearningCohortPlannerService::class);
            $planner->shouldReceive('eligibleLesson')->once()->andReturnNull();
            $this->app->instance(CausalLearningCohortPlannerService::class, $planner);
            $arbiter = new ResearchLoopArbiterService(
                app(AutonomousModeService::class),
                app(ResearchClosureInvariantService::class),
                app(ResearchExperimentConversionKernelService::class),
                app(LearningLaneService::class),
                $director,
                $cohorts,
                $drift,
                app(CausalLearningCohortService::class),
            );

            $result = $arbiter->tick();

            $this->assertSame('CONSUME_TARGETED_GENERATION_REQUEST', $result['action']);
            $this->assertNotSame($stale->id, $result['decision_id']);
            $this->assertSame('dispatched', $result['status']);
            Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
            Queue::assertPushed(RunScheduledArtisanCommandJob::class,
                fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:process-targeted-generations');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_target_aligned_causal_lesson_outranks_market_drift_generation(): void
    {
        Queue::fake();
        $lab = $this->lab();
        LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'new_data',
            'status' => 'screened',
            'population_size' => 20,
            'trigger_context' => [],
            'completed_at' => now(),
        ]);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'running');
        $lesson = new AgentLearningLesson([
            'strategy_family' => 'trend',
            'failure_class' => 'temporal_stability',
            'parameter_key' => 'regime_classifier_variant',
        ]);
        $lesson->id = 77;
        $planner = Mockery::mock(CausalLearningCohortPlannerService::class);
        $planner->shouldReceive('eligibleLesson')->once()->with('XAUUSD', 'H1')->andReturn($lesson);
        $this->app->instance(CausalLearningCohortPlannerService::class, $planner);
        $director = Mockery::mock(AutonomousLearningProgressDirectorService::class);
        $director->shouldReceive('advance')->never();
        $cohorts = Mockery::mock(MtfResearchCohortService::class);
        $cohorts->shouldReceive('candidate')->never();
        $drift = Mockery::mock(MarketDriftDetectionService::class);
        $drift->shouldReceive('confirmation')->never();
        $arbiter = new ResearchLoopArbiterService(
            app(AutonomousModeService::class),
            app(ResearchClosureInvariantService::class),
            app(ResearchExperimentConversionKernelService::class),
            app(LearningLaneService::class),
            $director,
            $cohorts,
            $drift,
            app(CausalLearningCohortService::class),
        );

        $result = $arbiter->tick();

        $this->assertSame('OPEN_CAUSAL_LEARNING_CONFIRMATION', $result['action']);
        $this->assertSame(77, data_get($result, 'evidence_snapshot.lesson.id'));
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class,
            fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:run-lifecycle-cycle'
                && ($job->arguments['--learning-confirmation'] ?? false) === true);
        Queue::assertNotPushed(RunScheduledArtisanCommandJob::class,
            fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:lab-generation');
    }

    private function lab(): AiLaboratory
    {
        return AiLaboratory::create(['name' => 'arbiter lab', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
    }

    public function test_exact_instrument_projection_debt_is_one_bounded_action_before_fresh_exploration(): void
    {
        Queue::fake();
        $lab = $this->lab();
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'new_data', 'status' => 'screened', 'population_size' => 20,
            'trigger_context' => [], 'completed_at' => now()]);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'running');
        $ledger = Mockery::mock(\App\Services\InstrumentInvocationLedgerService::class);
        $ledger->shouldReceive('pendingResearchPairs')->with('XAUUSD', 'H1')->andReturn([
            ['pair_id' => 99, 'generation_id' => $generation->id, 'pending_invocations' => 3,
                'powered_exact_contexts' => 2, 'priority' => 2]]);
        $this->app->instance(\App\Services\InstrumentInvocationLedgerService::class, $ledger);
        $result = app(ResearchLoopArbiterService::class)->tick();
        $this->assertSame('RECONCILE_EXACT_INSTRUMENT_PAIR', $result['action']);
        $this->assertSame(99, $result['arguments']['--pair-id']);
        $this->assertSame('scheduler-critical', $result['queue']);
        $this->assertSame('duplicate_suppressed', app(ResearchLoopArbiterService::class)->tick()['status']);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, fn ($job): bool =>
            $job->command === 'trading:reconcile-instrument-pairs' && $job->arguments['--autonomous'] === true);
    }

    private function contract(): array
    {
        return [
            'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => 'arbiter_fixture', 'id' => 1],
            'scope' => ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5'],
            'identity' => ['baseline_epoch_hash' => 'baseline', 'data_and_mtf_hash' => 'data',
                'runtime_and_contract_hash' => 'runtime', 'intervention_hash' => 'intervention',
                'window_plan_hash' => 'window', 'evaluator_version' => 'v1'],
            'arms' => [['role' => 'frozen_control'], ['role' => 'candidate']],
            'revisions' => ['subject' => 1, 'evidence' => 1],
        ];
    }

    private function learningPair(int $id, int $generationId, int $candidateAgentId): LabLearningLanePair
    {
        $pair = new LabLearningLanePair([
            'lab_generation_id' => $generationId,
            'candidate_agent_id' => $candidateAgentId,
            'status' => 'screen_paired',
        ]);
        $pair->id = $id;
        $pair->exists = true;

        return $pair;
    }
}
