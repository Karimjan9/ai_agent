<?php

namespace Tests\Feature;

use App\Services\StrategyLibraryCompilerService;
use Tests\TestCase;

class StrategyLibraryCompilerTest extends TestCase
{
    public function test_compiler_keeps_strategy_composition_bounded_and_risk_owned_by_sentinel(): void
    {
        $compiled = app(StrategyLibraryCompilerService::class)->compile('mix_001_trend_beast');
        $this->assertSame('risk_sentinel', $compiled['tactic_contract']['risk_owner']);
        $this->assertTrue($compiled['mutation_contract']['one_axis_only']);
        $this->assertContains('execution_contract', $compiled['mutation_contract']['forbidden']);
        $this->assertFalse($compiled['lifecycle']['routable']);
    }

    public function test_liquidity_trap_mtf_is_a_closed_candle_shadow_research_contract(): void
    {
        $compiled = app(StrategyLibraryCompilerService::class)->compile('str_041_liquidity_trap_mtf');

        $this->assertSame(['H4', 'H1', 'M15', 'M5'], $compiled['strategy_spec']['timeframes']);
        $this->assertSame('M15_closed_liquidity_trap', $compiled['temporal_role_contract']['required_roles']['setup']);
        $this->assertSame('M5_closed_mss_or_choch_with_displacement', $compiled['temporal_role_contract']['required_roles']['trigger']);
        $this->assertSame('M15_trap_extreme_plus_cost_buffer', $compiled['temporal_role_contract']['required_roles']['invalidation']);
        $this->assertSame('SHADOW', $compiled['lifecycle']['state']);
        $this->assertFalse($compiled['lifecycle']['routable']);
        $this->assertNull(app(StrategyLibraryCompilerService::class)->runtime('str_041_liquidity_trap_mtf'));
    }
}
