<?php

namespace Tests\Feature;

use App\Services\TacticCatalogueService;
use Tests\TestCase;

class TacticCatalogueTest extends TestCase
{
    public function test_popular_tactic_is_a_research_contract_with_one_gene_repair_rules(): void
    {
        $contract = app(TacticCatalogueService::class)->for(
            'volatility',
            'volatility_breakout',
            'stress_cost',
        );

        $this->assertSame(TacticCatalogueService::PROTOCOL, $contract['protocol']);
        $this->assertSame('atr_squeeze_donchian_expansion', $contract['tactic_id']);
        $this->assertContains('stress_cost', $contract['repair_lanes']);
        $this->assertFalse($contract['promotion_evidence']);
        $this->assertSame('passed', app(TacticCatalogueService::class)->alignment(
            $contract,
            'stress_cost',
            'atr_stop_multiplier',
        )['status']);
    }

    public function test_unknown_tactic_cannot_claim_single_gene_alignment(): void
    {
        $contract = app(TacticCatalogueService::class)->for('custom', 'unknown_architecture', 'regime_coverage');

        $alignment = app(TacticCatalogueService::class)->alignment($contract, 'regime_coverage', 'not_declared');

        $this->assertSame('failed', $alignment['status']);
        $this->assertSame('gene_not_declared_for_tactic', $alignment['reason']);
    }

    public function test_differential_child_inherits_parent_architecture_but_keeps_differential_contract(): void
    {
        $contract = app(TacticCatalogueService::class)->for(
            'differential_router',
            'regime_router',
            'opportunity_recall',
        );

        $this->assertSame('differential_regime_specialist', $contract['tactic_id']);
        $this->assertSame('passed', app(TacticCatalogueService::class)->alignment(
            $contract,
            'opportunity_recall',
            'differential_target_min_signal_confidence',
        )['status']);
    }
}
