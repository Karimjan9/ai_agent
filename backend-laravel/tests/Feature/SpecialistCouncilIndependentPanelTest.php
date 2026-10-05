<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentWorkItem;
use App\Models\ResearchExperimentReceipt;
use App\Services\InstrumentResearchWindowService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilIndependentPanelService;
use App\Services\SpecialistCouncilLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Synthetic original registries test guards only, never market/constructor acceptance. */
class SpecialistCouncilIndependentPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_unchanged_program_and_exact_clone_controls_pass_semantic_comparison_only(): void
    {
        [$parent, $target, $work, $plan] = $this->fixture();
        $this->assertParent($work, $parent, $target, $plan);
        $this->assertTrue(true); // not a full panel readiness or qualification claim
    }

    public function test_new_weaker_solo_cannot_replace_the_original_frozen_control(): void
    {
        [$parent, $target, $work, $plan, $models] = $this->fixture();
        $models['solo']->update(['parameters' => ['ema_fast' => 3, 'ema_slow' => 10]]);
        $this->expectExceptionMessage('COUNCIL_PANEL_FROZEN_COMPARATOR_PROGRAM_CHANGED:solo');
        $this->assertParent($work, $parent, $target, $plan);
    }

    public function test_founding_champion_is_only_the_unchanged_original_solo_not_arbitrary_new_model(): void
    {
        [$parent, $target, $work, $plan, $models] = $this->fixture(true);
        // A legacy founding snapshot may lack a champion; no new registry is
        // allowed to seal a nonexistent model. Test only the validator's
        // explicit unchanged-original-solo fallback, not live registration.
        $manifest = $parent->manifest; $manifest['evaluation_policy']['champion_model_version_id'] = 0; $parent->manifest = $manifest;
        $this->assertParent($work, $parent, $target, $plan);
        $models['champion']->update(['parameters' => ['ema_fast' => 9, 'ema_slow' => 10]]);
        $this->expectExceptionMessage('COUNCIL_PANEL_FROZEN_COMPARATOR_PROGRAM_CHANGED:champion');
        $this->assertParent($work, $parent, $target, $plan);
    }

    public function test_risk_routing_and_scientific_criteria_cannot_be_weakened_in_independent_followup(): void
    {
        [$parent, $target, $work, $plan] = $this->fixture();
        foreach (['risk', 'routing', 'evaluation_policy'] as $field) {
            $fresh = clone $target; $manifest = $fresh->manifest;
            if ($field === 'evaluation_policy') $manifest[$field]['minimum_positive_windows'] = 1;
            else $manifest[$field]['version'] = 'weaker';
            $fresh->manifest = $manifest;
            try { $this->assertParent($work, $parent, $fresh, $plan); $this->fail('A changed original policy must be refused.'); }
            catch (\LogicException $error) { $this->assertStringContainsString($field === 'evaluation_policy' ? 'SCIENTIFIC_POLICY_CHANGED' : 'EXTERNAL_POLICY_CHANGED', $error->getMessage()); }
        }
    }

    public function test_duplicate_roles_do_not_hide_a_changed_specialist_or_roster_member(): void
    {
        [$parent, $target, $work, $plan] = $this->fixture();
        $manifest = $target->manifest;
        $manifest['members'][0]['parameters']['ema_fast'] = 6;
        $target->manifest = $manifest;
        $this->expectExceptionMessage('COUNCIL_PANEL_VALIDATION_CANNOT_RETUNE_SELECTED_PROGRAM');
        $this->assertParent($work, $parent, $target, $plan);
    }

    public function test_actual_original_member_parameter_drift_cannot_hide_behind_unchanged_sealed_vectors(): void
    {
        [$parent, $target, $work, $plan, $models] = $this->fixture();
        $models['source']->update(['parameters' => ['ema_fast' => 6, 'ema_slow' => 10]]);
        $this->expectExceptionMessage('COUNCIL_PANEL_ORIGINAL_NATIVE_MEMBER_MODEL_DRIFT');
        $this->assertParent($work, $parent, $target, $plan);
    }

    public function test_late_instrument_descriptor_cannot_reinterpret_an_original_member_seal(): void
    {
        [$parent, $target, $work, $plan, $models] = $this->fixture();
        $models['source']->update(['metadata' => [...$models['source']->metadata,
            'instrument_research_assignment' => ['protocol' => 'synthetic_poison_not_original_owner']]]);
        $this->expectExceptionMessage('COUNCIL_PANEL_ORIGINAL_NATIVE_MEMBER_MODEL_DRIFT');
        $this->assertParent($work, $parent, $target, $plan);
    }

    public function test_descendant_requires_original_qualified_parent_not_provisional_observations(): void
    {
        [$parent, $target, $work, $plan] = $this->fixture();
        $work->work_type = 'specialist_council_descendant_transfer';
        $this->expectExceptionMessage('COUNCIL_DESCENDANT_ORIGINAL_QUALIFIED_PARENT_REQUIRED');
        $this->assertParent($work, $parent, $target, $plan);
    }

    public function test_completed_future_data_does_not_hide_the_missing_canonical_cohort_producer(): void
    {
        [$parent, , $work] = $this->fixture();
        $this->mock(InstrumentResearchWindowService::class)->shouldReceive('readiness')->once()->andReturn(['eligible_windows' => [['fixture' => true]]]);
        $result = app(SpecialistCouncilIndependentPanelService::class)->inspect($work, $parent);
        $this->assertFalse($result['executable']);
        $this->assertSame('CANONICAL_AUTHORIZED_WINDOW_COHORT_PRODUCER_REQUIRED', $result['reason']);
        $this->assertFalse($result['promotion_evidence']);
        $this->assertFalse($result['paper_authority_granted']);
    }

    public function test_register_refuses_context_shaped_generations_without_mutating_originals(): void
    {
        [$parent, $target, $work, $plan] = $this->fixture();
        $lab = AiLaboratory::create(['name' => 'synthetic incomplete producer', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['ema_rsi'], 'is_active' => false]);
        $generations = [];
        foreach (array_keys($plan['windows']) as $i => $key) {
            $gen = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => $i + 1, 'population_size' => 0, 'status' => 'draft',
                'trigger_type' => 'historical_research', 'trigger_context' => ['constructor_audit' => ['protocol' => 'agent_constructor_invariant_v1']]]);
            $generations[$key] = $gen->id;
        }
        try {
            app(SpecialistCouncilIndependentPanelService::class)->register($work, ['protocol' => SpecialistCouncilIndependentPanelService::PROTOCOL,
                'target_version_id' => $target->id, 'window_generation_ids' => $generations, 'arm_agent_ids' => []], 'fixture-operator', $parent, []);
            $this->fail('A context-shaped draft is not an original canonical panel producer.');
        } catch (\LogicException $error) { $this->assertSame('CANONICAL_AUTHORIZED_WINDOW_COHORT_PRODUCER_REQUIRED', $error->getMessage()); }
        $this->assertNull(data_get($work->fresh()->payload, 'followup_resolution'));
        foreach ($generations as $id) $this->assertNull(data_get(LabGeneration::findOrFail($id)->trigger_context, 'specialist_council_independent_panel_owner'));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_forged_seal_and_expired_lease_do_not_publish_a_run_or_call_transport(): void
    {
        [$parent, , $work] = $this->fixture();
        $work->update(['payload' => ['followup_resolution' => ['protocol' => 'forged']], 'status' => 'leased',
            'lease_token' => 'old-token', 'fence_version' => 1, 'lease_expires_at' => now()->subSecond()]);
        $originalWork = $work->fresh()->getRawOriginal();
        $service = app(SpecialistCouncilIndependentPanelService::class);
        $this->assertSame('COUNCIL_PANEL_SERVER_PREREGISTRATION_DRIFT', $service->inspect($work->fresh(), $parent)['reason']);
        Http::fake();
        $result = $service->execute($work->fresh());
        $this->assertSame('COUNCIL_PANEL_WORK_LEASE_NOT_CURRENT', $result['reason']);
        $this->assertSame($originalWork, $work->fresh()->getRawOriginal());
        Http::assertNothingSent();
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    private function assertParent($work, $parent, $target, array $plan): void
    {
        (new \ReflectionMethod(SpecialistCouncilIndependentPanelService::class, 'assertParent'))
            ->invoke(app(SpecialistCouncilIndependentPanelService::class), $work, $parent, $target, $plan, []);
    }

    private function fixture(bool $founding = false): array
    {
        config(['services.internal_api.token' => str_repeat('fixture-only-key-', 3)]);
        $models = [];
        foreach (['source', 'second', 'solo', 'champion'] as $name) $models[$name] = ModelVersion::create(['name' => 'Fixture '.$name,
            'strategy' => 'ema_rsi_v1', 'version' => 'v1', 'status' => 'testing', 'parameters' => ['ema_fast' => 4, 'ema_slow' => 10],
            'metadata' => ['base_strategy' => 'ema_rsi', 'strategy_architecture' => 'ema_rsi']]);
        $members = [];
        foreach (['source', 'second'] as $name) $members[] = ['specialist_id' => $name, 'role' => 'hour', 'version' => 'v1', 'as_of' => '2025-01-01T00:00:00Z',
            'inputs' => ['as_of_closed_candles'], 'scope' => ['symbols' => ['XAUUSD'], 'contexts' => ['trend']],
            'known_limits' => ['synthetic_guard_only_not_market_proof'], 'resources' => ['max_compute_ms' => 100, 'max_memory_mb' => 32, 'max_lookback_bars' => 512],
            'horizon' => ['kind' => 'hour', 'decision_interval_seconds' => 300, 'reevaluation_interval_seconds' => 300,
                'max_holding_seconds' => 3600, 'execution_precision' => 'candle'], 'data_requirements' => ['sessions', 'costs'],
            'model_version_id' => $models[$name]->id, 'strategy_version' => 'v1', 'tactic_version' => 'v1', 'management_version' => 'v1',
            'capital_weight' => .4, 'risk_per_trade_percent' => .5, 'sensor_timeframes' => ['H4', 'H1', 'M15', 'M5']];
        $manifest = ['council_id' => 'panel-guard-fixture', 'version' => 'v1', 'members' => $members, 'components' => [],
            'routing' => ['id' => 'router', 'version' => '1'], 'allocation' => ['id' => 'allocation', 'version' => '1'],
            'risk' => ['id' => 'risk', 'version' => '1'], 'execution' => ['id' => 'native', 'version' => '1', 'broker_position_mode' => 'hedging',
                'opposite_position_policy' => 'hedge', 'max_open_positions' => 8, 'max_reserved_capital_percent' => 100,
                'max_gross_exposure_percent' => 100, 'max_total_risk_percent' => 2, 'max_drawdown_percent' => 10,
                'max_daily_loss_percent' => 3, 'max_expected_cost_percent' => 1],
            'evaluation_policy' => ['objective' => 'net_return_at_equal_risk', 'champion_model_version_id' => $models['source']->id,
                'solo_model_version_id' => $models['source']->id]];
        $lifecycle = app(SpecialistCouncilLifecycleService::class);
        $parent = $lifecycle->registerDraft($manifest, 'original-creator');
        $manifest['version'] = 'v2'; $manifest['evaluation_policy']['champion_model_version_id'] = $models['champion']->id;
        $manifest['evaluation_policy']['solo_model_version_id'] = $models['solo']->id;
        $target = $lifecycle->registerDraft($manifest, 'future-creator');
        $windows = [];
        foreach (['a', 'b', 'c'] as $key) $windows[$key] = ['window_key' => $key, 'dataset_sha256' => str_repeat($key, 64)];
        $plan = ['purpose' => 'independent', 'version_id' => $target->id, 'manifest_hash' => $target->manifest_hash,
            'windows' => $windows, 'arms' => ['solo' => ['kind' => 'solo', 'model_version_id' => $models['solo']->id],
                'champion' => ['kind' => 'champion', 'model_version_id' => $models['champion']->id]]];
        DB::table('specialist_council_evaluation_plans')->insert(['specialist_council_version_id' => $target->id,
            'evaluator_id' => 'independent-fixture-examiner', 'plan' => json_encode($plan),
            'plan_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($plan), 'sealed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $target->update(['state' => 'evaluating']);
        $receipt = ResearchExperimentReceipt::create(['receipt_key' => 'panel-fixture-source', 'source_type' => 'synthetic_panel_guard',
            'source_id' => $parent->id, 'symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5',
            'contract_version' => 'fixture', 'rule_version' => 'fixture', 'contract_hash' => str_repeat('a', 64),
            'evidence_hash' => str_repeat('b', 64), 'classification' => 'data_missing', 'payload' => ['synthetic_guard_only' => true]]);
        $work = ResearchExperimentWorkItem::create(['work_key' => 'panel-fixture', 'work_type' => 'specialist_council_independent_validation',
            'research_experiment_receipt_id' => $receipt->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'status' => 'blocked', 'payload' => ['executable' => false]]);
        return [$parent, $target->fresh(), $work, $plan, $models];
    }
}
