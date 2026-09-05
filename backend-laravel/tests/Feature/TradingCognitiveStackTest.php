<?php

namespace Tests\Feature;

use App\Services\TradingCognitiveStackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradingCognitiveStackTest extends TestCase
{
    use RefreshDatabase;

    public function test_stack_routes_a_trade_through_all_brains_and_keeps_learning_separate(): void
    {
        $plan = app(TradingCognitiveStackService::class)->plan('XAUUSD', 'M15', [
            'regime' => 'trend_up', 'm15_regime' => 'trend_up', 'session' => 'london',
            'volatility' => 'normal', 'spread_atr_ratio' => .10, 'feed_healthy' => true,
        ], ['strategy_id' => 'fibonacci_structure_pullback', 'mastery_stage' => 'validated_specialist', 'innovation_allowed' => true]);

        $this->assertSame('trading_cognitive_stack_v1', $plan['protocol']);
        $this->assertSame('TRADE', $plan['decision']);
        $this->assertSame('bounded_innovation_shadow', $plan['innovation_manager']['mode']);
        $this->assertTrue($plan['risk_sentinel']['guards']['martingale'] === 'forbidden');
        $this->assertTrue($plan['learning_reflector']['exact_control_required']);
        $this->assertFalse($plan['invariants']['promotion_evidence']);
        $this->assertSame('fibonacci_structure_pullback', $plan['strategy_proposer']['strategy_id']);
        $this->assertNotEmpty($plan['strategy_proposer']['alternatives']);
        $this->assertTrue($plan['council_governor']['same_data_hash_required']);
        $this->assertSame(17, data_get($plan, 'strategy_proposer.professional_playbook_toolbox.catalogue_size'));
        $this->assertCount(3, data_get($plan, 'instrument_composer.professional_research_playbooks'));
        $this->assertSame('bounded_shadow_window', data_get($plan, 'strategy_proposer.professional_playbook_toolbox.creative_window.status'));
        $this->assertSame('individual_playbook_conflict_resolution_v1', data_get($plan, 'instrument_composer.model_dispatch_policy.protocol'));
    }

    public function test_stack_abstains_on_hazard_and_never_lets_innovation_bypass_it(): void
    {
        $plan = app(TradingCognitiveStackService::class)->plan('XAUUSD', 'M15', [
            'regime' => 'trend_up', 'session' => 'london', 'volatility' => 'high',
            'spread_atr_ratio' => .10, 'feed_healthy' => true, 'news_risk' => true,
        ], ['mastery_stage' => 'validated_specialist', 'innovation_allowed' => true]);

        $this->assertSame('WAIT', $plan['decision']);
        $this->assertContains('NEWS_RISK', $plan['reason_codes']);
        $this->assertContains('HIGH_VOLATILITY_FIREWALL', $plan['reason_codes']);
        $this->assertFalse($plan['invariants']['promotion_evidence']);
        $this->assertFalse($plan['innovation_manager']['live_execution']);
    }

    public function test_brain_contract_has_explicit_authority_order_and_data_boundary(): void
    {
        $contract = app(TradingCognitiveStackService::class)->brainContract();

        $this->assertSame('trading_cognitive_stack_v1', $contract['protocol']);
        $this->assertContains('execution_quality_monitor', $contract['brains']);
        $this->assertSame('risk_sentinel', $contract['authority_order'][1]);
        $this->assertContains('paired_replay', $contract['control_flow']);
        $this->assertContains('independent_confirmation', $contract['control_flow']);
    }

    public function test_setup_does_not_execute_when_confirmation_entry_contract_has_no_trigger(): void
    {
        $checks = array_fill_keys([
            'context', 'location', 'setup', 'confirmation', 'trigger',
            'invalidation', 'reward_space', 'chase', 'event',
        ], true);
        $checks['trigger'] = false;
        $plan = app(TradingCognitiveStackService::class)->plan('XAUUSD', 'M15', [
            'regime' => 'trend_up', 'm15_regime' => 'trend_up', 'session' => 'london',
            'volatility' => 'normal', 'spread_atr_ratio' => .10, 'feed_healthy' => true,
            'entry_contract' => [
                'protocol' => 'confirmation_entry_contract_v1', 'model' => 'trend_continuation',
                'mode' => 'balanced', 'status' => 'trigger_missing', 'checks' => $checks,
            ],
        ], ['strategy_id' => 'fibonacci_structure_pullback']);

        $this->assertSame('WAIT', $plan['decision']);
        $this->assertContains('ENTRY_TRIGGER_MISSING', $plan['reason_codes']);
        $this->assertSame('trigger_confirmed', data_get($plan, 'causal_edge_accounting.opportunity_funnel.terminal_stage'));
    }

    public function test_required_entry_contract_cannot_fall_back_to_legacy_route(): void
    {
        $plan = app(TradingCognitiveStackService::class)->plan('XAUUSD', 'M15', [
            'regime' => 'trend_up', 'm15_regime' => 'trend_up', 'session' => 'london',
            'volatility' => 'normal', 'spread_atr_ratio' => .10, 'feed_healthy' => true,
            'entry_contract_required' => true, 'entry_contract_attested' => false,
        ], ['strategy_id' => 'confirmation_entry_mtf_v1']);

        $this->assertSame('WAIT', $plan['decision']);
        $this->assertContains('ENTRY_CONTRACT_REQUIRED', $plan['reason_codes']);
        $this->assertSame('setup_detected', data_get($plan, 'causal_edge_accounting.opportunity_funnel.terminal_stage'));
    }

    public function test_fill_time_reward_veto_is_visible_in_the_cognitive_funnel(): void
    {
        $checks = array_fill_keys([
            'context', 'location', 'setup', 'confirmation', 'trigger',
            'invalidation', 'reward_space', 'chase', 'event',
        ], true);
        $context = [
            'regime' => 'trend_up', 'm15_regime' => 'trend_up', 'session' => 'london',
            'volatility' => 'normal', 'spread_atr_ratio' => .10, 'feed_healthy' => true,
            'entry_contract_required' => true, 'entry_contract_attested' => true,
            'entry_contract' => [
                'protocol' => 'confirmation_entry_contract_v1',
                'model' => 'trend_continuation', 'mode' => 'balanced',
                'direction' => 'BUY', 'status' => 'entry_ready', 'stage' => 'entry',
                'order_type' => 'market_after_retest_close', 'checks' => $checks,
                'confirmation' => [
                    'families' => ['price_reaction', 'market_structure', 'volatility_participation'],
                    'independent_count' => 3, 'raw_count' => 5, 'redundancy_penalty' => 2,
                ],
                'reference_price' => 100, 'invalidation_price' => 98,
                'target_reference_price' => 104, 'trigger_anchor_price' => 99,
                'structure_atr' => 1, 'reward_space_r' => 2, 'chase_distance_atr' => 1,
            ],
        ];
        $missing = app(TradingCognitiveStackService::class)->plan(
            'XAUUSD', 'M15', $context, ['strategy_id' => 'confirmation_entry_mtf_v1'],
        );
        $plan = app(TradingCognitiveStackService::class)->plan('XAUUSD', 'M15', [
            ...$context,
            'entry_fill_admission' => [
                'allowed' => false, 'reason' => 'entry_contract_fill_reward_space',
            ],
        ], ['strategy_id' => 'confirmation_entry_mtf_v1']);

        $this->assertSame('WAIT', $missing['decision']);
        $this->assertContains('ENTRY_FILL_ADMISSION_REQUIRED', $missing['reason_codes']);
        $this->assertSame('spread_and_cost_valid', data_get($missing, 'causal_edge_accounting.opportunity_funnel.terminal_stage'));
        $this->assertSame('WAIT', $plan['decision']);
        $this->assertSame('spread_and_cost_valid', data_get($plan, 'causal_edge_accounting.opportunity_funnel.terminal_stage'));
        $this->assertContains('entry_contract_fill_reward_space', $plan['reason_codes']);
    }
}
