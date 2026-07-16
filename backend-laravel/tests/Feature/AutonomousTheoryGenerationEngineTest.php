<?php

namespace Tests\Feature;

use App\Models\CausalEdge;
use App\Models\CausalNode;
use App\Models\CausalRootCause;
use App\Models\QuantLaw;
use App\Models\QuantTheory;
use App\Models\TheoryBattle;
use App\Models\TheoryComponent;
use App\Models\TheoryEvolutionEvent;
use App\Models\TheoryGenerationRun;
use App\Models\TheoryPrediction;
use App\Models\UnifiedQuantModel;
use App\Services\AutonomousTheoryGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AutonomousTheoryGenerationEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_theory_generation_creates_theories_battles_predictions_and_unified_models(): void
    {
        $this->seedTheoryEvidence();

        $run = app(AutonomousTheoryGenerationService::class)->generate();

        $this->assertNotNull($run);
        $this->assertGreaterThan(0, QuantTheory::count());
        $this->assertGreaterThan(0, TheoryComponent::count());
        $this->assertGreaterThan(0, TheoryBattle::count());
        $this->assertGreaterThan(0, TheoryPrediction::count());
        $this->assertGreaterThan(0, TheoryEvolutionEvent::count());
        $this->assertGreaterThan(0, UnifiedQuantModel::count());
    }

    public function test_theory_lab_dashboard_and_manual_generation_work(): void
    {
        $this->seedTheoryEvidence();

        $this->post(route('theory-lab.generate'))
            ->assertRedirect(route('theory-lab.index'));

        $this->get(route('theory-lab.index'))
            ->assertOk()
            ->assertSee('Theory Lab')
            ->assertSee('Autonomous Theory Generation')
            ->assertSee('Theory Battles')
            ->assertSee('Unified Models');
    }

    public function test_theory_generation_command_runs(): void
    {
        $this->seedTheoryEvidence();

        Artisan::call('theory:generate');

        $this->assertGreaterThan(0, TheoryGenerationRun::count());
        $this->assertStringContainsString('Theory generation', Artisan::output());
    }

    private function seedTheoryEvidence(): void
    {
        $adaptabilityLaw = QuantLaw::create([
            'law_key' => 'law:trend_dependency:adaptability_decay',
            'title' => 'High trend dependency reduces long-term adaptability',
            'statement' => 'trend dependency reduces adaptability across strategies.',
            'law_type' => 'adaptability_law',
            'status' => 'active',
            'confidence_score' => 91,
            'universality_score' => 72,
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

        $recoveryLaw = QuantLaw::create([
            'law_key' => 'law:recovery_speed:future_survival',
            'title' => 'High recovery speed improves future survival',
            'statement' => 'recovery speed improves future survival after adverse regimes.',
            'law_type' => 'survival_law',
            'status' => 'active',
            'confidence_score' => 84,
            'universality_score' => 65,
            'effect_size' => 24,
            'evidence_count' => 18,
            'strategy_count' => 3,
            'species_count' => 2,
            'session_count' => 7,
            'trade_count' => 260,
            'first_seen_at' => now(),
            'last_validated_at' => now(),
            'scope' => [
                'driver' => 'recovery_speed',
                'target' => 'future_survival',
                'direction' => 'positive',
            ],
            'metadata' => [],
        ]);

        $regimeLaw = QuantLaw::create([
            'law_key' => 'law:regime_awareness:knowledge_transfer',
            'title' => 'Regime awareness improves knowledge transfer',
            'statement' => 'regime awareness prevents laws from being used outside valid market species.',
            'law_type' => 'market_context_law',
            'status' => 'active',
            'confidence_score' => 80,
            'universality_score' => 61,
            'effect_size' => 20,
            'evidence_count' => 15,
            'strategy_count' => 3,
            'species_count' => 3,
            'session_count' => 6,
            'trade_count' => 240,
            'first_seen_at' => now(),
            'last_validated_at' => now(),
            'scope' => [
                'driver' => 'regime_awareness',
                'target' => 'knowledge_transfer',
                'direction' => 'positive',
            ],
            'metadata' => [],
        ]);

        $adaptability = CausalNode::create([
            'node_key' => 'causal_node:adaptability',
            'label' => 'adaptability',
            'node_type' => 'outcome',
            'description' => 'Adaptability outcome.',
            'confidence_score' => 90,
            'metadata' => [],
        ]);
        $trendDependency = CausalNode::create([
            'node_key' => 'causal_node:trend_dependency',
            'label' => 'trend dependency',
            'node_type' => 'driver',
            'description' => 'Trend dependency driver.',
            'confidence_score' => 90,
            'metadata' => [],
        ]);
        $recoverySpeed = CausalNode::create([
            'node_key' => 'causal_node:recovery_speed',
            'label' => 'recovery speed',
            'node_type' => 'driver',
            'description' => 'Recovery speed driver.',
            'confidence_score' => 84,
            'metadata' => [],
        ]);
        $futureSurvival = CausalNode::create([
            'node_key' => 'causal_node:future_survival',
            'label' => 'future survival',
            'node_type' => 'outcome',
            'description' => 'Future survival outcome.',
            'confidence_score' => 84,
            'metadata' => [],
        ]);

        CausalEdge::create([
            'source_node_id' => $trendDependency->id,
            'target_node_id' => $adaptability->id,
            'quant_law_id' => $adaptabilityLaw->id,
            'edge_key' => 'causal:trend_dependency:adaptability:test',
            'direction' => 'negative',
            'identification_status' => 'provisionally_identified',
            'causality_score' => 82,
            'correlation_score' => 91,
            'effect_size' => 31,
            'evidence_count' => 24,
            'rationale' => 'Test causal evidence.',
            'assumptions' => [],
            'metadata' => [],
        ]);

        CausalEdge::create([
            'source_node_id' => $recoverySpeed->id,
            'target_node_id' => $futureSurvival->id,
            'quant_law_id' => $recoveryLaw->id,
            'edge_key' => 'causal:recovery_speed:future_survival:test',
            'direction' => 'positive',
            'identification_status' => 'partially_identified',
            'causality_score' => 73,
            'correlation_score' => 84,
            'effect_size' => 24,
            'evidence_count' => 18,
            'rationale' => 'Test recovery evidence.',
            'assumptions' => [],
            'metadata' => [],
        ]);

        CausalRootCause::create([
            'cause_key' => 'root:trend_dependency:test',
            'title' => 'trend dependency',
            'summary' => 'trend dependency is a root-cause candidate for adaptability.',
            'impact_score' => 82,
            'confidence_score' => 82,
            'rank' => 1,
            'status' => 'active',
            'metadata' => ['target' => 'adaptability'],
        ]);

        CausalRootCause::create([
            'cause_key' => 'root:recovery_speed:test',
            'title' => 'recovery speed',
            'summary' => 'recovery speed is a root-cause candidate for future survival.',
            'impact_score' => 73,
            'confidence_score' => 73,
            'rank' => 2,
            'status' => 'active',
            'metadata' => ['target' => 'future_survival'],
        ]);

        CausalRootCause::create([
            'cause_key' => 'root:regime_awareness:test',
            'title' => 'regime awareness',
            'summary' => 'regime awareness is a root-cause candidate for knowledge transfer.',
            'impact_score' => 68,
            'confidence_score' => 76,
            'rank' => 3,
            'status' => 'active',
            'metadata' => ['target' => 'knowledge_transfer', 'law_id' => $regimeLaw->id],
        ]);
    }
}
