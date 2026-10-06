<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabEvaluationRun;
use App\Models\ModelVersion;
use App\Services\AutonomousModeService;
use App\Services\GenerationAdmissionDecisionService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Services\LearningVelocityGateService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\ResearchReleaseSealService;
use App\Services\SpecialistCouncilContractService;
use App\Services\SpecialistCouncilIndependentPanelService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** The real public constructor/slot path; original proof issuer is isolated, not market evidence. */
class AuthorizedSpecialistCouncilConstructorTest extends TestCase
{
    use RefreshDatabase;

    private function ready(): AiLaboratory
    {
        config(['services.market_data.provider' => 'csv', 'services.lab_selection.constructor_initial_seat_budget' => 12]);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'authorized original constructor test');
        $this->partialMock(LabImmutableEvidenceService::class, fn ($mock) => $mock->shouldReceive('codeHash')->andReturn(str_repeat('a', 64)));
        $this->partialMock(ResearchReleaseSealService::class, fn ($mock) => $mock->shouldReceive('pythonHash')->andReturn(str_repeat('b', 64)));
        $this->mock(LearningVelocityGateService::class, fn ($mock) => $mock->shouldReceive('inspect')->andReturn(['status' => 'healthy', 'allowed' => true]));
        app()->instance(LabPopulationService::class, app(LabPopulationService::class));
        return AiLaboratory::create(['name' => 'authorized constructor only', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
    }

    private function source(): ModelVersion
    {
        return ModelVersion::create(['name' => 'original immutable native fixture', 'strategy' => 'xauusd_hybrid_g1_a01',
            'version' => 'v1', 'status' => 'testing', 'parameters' => app(StrategyParameterSchemaService::class)->defaults('hybrid'),
            'metadata' => ['base_strategy' => 'hybrid', 'strategy_architecture' => 'hybrid',
                'tactic_contract' => ['protocol' => 'original-native-tactic'],
                'instrument_research_assignment' => ['assignment_hash' => str_repeat('c', 64), 'identity' => 'original-fixture'],
                'specialist_council' => ['protocol' => 'original-native-runtime', 'version_id' => 17],
                'specialist_council_evaluation' => ['old_binding' => true], 'last_result' => ['profit' => 99],
                'inheritance_credit' => 4]]);
    }

    private function intent(ModelVersion $source, int $ordinal = 1, int $work = 31): array
    {
        $arms = [];
        foreach (['candidate', 'champion', 'solo', 'ablation', 'retention'] as $kind) $arms[] = [
            'arm_key' => 'window_'.$ordinal.':'.$kind, 'kind' => $kind,
            'source_model_version_id' => $source->id, 'source_model_hash' => app(SpecialistCouncilContractService::class)->modelHash($source),
            'strategy' => $source->strategy, 'family' => 'hybrid', 'parameters' => (array) $source->parameters,
        ];
        return ['protocol' => LabPopulationService::AUTHORIZED_COUNCIL_PANEL_INTENT_PROTOCOL,
            'symbol' => 'XAUUSD', 'storage_timeframe' => 'H1', 'work_item_id' => $work, 'work_key' => 'original-work-'.$work,
            'reservation_hash' => str_repeat('d', 64), 'window_key' => 'window_'.$ordinal, 'window_ordinal' => $ordinal,
            'source_version_id' => 17, 'evaluator_id' => 'independent-original-examiner',
            'current_source_hash' => str_repeat('a', 64), 'current_python_source_hash' => str_repeat('b', 64),
            'authorized_window' => ['window_key' => 'window_'.$ordinal, 'dataset_sha256' => str_repeat((string) $ordinal, 64),
                'start_inclusive' => '2027-0'.$ordinal.'-01T00:00:00Z', 'end_exclusive' => '2027-0'.($ordinal + 1).'-01T00:00:00Z'],
            'arm_roots' => $arms, 'authority' => 'research_only', 'promotion_evidence' => false,
            'independent_evidence_claimed' => false];
    }

    private function issuer(): void
    {
        $this->mock(SpecialistCouncilIndependentPanelService::class, fn ($mock) => $mock
            ->shouldReceive('assertConstructorIntent')->andReturnUsing(fn (array $input): array => $input));
    }

    private function build(array $intent): ?\App\Models\LabGeneration
    {
        return app(LabPopulationService::class)->build('XAUUSD', LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER,
            false, 'H1', [], false, false, null, null, false, null, null, $intent);
    }

    public function test_real_public_constructor_reserves_three_exact_unobserved_window_cohorts_and_retry_reuses_owner(): void
    {
        $this->ready(); $source = $this->source(); $this->issuer();
        $evidence = app(LabImmutableEvidenceService::class);
        $originalHash = app(SpecialistCouncilContractService::class)->modelHash($source);
        foreach ([1, 2, 3] as $ordinal) {
            $generation = $this->build($this->intent($source, $ordinal));
            $this->assertNotNull($generation, json_encode(app(LabPopulationService::class)->lastBuildOutcome()));
            $this->assertSame('research_reserved', $generation->status);
            $this->assertSame(5, $generation->agents()->count());
            $this->assertSame($ordinal, data_get($generation->trigger_context, 'specialist_council_authorized_panel.window_ordinal'));
            $this->assertSame(str_repeat((string) $ordinal, 64), $generation->data_fingerprint);
            $this->assertNull(data_get($generation->trigger_context, 'historical_research_admission'));
            $this->assertNull(data_get($generation->trigger_context, 'control_pairing_contract'));
            foreach ($generation->agents()->with('modelVersion')->orderBy('id')->get() as $index => $agent) {
                $model = $agent->modelVersion;
                $this->assertSame($source->strategy, $model->strategy);
                $this->assertTrue($evidence->equivalentJsonValue($source->parameters, $model->parameters));
                $this->assertTrue($evidence->equivalentJsonValue($evidence->modelRuntimeBasis($source), $evidence->modelRuntimeBasis($model)));
                $this->assertSame($source->metadata['instrument_research_assignment'], $model->metadata['instrument_research_assignment']);
                $this->assertNull(data_get($model->metadata, 'specialist_council_evaluation'));
                $this->assertNull(data_get($model->metadata, 'last_result'));
                $this->assertNull(data_get($model->metadata, 'inheritance_credit'));
                $this->assertSame($index + 1, data_get($model->metadata, 'authorized_specialist_council_panel_seed.construction_slot'));
                $this->assertSame((int) $generation->id, data_get($model->metadata, 'authorized_specialist_council_panel_seed.lab_generation_id'));
                $this->assertNull($agent->parent_a_model_version_id);
                $this->assertSame([], $agent->parameter_diff);
            }
            $again = $this->build($this->intent($source, $ordinal));
            $this->assertSame($generation->id, $again?->id);
        }
        $this->assertSame($originalHash, app(SpecialistCouncilContractService::class)->modelHash($source->fresh()));
        $this->assertDatabaseCount('lab_generations', 3);
        $this->assertDatabaseCount('model_versions', 16);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_partial_original_strategy_clone_resumes_same_generation_by_verified_slot_marker(): void
    {
        $this->ready(); $source = $this->source(); $this->issuer();
        config(['services.lab_selection.constructor_initial_seat_budget' => 2]);
        $generation = $this->build($this->intent($source));
        $this->assertNotNull($generation, json_encode(app(LabPopulationService::class)->lastBuildOutcome()));
        $this->assertSame('technical_quarantine', $generation->status);
        $originalIds = $generation->agents()->orderBy('id')->pluck('id')->all();
        $result = app(LabPopulationService::class)->continueInterruptedConstruction($generation->id, 3);
        $this->assertSame('complete', $result['status'], json_encode($result));
        $this->assertSame([], $result['failures']);
        $this->assertSame('research_reserved', $generation->fresh()->status);
        $this->assertSame($originalIds, $generation->agents()->orderBy('id')->limit(2)->pluck('id')->all());
        $this->assertSame(5, $generation->agents()->count());
        $this->assertDatabaseCount('lab_generations', 1);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    /** Conditional issuer isolates constructor ownership; never a qualified-parent/admission claim. */
    public function test_descendant_first_cohort_reserves_thirteen_owned_slots_and_resumes_three_to_twelve_to_thirteen(): void
    {
        $this->ready(); $source = $this->source(); $this->issuer();
        config(['services.lab_selection.constructor_initial_seat_budget' => 3]);
        $intent = $this->intent($source);
        $arms = [];
        foreach (['candidate', 'champion', 'solo', 'retention'] as $kind) $arms[] = [...$intent['arm_roots'][0], 'arm_key' => $kind, 'kind' => $kind];
        foreach (['scalp', 'hour', 'day', 'swing', 'trait'] as $target) $arms[] = [...$intent['arm_roots'][0],
            'arm_key' => 'ablation:'.$target, 'kind' => 'ablation', 'removed_id' => $target];
        $intent['arm_roots'] = $arms; $members = [];
        foreach (['scalp', 'hour', 'day', 'swing'] as $role) {
            $metadata = (array) $source->metadata; unset($metadata['specialist_council']);
            $member = ModelVersion::create(['name' => 'conditional-derived-source-'.$role, 'strategy' => $source->strategy,
                'version' => 'v1', 'status' => 'testing', 'parameters' => (array) $source->parameters, 'metadata' => $metadata]);
            $parameters = (array) $member->parameters; $deltas = [];
            if ($role === 'hour') { $deltas[] = ['gene' => 'trend_weight', 'old' => $parameters['trend_weight'], 'new' => .9]; $parameters['trend_weight'] = .9; }
            $members[] = ['arm_key' => 'member:'.$role, 'kind' => 'member_source', 'derived_member_role' => $role,
                'source_model_version_id' => $member->id, 'source_model_hash' => app(SpecialistCouncilContractService::class)->modelHash($member),
                'strategy' => $member->strategy, 'family' => 'hybrid', 'parameters' => $parameters, 'parameter_deltas' => $deltas];
        }
        $intent['member_roots'] = $members;
        $generation = $this->build($intent);
        $this->assertNotNull($generation, json_encode(app(LabPopulationService::class)->lastBuildOutcome()));
        $this->assertCount(13, LabPopulationService::authorizedPanelConstructionRoots(data_get($generation->trigger_context, 'authorized_specialist_council_panel_intent')));
        $this->assertSame(3, $generation->population_size); $this->assertSame(3, $generation->agents()->count());
        $firstIds = $generation->agents()->orderBy('id')->pluck('id')->all();
        // Canonical continuations retain their four-seat delivery ceiling.
        foreach ([4, 4, 1] as $budget) $middle = app(LabPopulationService::class)->continueInterruptedConstruction($generation->id, $budget);
        $this->assertSame(12, $generation->agents()->count(), json_encode([$middle['status'], $middle['failures']]));
        $this->assertTrue(LabPopulationService::constructionIncomplete($generation->fresh()));
        $last = app(LabPopulationService::class)->continueInterruptedConstruction($generation->id, 1);
        $this->assertSame('complete', $last['status'], json_encode([$last['status'], $last['failures']]));
        $this->assertSame(13, $generation->agents()->count()); $this->assertSame('research_reserved', $generation->fresh()->status);
        $this->assertSame($firstIds, $generation->agents()->orderBy('id')->limit(3)->pluck('id')->all());
        $agents = $generation->agents()->with('modelVersion')->get();
        $this->assertCount(4, $agents->filter(fn ($agent) => data_get($agent->modelVersion->metadata, 'authorized_specialist_council_panel_seed.kind') === 'member_source'));
        foreach ($agents as $agent) {
            $slot = data_get($agent->modelVersion->metadata, 'authorized_specialist_council_panel_seed.construction_slot');
            $root = LabPopulationService::authorizedPanelConstructionRoots($intent)[$slot - 1];
            $this->assertTrue(app(LabImmutableEvidenceService::class)->equivalentJsonValue($root['parameters'], $agent->modelVersion->parameters));
            $this->assertSame('draft', $agent->lifecycle_status); $this->assertNull($agent->parent_a_model_version_id);
        }
        foreach ([2, 3] as $ordinal) {
            $next = $this->intent($source, $ordinal); $next['arm_roots'] = $arms;
            $sibling = $this->build($next);
            $this->assertNotNull($sibling, json_encode(app(LabPopulationService::class)->lastBuildOutcome()));
            foreach ([4, 2] as $budget) $result = app(LabPopulationService::class)->continueInterruptedConstruction($sibling->id, $budget);
            $this->assertSame('complete', $result['status']); $this->assertSame(9, $sibling->agents()->count());
            $this->assertSame(9, $sibling->fresh()->population_size);
        }
        $work = new \App\Models\ResearchExperimentWorkItem; $work->forceFill(['id' => 31]);
        $settler = new \ReflectionMethod(\App\Services\SpecialistCouncilPanelReservationService::class, 'settleMemberReferences');
        $settler->invoke(app(\App\Services\SpecialistCouncilPanelReservationService::class), $generation->fresh(), $work, ['ptu_version_id' => 999]);
        $this->assertSame(4, $generation->agents()->where('lifecycle_status', 'completed')->count());
        $this->assertSame(9, $generation->agents()->where('lifecycle_status', 'draft')->count());
        $this->assertDatabaseCount('agent_learning_settlements', 4);
        foreach (\App\Models\AgentLearningSettlement::all() as $settlement) {
            $this->assertSame('insufficient_evidence', $settlement->evidence_state);
            $this->assertEquals(0, $settlement->selection_reward);
        }
        $this->assertDatabaseCount('lab_generations', 3); $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_foreign_reserved_work_or_any_original_outcome_cannot_open_another_window(): void
    {
        $this->ready(); $source = $this->source(); $this->issuer();
        $generation = $this->build($this->intent($source));
        $this->assertNotNull($generation);
        $this->assertNull($this->build($this->intent($source, 2, 32)));
        $agent = $generation->agents()->first();
        LabEvaluationRun::create(['run_id' => 'original-started-unit', 'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id,
            'phase' => 'full_validation', 'mode' => 'full', 'status' => 'started', 'started_at' => now()]);
        $this->assertNull($this->build($this->intent($source, 2)));
        $this->assertDatabaseCount('lab_generations', 1);
    }

    public function test_source_drift_2026_and_unverified_flags_fail_before_creating_models(): void
    {
        $this->ready(); $source = $this->source(); $this->issuer();
        $intent = $this->intent($source);
        $source->update(['parameters' => [...$source->parameters, 'ema_fast' => 3]]);
        $this->assertNull($this->build($intent));
        $intent = $this->intent($source); $intent['authorized_window']['start_inclusive'] = '2026-01-01T00:00:00Z';
        $this->assertNull($this->build($intent));
        $this->mock(SpecialistCouncilIndependentPanelService::class, fn ($mock) => $mock->shouldReceive('assertConstructorIntent')
            ->andThrow(new \LogicException('ORIGINAL_SERVER_RESERVATION_REQUIRED')));
        $this->assertNull($this->build($this->intent($source)));
        $this->assertSame('ORIGINAL_SERVER_RESERVATION_REQUIRED', data_get(app(LabPopulationService::class)->lastBuildOutcome(), 'context.dependency'));
        $this->assertDatabaseCount('lab_generations', 0);
        $this->assertDatabaseCount('model_versions', 1);
    }

    public function test_shared_admission_cannot_promote_an_invalid_panel_into_special_escape_or_ignore_foreign_active_work(): void
    {
        $lab = $this->ready(); $source = $this->source(); $this->issuer();
        $intent = $this->intent($source);
        $intent['intent_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash($intent);
        $admission = app(GenerationAdmissionDecisionService::class);
        $invalid = $admission->decide($lab, null, ['trigger' => LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER,
            'authorized_specialist_council_panel_intent' => $intent, 'role_complete' => true]);
        $this->assertFalse($invalid['allowed']);
        $this->assertSame(GenerationAdmissionDecisionService::BLOCK_HARD, $invalid['decision']);
        $this->assertContains('AUTHORIZED_COUNCIL_PANEL_ADMISSION_OWNER_INVALID', $invalid['reason_codes']);
        $foreign = $lab->generations()->create(['generation' => 1, 'trigger_type' => 'historical_research',
            'status' => 'draft', 'population_size' => 0, 'trigger_context' => []]);
        $reserved = $lab->generations()->create(['generation' => 2, 'trigger_type' => LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER,
            'status' => 'research_reserved', 'population_size' => 0,
            'trigger_context' => ['specialist_council_authorized_panel' => ['work_item_id' => $intent['work_item_id'],
                'work_key' => $intent['work_key'], 'reservation_hash' => $intent['reservation_hash']]]]);
        $refused = $admission->decide($lab, $reserved, ['trigger' => LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER,
            'authorized_specialist_council_panel_intent' => $intent]);
        $this->assertFalse($refused['allowed']);
        $this->assertContains('AUTHORIZED_COUNCIL_PANEL_FOREIGN_OR_OBSERVED_ACTIVE_OWNER', $refused['reason_codes']);
        $this->assertSame('draft', $foreign->fresh()->status);
        $ordinary = $admission->decide($lab, $reserved, ['trigger' => 'historical_research']);
        $this->assertFalse($ordinary['allowed']);
        $this->assertSame(GenerationAdmissionDecisionService::WAIT_ACTIVE_WORK, $ordinary['decision']);
    }
}
