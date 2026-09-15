<?php

namespace Tests\Feature;

use App\Jobs\RunScheduledArtisanCommandJob;
use App\Models\AgentLearningLesson;
use App\Models\AiLaboratory;
use App\Models\CandidateHandoffEvent;
use App\Models\LabGeneration;
use App\Models\ResearchExperimentWorkItem;
use App\Models\ResearchLoopDecision;
use App\Services\AutonomousLearningProgressDirectorService;
use App\Services\AutonomousModeService;
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
}
