<?php

namespace Tests\Feature;

use App\Services\ContextualInstrumentBundleGraphService;
use App\Services\InstrumentLearningMonitorService;
use App\Services\InstrumentResearchWindowService;
use App\Services\LabInstrumentResearchService;
use App\Services\TradeManagementLibraryService;
use App\Services\TradingInstrumentOperatingSystemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstrumentCapabilityReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_capabilities_are_code_owned_and_read_only_not_distinct_algorithm_claims(): void
    {
        $os = app(TradingInstrumentOperatingSystemService::class);
        $cards = $os->runtimeCapabilities();
        $this->assertCount(26, $cards);
        foreach ($cards as $card) {
            $this->assertNotEmpty($card['runtime_events']);
            $this->assertSame(64, strlen($card['capability_hash']));
            $this->assertFalse($card['promotion_evidence']);
            $this->assertFalse($card['ablation_contract']['safety_gate_disable_allowed']);
        }
        $this->assertSame('structural_proxy_adapter', $os->runtimeCapability('liquidity_pool')['implementation_status']);
        $this->assertSame('declarative_only', $os->runtimeCapability('invented_unimplemented_tool')['implementation_status']);
        $this->assertDatabaseCount('trading_instruments', 0);
    }

    public function test_source_data_and_context_must_be_attested_before_learnability_is_claimed(): void
    {
        $os = app(TradingInstrumentOperatingSystemService::class);
        $params = ['volume_lane' => 'breakout_volume_confirmation'];
        $this->assertSame('unknown_data', $os->researchReadiness('volume_confirmation', $params)['status']);
        $facts = $this->facts();
        $missing = $os->researchReadiness('volume_confirmation', $params, $facts);
        $this->assertSame('data_missing', $missing['status']);
        $this->assertContains('canonical_volume', $missing['missing_data']);
        $this->assertFalse($missing['negative_skill_allowed']);
        $facts['data_available']['canonical_volume'] = true;
        $this->assertSame('learnable', $os->researchReadiness('volume_confirmation', $params, $facts)['status']);
        $facts['dataset_hash'] = '';
        $this->assertSame('unknown_data', $os->researchReadiness('volume_confirmation', $params, $facts)['status']);
    }

    public function test_exit_cannot_be_prioritized_as_powered_when_position_stage_was_never_reached(): void
    {
        $os = app(TradingInstrumentOperatingSystemService::class);
        $params = ['time_stop_candles' => 24];
        $facts = $this->facts();
        $this->assertSame('underpowered', $os->researchReadiness('cost_aware_exit', $params, $facts)['status']);
        $facts['stages']['position_management'] = true;
        $ready = $os->researchReadiness('cost_aware_exit', $params, $facts);
        $this->assertSame('learnable', $ready['status']);
        $this->assertFalse($ready['economic_credit_allowed']);
        $this->assertSame('no_effect', $os->researchReadiness('cost_aware_exit', [], $facts)['status']);
        $facts['context_opportunities'] = 3;
        $this->assertSame('underpowered', $os->researchReadiness('cost_aware_exit', $params, $facts)['status']);
    }

    public function test_learnability_priority_requires_a_control_path_and_observed_stage(): void
    {
        $os = app(TradingInstrumentOperatingSystemService::class);
        $params = ['time_stop_candles' => 24];
        $facts = $this->facts();
        $facts['stages']['position_management'] = true;
        $facts['source_instrument_evaluations'] = 24;

        $sealed = $os->researchReadiness('cost_aware_exit', $params, $facts);
        $this->assertSame('learnable', $sealed['status']);
        $this->assertSame(4, $sealed['priority']);
        $this->assertSame('sealed_exact_control', $sealed['control_path']);
        $this->assertEquals(.2, $sealed['uncertainty']);
        $this->assertFalse($sealed['economic_credit_allowed']);

        $facts['exact_control_available'] = false;
        $facts['prospective_control_feasible'] = true;
        $prospective = $os->researchReadiness('cost_aware_exit', $params, $facts);
        $this->assertSame('learnable', $prospective['status']);
        $this->assertSame(3, $prospective['priority']);
        $this->assertSame('prospective_exact_control_required', $prospective['control_path']);

        $facts['prospective_control_feasible'] = false;
        $this->assertSame(0, $os->researchReadiness('cost_aware_exit', $params, $facts)['priority']);
        $facts['exact_control_available'] = true;
        $facts['stages']['position_management'] = false;
        $unreached = $os->researchReadiness('cost_aware_exit', $params, $facts);
        $this->assertSame('underpowered', $unreached['status']);
        $this->assertSame(0, $unreached['priority']);
        $this->assertSame('REQUIRED_STAGE_NOT_OBSERVED', $unreached['reason_code']);
    }

    public function test_missing_source_evaluation_count_is_not_invented_as_uncertainty(): void
    {
        $facts = $this->facts();
        $unknown = app(TradingInstrumentOperatingSystemService::class)
            ->researchReadiness('trend_pullback', ['ema_fast' => 12], $facts);
        $this->assertNull($unknown['uncertainty']);
        $this->assertNull($unknown['source_instrument_evaluations']);

        $facts['source_instrument_evaluations'] = 0;
        $observed = app(TradingInstrumentOperatingSystemService::class)
            ->researchReadiness('trend_pullback', ['ema_fast' => 12], $facts);
        $this->assertEquals(1.0, $observed['uncertainty']);
        $this->assertSame('sealed_source_execution_exploration_only_not_economic_effect', $observed['uncertainty_scope']);
    }

    public function test_generic_breakout_genes_bind_to_the_actual_signal_owner(): void
    {
        $research = app(LabInstrumentResearchService::class);
        $os = app(TradingInstrumentOperatingSystemService::class);
        foreach (['lookback', 'atr_period', 'atr_multiplier', 'confirmation_candles', 'trend_strength_min'] as $gene) {
            $this->assertSame('breakout_retest', $research->instrumentForGene($gene, 'breakout'));
            $this->assertContains($gene, $os->runtimeCapability('breakout_retest')['parameter_surface']);
        }
        $this->assertSame('range_reentry', $research->instrumentForGene('lookback', 'mean_reversion'));
        $this->assertSame('compression_expansion', $research->instrumentForGene('lookback', 'volatility'));
        $this->assertSame('session_breakout', $research->instrumentForGene('lookback', 'session'));
        $this->assertSame('adaptive_entry_topology', $research->instrumentForGene('lookback'));
        $this->assertDatabaseCount('instrument_invocation_ledger', 0);
    }

    public function test_management_profiles_expose_simplified_engine_limits(): void
    {
        $management = app(TradeManagementLibraryService::class);
        foreach (array_keys($management->library()) as $profile) {
            $adapter = $management->runtimeAdapter($profile);
            if ($profile === 'parameter_preserving_research') {
                $this->assertSame('existing_parameter_owned_position_lifecycle', $adapter['implementation_status']);
                $this->assertSame('parameter_preserving_replay_v1', $adapter['engine']);
                $this->assertSame([], $adapter['overrides']);
                $this->assertFalse($adapter['paper_execution_authority']);
                continue;
            }
            $this->assertSame('simplified_profile_adapter', $adapter['implementation_status']);
            $this->assertSame('single_partial_runner_v1', $adapter['engine']);
            $this->assertContains('winner_pyramiding', $adapter['not_implemented_by_this_adapter']);
        }
    }

    public function test_factorial_separates_incremental_and_interfering_effects_without_authority(): void
    {
        $graph = app(ContextualInstrumentBundleGraphService::class);
        $arms = $this->arms([1, 1.3, 1.2, 1.4]);
        $result = $graph->controlledEffects($arms);
        $this->assertSame('controlled_contrast', $result['status']);
        $this->assertEquals(-.1, $result['effects']['interaction_effect']);
        $this->assertEquals(.4, $result['effects']['whole_capsule_effect']);
        $this->assertTrue($result['independent_confirmation_required']);
        $this->assertFalse($result['promotion_evidence']);
        $arms['b_only']['data_hash'] = str_repeat('x', 64);
        $this->assertSame('factorial_controlled_evidence_incomplete', $graph->controlledEffects($arms)['status']);
    }

    public function test_factorial_missing_nonfinite_or_different_context_is_not_zero_effect(): void
    {
        $graph = app(ContextualInstrumentBundleGraphService::class);
        $baseline = $this->arms([1, 1.3, 1.2, 1.8]);
        foreach (['after_cost_value', 'mtf_bundle_hash', 'session_instance_id', 'evidence_run_id'] as $key) {
            $arms = $baseline;
            unset($arms['a_plus_b'][$key]);
            $this->assertSame([], $graph->controlledEffects($arms)['effects']);
        }
        $arms = $baseline; $arms['a_only']['after_cost_value'] = INF;
        $this->assertSame([], $graph->controlledEffects($arms)['effects']);
        $arms = $baseline; $arms['a_only']['context_cell_key'] = 'other-phase';
        $this->assertSame([], $graph->controlledEffects($arms)['effects']);
    }

    public function test_monitor_reports_aliases_retention_and_data_dependency_without_seeding_or_credit(): void
    {
        $snapshot = app(InstrumentLearningMonitorService::class)->snapshot();
        $inventory = $snapshot['block_1_candidate_inventory'];
        $this->assertGreaterThan($inventory['strategy_library']['distinct_runtime_keys'], $inventory['strategy_library']['total']);
        $this->assertSame(2, $inventory['executable_capabilities']['distinct_management_engines']);
        $this->assertFalse($inventory['executable_capabilities']['standalone_algorithm_count_attested']);
        $this->assertSame('awaiting_authorized_research_data', $snapshot['independent_validation_dependency']['status']);
        $this->assertFalse($snapshot['independent_validation_dependency']['paper_2026_eligible']);
        $this->assertDatabaseCount('trading_instruments', 0);
        $this->assertSame([], app(InstrumentResearchWindowService::class)->readiness()['eligible_windows']);
    }

    private function facts(): array
    {
        return ['sealed_source' => true, 'dataset_hash' => str_repeat('d', 64),
            'context_key' => 'london_comex_overlap', 'context_opportunities' => 30,
            'exact_control_available' => true, 'data_available' => ['closed_ohlc' => true],
            'stages' => ['pre_entry_decision' => true]];
    }

    private function arms(array $values): array
    {
        $arms = [];
        foreach (['control', 'a_only', 'b_only', 'a_plus_b'] as $index => $arm) {
            $arms[$arm] = ['lab_agent_id' => $index + 1, 'evidence_status' => 'eligible',
                'evidence_run_id' => $arm, 'after_cost_value' => $values[$index],
                'data_hash' => str_repeat('d', 64), 'execution_hash' => str_repeat('e', 64),
                'mtf_bundle_hash' => str_repeat('m', 64), 'context_cell_key' => 'phase-cell',
                'session_instance_id' => 'same-session'];
        }
        return $arms;
    }
}
