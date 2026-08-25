<?php

namespace Tests\Feature;

use App\Models\KnowledgePrior;
use App\Services\PriorKnowledgeVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriorKnowledgeVaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_priors_are_seeded_as_proposal_only_and_decay_with_local_evidence(): void
    {
        $vault = app(PriorKnowledgeVaultService::class);
        $result = $vault->seed();

        $this->assertSame('seeded', $result['status']);
        $this->assertSame(8, KnowledgePrior::query()->count());
        $cold = $vault->proposalBias('prior_trend_pullback_001', 0);
        $local = $vault->proposalBias('prior_trend_pullback_001', 24);
        $this->assertFalse($cold['authority']['runtime_trade']);
        $this->assertFalse($cold['authority']['promotion']);
        $this->assertLessThan($cold['proposal_bias_weight'], $local['proposal_bias_weight']);
    }
}
