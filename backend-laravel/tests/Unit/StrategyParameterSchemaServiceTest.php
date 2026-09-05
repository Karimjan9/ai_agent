<?php

namespace Tests\Unit;

use App\Services\StrategyParameterSchemaService;
use Tests\TestCase;

class StrategyParameterSchemaServiceTest extends TestCase
{
    public function test_composite_runtime_keeps_its_identity_when_parent_metadata_is_stale(): void
    {
        $schemas = app(StrategyParameterSchemaService::class);

        $this->assertSame(
            'differential_router_v1',
            $schemas->runtimeBaseStrategy(
                'xauusd_differential_router_g103_a01',
                'breakout_v1',
                'differential_router',
            ),
        );
        $this->assertSame(
            'regime_ensemble_v1',
            $schemas->runtimeBaseStrategy(
                'eurusd_regime_ensemble_g4_a02',
                'breakout_v1',
                'regime_ensemble',
            ),
        );
    }

    public function test_liquidity_trap_mtf_has_a_bounded_shared_schema_and_defaults(): void
    {
        $schemas = app(StrategyParameterSchemaService::class);

        $this->assertSame('liquidity_trap_mtf_v1', $schemas->runtimeBaseStrategy('liquidity_trap_mtf_v1'));
        $this->assertSame('balanced', $schemas->defaults('liquidity_trap_mtf_v1')['entry_mode']);
        $this->assertSame(30, $schemas->defaults('liquidity_trap_mtf_v1')['m15_trap_expiry_minutes']);
        $this->assertSame(
            ['entry_mode' => 'balanced', 'm15_trap_expiry_minutes' => 30],
            $schemas->validate('liquidity_trap_mtf_v1', [
                'entry_mode' => 'balanced', 'm15_trap_expiry_minutes' => 30,
            ]),
        );
    }

    public function test_confirmation_entry_matrix_has_bounded_model_mode_and_admission_gates(): void
    {
        $schemas = app(StrategyParameterSchemaService::class);
        $defaults = $schemas->defaults('confirmation_entry_mtf_v1');

        $this->assertSame('trend_continuation', $defaults['entry_model']);
        $this->assertSame('H1', $defaults['breakout_setup_timeframe']);
        $this->assertSame('balanced', $defaults['entry_mode']);
        $this->assertSame(3, $defaults['minimum_independent_confirmations']);
        $this->assertSame('all_three_simultaneous', $defaults['confirmation_family_policy']);
        $this->assertSame(1.5, $defaults['minimum_reward_space_r']);
        $this->assertSame(20.0, $defaults['h1_range_adx_max']);
        $this->assertSame([
            'entry_model' => 'breakout_retest', 'breakout_setup_timeframe' => 'M15',
            'entry_mode' => 'conservative',
            'minimum_reward_space_r' => 2.0,
        ], $schemas->validate('confirmation_entry_mtf_v1', [
            'entry_model' => 'breakout_retest', 'breakout_setup_timeframe' => 'M15',
            'entry_mode' => 'conservative',
            'minimum_reward_space_r' => 2.0,
        ]));
        $this->assertSame([
            'confirmation_family_policy' => 'structure_plus_reaction',
            'trigger_topology_policy' => 'volatility_adaptive',
            'setup_topology_policy' => 'liquidity_sweep_reclaim',
        ], $schemas->validate('confirmation_entry_mtf_v1', [
            'confirmation_family_policy' => 'structure_plus_reaction',
            'trigger_topology_policy' => 'volatility_adaptive',
            'setup_topology_policy' => 'liquidity_sweep_reclaim',
        ]));
    }

    public function test_architecture_interaction_is_an_explicit_bounded_macro_gene(): void
    {
        $schemas = app(StrategyParameterSchemaService::class);

        $this->assertSame('frozen', $schemas->defaults('hybrid')['architecture_interaction_variant']);
        $this->assertSame([
            'architecture_interaction_variant' => 'state_classifier_coherence_v1',
        ], $schemas->validate('hybrid', [
            'architecture_interaction_variant' => 'state_classifier_coherence_v1',
        ]));
    }
}
