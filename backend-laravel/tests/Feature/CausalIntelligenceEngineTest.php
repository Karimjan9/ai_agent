<?php

namespace Tests\Feature;

use App\Models\CausalCounterfactual;
use App\Models\CausalDiscoveryRun;
use App\Models\CausalEdge;
use App\Models\CausalEffectEstimate;
use App\Models\CausalExperiment;
use App\Models\CausalIntervention;
use App\Models\CausalNode;
use App\Models\CausalRootCause;
use App\Models\DiscoveryQualityScore;
use App\Models\QuantLaw;
use App\Services\CausalIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CausalIntelligenceEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_causal_discovery_creates_graph_counterfactuals_interventions_experiments_and_quality_scores(): void
    {
        $this->createQuantLaw();

        $run = app(CausalIntelligenceService::class)->discover();

        $this->assertNotNull($run);
        $this->assertGreaterThan(0, CausalNode::count());
        $this->assertGreaterThan(0, CausalEdge::count());
        $this->assertGreaterThan(0, CausalEffectEstimate::count());
        $this->assertGreaterThan(0, CausalCounterfactual::count());
        $this->assertGreaterThan(0, CausalIntervention::count());
        $this->assertGreaterThan(0, CausalExperiment::count());
        $this->assertGreaterThan(0, CausalRootCause::count());
        $this->assertGreaterThan(0, DiscoveryQualityScore::count());
    }

    public function test_causal_intelligence_dashboard_and_manual_discovery_work(): void
    {
        $this->createQuantLaw();

        $this->post(route('causal-intelligence.discover'))
            ->assertRedirect(route('causal-intelligence.index'));

        $this->get(route('causal-intelligence.index'))
            ->assertOk()
            ->assertSee('Causal Intelligence')
            ->assertSee('Causal Graph')
            ->assertSee('Root Causes')
            ->assertSee('Counterfactual Laboratory')
            ->assertSee('Discovery Quality');
    }

    public function test_causal_discovery_command_runs(): void
    {
        $this->createQuantLaw();

        Artisan::call('causal:discover');

        $this->assertGreaterThan(0, CausalDiscoveryRun::count());
        $this->assertStringContainsString('Causal discovery', Artisan::output());
    }

    private function createQuantLaw(): void
    {
        QuantLaw::create([
            'law_key' => 'law:trend_dependency:adaptability_decay',
            'title' => 'High trend dependency reduces long-term adaptability',
            'statement' => 'trend dependency reduces adaptability; confidence 91%, universality 68%.',
            'law_type' => 'adaptability_law',
            'status' => 'active',
            'confidence_score' => 91,
            'universality_score' => 68,
            'effect_size' => 31,
            'evidence_count' => 24,
            'strategy_count' => 4,
            'species_count' => 2,
            'session_count' => 8,
            'trade_count' => 320,
            'first_seen_at' => now(),
            'last_validated_at' => now(),
            'scope' => [
                'driver' => 'trend_dependency',
                'target' => 'adaptability',
                'direction' => 'negative',
            ],
            'metadata' => [],
        ]);
    }
}
