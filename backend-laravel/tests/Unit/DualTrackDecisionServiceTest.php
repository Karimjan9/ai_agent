<?php

namespace Tests\Unit;

use App\Services\CapabilityCellRouterService;
use App\Services\ChampionCouncilCanaryRouterService;
use App\Services\DualTrackDecisionService;
use PHPUnit\Framework\TestCase;

class DualTrackDecisionServiceTest extends TestCase
{
    public function test_opposite_action_is_fail_closed_to_wait(): void
    {
        $result = (new DualTrackDecisionService())->evaluate(
            ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'market_regime' => 'trend_up', 'volatility_regime' => 'normal'],
            ['decision' => 'BUY', 'confidence' => .9],
            ['decision' => 'SELL', 'confidence' => .8],
            ['incumbent_decision' => 'BUY'],
        );

        $this->assertSame('WAIT', $result['selected_decision']);
        $this->assertSame('wait', $result['status']);
        $this->assertSame('OPPOSITE_ACTIONS', $result['disagreement_code']);
        $this->assertFalse($result['promotion_evidence']);
    }

    public function test_agreement_records_hybrid_complementarity_without_promoting(): void
    {
        $result = (new DualTrackDecisionService())->evaluate(
            ['symbol' => 'EURUSD', 'timeframe' => 'M15', 'market_regime' => 'range', 'volatility_regime' => 'low'],
            ['decision' => 'BUY', 'confidence' => .7],
            ['decision' => 'BUY', 'confidence' => .8],
        );

        $this->assertSame('hybrid', $result['route']);
        $this->assertSame('BUY', $result['selected_decision']);
        $this->assertSame(1.0, $result['scores']['complementarity']);
        $this->assertFalse($result['promotion_evidence']);
    }

    public function test_failed_constitution_blocks_both_lanes(): void
    {
        $result = (new DualTrackDecisionService())->evaluate(
            ['symbol' => 'GBPUSD', 'timeframe' => 'H1'],
            ['decision' => 'BUY', 'confidence' => .9],
            ['decision' => 'BUY', 'confidence' => .9],
            ['constitution_integrity' => false],
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('WAIT', $result['selected_decision']);
        $this->assertContains('constitution_integrity', $result['hard_gate']['failed']);
    }

    public function test_missing_lane_output_is_fail_closed(): void
    {
        $result = (new DualTrackDecisionService())->evaluate(
            ['symbol' => 'GBPUSD', 'timeframe' => 'H1'],
            [],
            ['decision' => 'WAIT'],
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('WAIT', $result['selected_decision']);
        $this->assertContains('champion_output_present', $result['hard_gate']['failed']);
    }

    public function test_cell_router_is_incumbent_owned_in_shadow_mode(): void
    {
        $router = new CapabilityCellRouterService(new ChampionCouncilCanaryRouterService(), new \App\Services\DualTrackCellPolicyService());

        $result = $router->decide(
            ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'market_regime' => 'trend_up', 'volatility_regime' => 'normal'],
            ['decision' => 'PROMOTE_COUNCIL', 'council_canary_share' => 1],
            'same-event',
        );

        $this->assertSame('incumbent', $result['route']);
        $this->assertTrue($result['observation_only']);
        $this->assertFalse($result['promotion_evidence']);
    }
}
