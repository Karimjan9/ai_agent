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
    }
}
