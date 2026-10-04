<?php

namespace Tests\Feature;

use App\Jobs\RunScheduledArtisanCommandJob;
use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Models\ResearchExperimentWorkItem;
use App\Models\ResearchLoopDecision;
use App\Services\AcademyExperimentMaterializerService;
use App\Services\AutonomousLearningProgressDirectorService;
use App\Services\AutonomousModeService;
use App\Services\ProspectiveRepairExperimentService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchLoopArbiterService;
use App\Services\MarketDriftDetectionService;
use App\Services\XauusdEdgeFormationAcademyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Staged selector regression; SQLite/Queue::fake, never production runtime. */
class AcademyColdStartFairnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.xauusd_organism.historical_research_until_champion', false);
        Queue::fake();
        AiLaboratory::create(['name' => 'cold-fairness', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'bounded-fairness');
    }

    public static function freshDirectorActions(): array
    {
        return [['EDGE_GENESIS'], ['EDGE_HYPOTHESIS_COMPILED']];
    }

    #[DataProvider('freshDirectorActions')]
    public function test_ready_cold_question_precedes_new_discovery_and_duplicate_tick_does_not_double_dispatch(string $action): void
    {
        $this->proposal($this->cold(), 2);
        $this->director($action, 2);
        $this->mock(ProspectiveRepairExperimentService::class, function ($mock): void {
            // Fresh pair IDs cannot continually intercept this ready lane.
            $mock->shouldReceive('eligible')->never();
        });

        $first = app(ResearchLoopArbiterService::class)->tick();
        $second = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame('OPEN_ACADEMY_EXPERIMENT', $first['action']);
        $this->assertSame(['trial' => 0], $first['arguments']);
        $this->assertContains('BOUNDED_COLD_ACADEMY_PRECEDES_NEW_DISCOVERY', $first['reason_codes']);
        $this->assertSame('input-bytes-scope', data_get($first, 'evidence_snapshot.selection_policy.stable_input_budget_scope'));
        $this->assertSame($action, data_get($first, 'evidence_snapshot.selection_policy.deferred_new_director_action'));
        $this->assertFalse(data_get($first, 'evidence_snapshot.selection_policy.active_work_preempted'));
        $this->assertFalse(data_get($first, 'evidence_snapshot.selection_policy.earned_proof_preempted'));
        $this->assertSame('duplicate_suppressed', $second['status']);
        $this->assertSame(1, ResearchLoopDecision::count());
        $this->assertSame(0, LabGeneration::count());
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
    }

    public static function earnedDirectorActions(): array
    {
        $actions = [
            'EDGE_DISCOVERY_RESUME', 'AUTHORITY_DESCENDANT_PROOF', 'AUTHORITY_INCUBATOR',
            'PROVISIONAL_SKILL_CARTRIDGE_CONFIRMATION', 'EDGE_INDEPENDENT_REPLICATION',
            'EDGE_CONFIRMATION', 'EDGE_ATTRIBUTION', 'QUALITY_EVOLUTION_SYNTHESIS',
            'SKILL_CARTRIDGE_TRANSPLANT', 'EDGE_ARCHITECTURE_REPAIR',
        ];
        $cases = [];
        foreach ($actions as $action) {
            $cases[] = [$action, true];
            $cases[] = [$action, false];
        }

        return $cases;
    }

    #[DataProvider('earnedDirectorActions')]
    public function test_admitted_or_evidence_earned_director_work_still_wins(string $action, bool $coldReady): void
    {
        $this->proposal($coldReady ? $this->cold()
            : ['status' => 'blocked', 'reason' => 'ACADEMY_COLD_START_SCOPE_BUDGET_EXHAUSTED']);
        $this->director($action);
        $this->mock(ProspectiveRepairExperimentService::class, fn ($mock) => $mock->shouldReceive('eligible')->never());

        $result = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame($action, $result['action']);
        $this->assertSame('trading:advance-learning-progress', $result['command']);
        $this->assertContains('EARNED_PROOF_PRECEDES_NEW_DISCOVERY', $result['reason_codes']);
        $this->assertFalse(data_get($result, 'contract.promotion_authority'));
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
    }

    public function test_current_constructing_generation_is_not_preempted_by_cold_question(): void
    {
        $generation = LabGeneration::create(['ai_laboratory_id' => AiLaboratory::query()->sole()->id,
            'generation' => 252, 'trigger_type' => 'learning_confirmation', 'status' => 'draft',
            'population_size' => 20, 'trigger_context' => []]);
        $this->proposal($this->cold());
        $this->mock(AutonomousLearningProgressDirectorService::class, fn ($mock) => $mock->shouldReceive('advance')->never());
        $this->mock(ProspectiveRepairExperimentService::class, fn ($mock) => $mock->shouldReceive('eligible')->never());

        $result = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame('SETTLE_EXISTING_GENERATION', $result['action']);
        $this->assertSame($generation->id, $result['arguments']['--expected-generation-id']);
        $this->assertSame(1, LabGeneration::count());
        $this->assertSame('draft', $generation->fresh()->status);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
    }

    public function test_existing_durable_continuation_is_leased_before_cold_discovery(): void
    {
        $this->proposal($this->cold());
        $this->mock(AutonomousLearningProgressDirectorService::class, fn ($mock) => $mock->shouldReceive('advance')->never());
        app(ResearchExperimentConversionKernelService::class)->record([
            'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => 'arbiter_fixture', 'id' => 1],
            'scope' => ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5'],
            'identity' => ['baseline_epoch_hash' => 'baseline', 'data_and_mtf_hash' => 'data',
                'runtime_and_contract_hash' => 'runtime', 'intervention_hash' => 'intervention',
                'window_plan_hash' => 'window', 'evaluator_version' => 'v1'],
            'arms' => [['role' => 'frozen_control'], ['role' => 'candidate']],
            'revisions' => ['subject' => 1, 'evidence' => 1],
        ], ['settlement_id' => 1], 'INCONCLUSIVE',
            ['type' => 'fixture_executable_continuation', 'identity' => 'fixture']);

        $result = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame('CONSUME_DURABLE_NEXT_WORK', $result['action']);
        $this->assertSame('leased', ResearchExperimentWorkItem::query()->sole()->status);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
    }

    public function test_exhausted_cold_input_budget_does_not_block_fresh_repair(): void
    {
        $this->proposal(['status' => 'blocked', 'reason' => 'ACADEMY_COLD_START_SCOPE_BUDGET_EXHAUSTED']);
        $this->mock(AutonomousLearningProgressDirectorService::class, fn ($mock) => $mock->shouldReceive('advance')
            ->once()->with('XAUUSD', 'H1', false, true, true)->andReturn(['status' => 'blocked', 'reason' => 'NO_READY_PROOF']));
        $this->mock(ProspectiveRepairExperimentService::class, function ($mock): void {
            $mock->shouldReceive('eligible')->once()->with('XAUUSD', 'H1')->andReturn([
                'source_pair_id' => 1608, 'source_hash' => str_repeat('a', 64),
            ]);
        });

        $result = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame('OPEN_PROSPECTIVE_REPAIR_EXPERIMENT', $result['action']);
        $this->assertSame(1608, $result['arguments']['--prospective-source-pair-id']);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
    }

    #[DataProvider('freshDirectorActions')]
    public function test_fresh_generic_edge_does_not_outrank_eligible_repair_after_cold_cap(string $action): void
    {
        $this->proposal(['status' => 'blocked', 'reason' => 'ACADEMY_COLD_START_SCOPE_BUDGET_EXHAUSTED']);
        $this->director($action);
        $this->mock(ProspectiveRepairExperimentService::class, function ($mock): void {
            $mock->shouldReceive('eligible')->once()->with('XAUUSD', 'H1')->andReturn([
                'source_pair_id' => 1609, 'source_hash' => str_repeat('c', 64),
            ]);
        });

        $result = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame('OPEN_PROSPECTIVE_REPAIR_EXPERIMENT', $result['action']);
        $this->assertSame(1609, $result['arguments']['--prospective-source-pair-id']);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
    }

    public function test_readonly_fair_selection_cannot_persist_lease_or_dispatch(): void
    {
        $this->proposal($this->cold());
        $this->director('EDGE_GENESIS');

        $result = app(ResearchLoopArbiterService::class)->tick('XAUUSD', 'H1', true);

        $this->assertSame('OPEN_ACADEMY_EXPERIMENT', $result['action']);
        $this->assertSame(0, ResearchLoopDecision::count());
        $this->assertSame(0, LabGeneration::count());
        $this->assertSame(0, ResearchExperimentWorkItem::where('status', 'leased')->count());
        Queue::assertNothingPushed();
    }

    public function test_earned_replication_wins_when_cold_is_spent_and_no_repair_is_ready(): void
    {
        $this->proposal(['status' => 'blocked', 'reason' => 'ACADEMY_COLD_START_SCOPE_BUDGET_EXHAUSTED']);
        $this->director('EDGE_INDEPENDENT_REPLICATION');
        $this->mock(ProspectiveRepairExperimentService::class, fn ($mock) => $mock->shouldReceive('eligible')->never());
        // Even a freshly confirmed live drift cannot intercept earned proof.
        $this->mock(MarketDriftDetectionService::class, fn ($mock) => $mock->shouldReceive('confirmation')->never());

        $result = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame('EDGE_INDEPENDENT_REPLICATION', $result['action']);
        $this->assertContains('EARNED_PROOF_PRECEDES_NEW_DISCOVERY', $result['reason_codes']);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
    }

    public function test_attested_curriculum_successor_precedes_fresh_edge_or_new_pair(): void
    {
        $this->proposal(['status' => 'would_materialize', 'trial_id' => 7, 'baseline_model_version_id' => 11]);
        $this->director('EDGE_GENESIS');
        $this->mock(XauusdEdgeFormationAcademyService::class, fn ($mock) => $mock->shouldReceive('curriculumContinuationEvidence')
            ->once()->with(7)->andReturn(['eligible' => true, 'source_trial_id' => 5, 'source_candidate_run_id' => 'sealed-run',
                'successor_passport_id' => 8, 'stage_depth' => 1]));
        $this->mock(ProspectiveRepairExperimentService::class, fn ($mock) => $mock->shouldReceive('eligible')->never());

        $result = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame('OPEN_ACADEMY_EXPERIMENT', $result['action']);
        $this->assertSame(['trial' => 7], $result['arguments']);
        $this->assertContains('ACADEMY_ATTESTED_CURRICULUM_CONTINUATION_READY', $result['reason_codes']);
        $this->assertSame(5, data_get($result, 'evidence_snapshot.academy_continuation.source_trial_id'));
        $this->assertSame(0, LabGeneration::count());
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
    }

    public function test_unattested_planned_trial_does_not_get_earned_priority_from_a_depth_label(): void
    {
        $this->proposal(['status' => 'would_materialize', 'trial_id' => 7, 'stage_depth' => 5,
            'identity' => ['source_academy_trial_id' => 5]]);
        $this->director('EDGE_GENESIS');
        $this->mock(XauusdEdgeFormationAcademyService::class, fn ($mock) => $mock->shouldReceive('curriculumContinuationEvidence')
            ->once()->with(7)->andReturn(['eligible' => false, 'reason' => 'ORIGINAL_STAGE_PROOF_UNAVAILABLE']));
        $this->mock(ProspectiveRepairExperimentService::class, fn ($mock) => $mock->shouldReceive('eligible')
            ->once()->with('XAUUSD', 'H1')->andReturnNull());

        $result = app(ResearchLoopArbiterService::class)->tick();

        $this->assertSame('EDGE_GENESIS', $result['action']);
        $this->assertNotContains('ACADEMY_ATTESTED_CURRICULUM_CONTINUATION_READY', $result['reason_codes']);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
    }

    private function cold(): array
    {
        return ['status' => 'would_prepare_cold_start', 'trial_id' => 0,
            'cold_start' => ['key' => str_repeat('b', 64), 'budget_scope' => 'input-bytes-scope']];
    }

    private function proposal(array $proposal, int $times = 1): void
    {
        $this->mock(AcademyExperimentMaterializerService::class, function ($mock) use ($proposal, $times): void {
            $mock->shouldReceive('proposal')->times($times)->with('XAUUSD', 'H1')->andReturn($proposal);
        });
    }

    private function director(string $action, int $times = 1): void
    {
        $this->mock(AutonomousLearningProgressDirectorService::class, function ($mock) use ($action, $times): void {
            $mock->shouldReceive('advance')->times($times)->with('XAUUSD', 'H1', false, true, true)->andReturn([
                'protocol' => AutonomousLearningProgressDirectorService::PROTOCOL,
                'action' => $action, 'result' => ['status' => 'would_queue'],
            ]);
        });
    }
}
