<?php

namespace Tests\Feature;

use App\Services\StrategyResearchCatalogueService;
use Tests\TestCase;

class StrategyResearchCatalogueTest extends TestCase
{
    public function test_catalogue_separates_seventeen_playbooks_from_reusable_modules(): void
    {
        $catalogue = app(StrategyResearchCatalogueService::class)->catalogue();

        $this->assertSame(StrategyResearchCatalogueService::PROTOCOL, $catalogue['protocol']);
        $this->assertCount(17, $catalogue['models']);
        $this->assertContains('breaker_fvg_overlap_unicorn', $catalogue['reusable_modules']);
        $this->assertContains('one_playbook_plus_declared_modules_per_trial', $catalogue['research_rules']);
        $this->assertContains('setup_confirmation_and_trigger_are_distinct_events', $catalogue['research_rules']);
        $this->assertContains('reward_space_gate', $catalogue['reusable_modules']);
        $this->assertSame('liquidity_trap_mtf', $catalogue['models'][0]['id']);
    }

    public function test_cross_market_and_adaptive_models_have_explicit_data_requirements(): void
    {
        $catalogue = app(StrategyResearchCatalogueService::class);

        $this->assertContains('related_market', $catalogue->model('smt_sweep_mss')['required_streams']);
        $this->assertContains('htf_conflict_without_M15_confirmation', $catalogue->model('adaptive_timeframe_confirmation')['no_trade']);
        $this->assertSame('M5_MSS_displacement', data_get($catalogue->model('confirmation_trend_continuation'), 'roles.confirmation'));
        $this->assertContains('wick_only_break', $catalogue->model('confirmation_breakout_retest')['no_trade']);
    }
}
