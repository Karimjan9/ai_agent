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
}
