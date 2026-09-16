<?php

namespace Tests\Feature;

use App\Jobs\RunScheduledArtisanCommandJob;
use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningLesson;
use App\Models\AiLaboratory;
use App\Models\CandidateHandoffEvent;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentWorkItem;
use App\Models\ResearchLoopDecision;
use App\Services\AutonomousLearningProgressDirectorService;
use App\Services\AutonomousModeService;
use App\Services\CausalLearningCohortService;
use App\Services\CausalLearningCohortPlannerService;
use App\Services\LearningLaneService;
use App\Services\MarketDriftDetectionService;
use App\Services\MtfResearchCohortService;
use App\Services\ResearchClosureInvariantService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchLoopArbiterService;
use App\Services\ScheduledCommandOutcomeClassifierService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ResearchLoopArbiterTest extends TestCase
{
    use RefreshDatabase;

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
                && $job->arguments === [
                    0 => 'XAUUSD',
                    '--timeframe' => 'H1',
                    '--causal-experiment-id' => $experiment->id,
                ]);
    }

    public function test_terminal_causal_technical_arm_is_settled_without_replay_or_authority(): void
    {
        Queue::fake();
        $lab = $this->lab();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 222,
            'trigger_type' => 'learning_confirmation',
            'status' => 'technical_quarantine',
            'population_size' => 3,
            'trigger_context' => [],
            'completed_at' => now(),
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
            $this->assertSame('duplicate_suppressed', $second['status']);
            Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_next_minute_suppresses_the_same_in_flight_child_decision(): void
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
            CarbonImmutable::setTestNow('2026-09-11 12:35:20 UTC');
            $second = app(ResearchLoopArbiterService::class)->tick();

            $this->assertSame('dispatched', $first['status']);
            $this->assertSame('in_flight_suppressed', $second['status']);
            $this->assertSame($first['decision_id'], $second['decision_id']);
            $this->assertDatabaseCount('research_loop_decisions', 1);
            Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
        } finally {
            CarbonImmutable::setTestNow();
        }
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
            fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:run-lifecycle-cycle');
        Queue::assertNotPushed(RunScheduledArtisanCommandJob::class,
            fn (RunScheduledArtisanCommandJob $job): bool => $job->command === 'trading:lab-generation');
    }

    private function lab(): AiLaboratory
    {
        return AiLaboratory::create(['name' => 'arbiter lab', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
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
