<?php

namespace Tests\Feature;

use App\Models\KnowledgeClaim;
use App\Models\KnowledgeGraphEdge;
use App\Models\KnowledgeGraphNode;
use App\Models\KnowledgeMiningRun;
use App\Models\KnowledgeQuery;
use App\Models\MarketSpecies;
use App\Models\StrategyScore;
use App\Models\StrategySpeciesPerformance;
use App\Models\TrainingSession;
use App\Services\UniversalKnowledgeGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class UniversalKnowledgeGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_training_session_builds_universal_knowledge_graph(): void
    {
        [$session] = $this->createGraphEvidence();

        app(UniversalKnowledgeGraphService::class)->recordTrainingSession($session);

        $this->assertDatabaseHas('knowledge_graph_nodes', [
            'node_type' => 'strategy',
            'node_key' => 'strategy:ema_rsi_v12',
        ]);
        $this->assertDatabaseHas('knowledge_graph_nodes', [
            'node_type' => 'market_species',
            'node_key' => 'market_species:SPC_TEST41',
        ]);
        $this->assertDatabaseHas('knowledge_graph_edges', [
            'relation_type' => 'PERFORMS_IN_MARKET_SPECIES',
        ]);
        $this->assertDatabaseHas('knowledge_claims', [
            'claim_type' => 'strategy_species_performance',
        ]);

        $this->assertGreaterThan(0, KnowledgeGraphNode::count());
        $this->assertGreaterThan(0, KnowledgeGraphEdge::count());
        $this->assertGreaterThan(0, KnowledgeClaim::count());
        $this->assertGreaterThan(0, KnowledgeMiningRun::count());
    }

    public function test_research_assistant_answers_from_graph_and_dashboard_renders(): void
    {
        [$session] = $this->createGraphEvidence();
        app(UniversalKnowledgeGraphService::class)->recordTrainingSession($session);

        $query = app(UniversalKnowledgeGraphService::class)->answer('Why did ema_rsi_v12 become successful?');

        $this->assertStringContainsString('EMA_RSI_V12', $query->answer);
        $this->assertGreaterThan(0, KnowledgeQuery::count());

        $this->get(route('knowledge-center.index', ['q' => 'Why did ema_rsi_v12 become successful?']))
            ->assertOk()
            ->assertSee('Knowledge Center')
            ->assertSee('Research Assistant')
            ->assertSee('Knowledge Graph')
            ->assertSee('Failure Analysis')
            ->assertSee('Pattern Explorer');
    }

    public function test_knowledge_mining_command_runs(): void
    {
        $this->createGraphEvidence();

        Artisan::call('knowledge:mine');

        $this->assertDatabaseHas('knowledge_mining_runs', [
            'status' => 'success',
        ]);
    }

    private function createGraphEvidence(): array
    {
        $session = TrainingSession::create([
            'title' => 'Knowledge Graph Session',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'agents_count' => 1,
            'best_strategy' => 'ema_rsi_v12',
            'best_score' => 88,
            'worst_strategy' => 'breakout_v3',
            'worst_score' => 31,
            'total_trades' => 24,
            'average_winrate' => 70,
            'average_profit' => 6.4,
            'average_drawdown' => 4.2,
            'average_profit_factor' => 2.1,
            'average_stability_score' => 82,
            'ai_conclusion' => 'EMA RSI did well in a specific species.',
            'next_training_plan' => 'Mine graph links.',
            'raw_leaderboard' => [],
            'status' => 'completed',
        ]);

        $score = StrategyScore::create([
            'training_session_id' => $session->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy' => 'ema_rsi_v12',
            'parameters' => ['ema_fast' => 45, 'ema_slow' => 200, 'rsi_buy_min' => 55, 'rsi_buy_max' => 65],
            'score' => 88,
            'train_score' => 85,
            'validation_score' => 83,
            'forward_score' => 81,
            'robustness_score' => 84,
            'is_overfit' => false,
            'total_trades' => 24,
            'wins' => 17,
            'losses' => 7,
            'winrate' => 70.83,
            'net_profit_percent' => 6.4,
            'max_drawdown_percent' => 4.2,
            'profit_factor' => 2.1,
            'stability_score' => 82,
            'raw_result' => [],
        ]);

        $species = MarketSpecies::create([
            'code' => 'SPC_TEST41',
            'name' => 'Fear Expansion',
            'dominant_state' => 'panic',
            'description' => 'Test species for graph links.',
            'danger_score' => 72,
            'opportunity_score' => 66,
            'signature' => ['market_state' => 'panic'],
        ]);

        StrategySpeciesPerformance::create([
            'market_species_id' => $species->id,
            'strategy_score_id' => $score->id,
            'training_session_id' => $session->id,
            'strategy' => 'ema_rsi_v12',
            'species_code' => $species->code,
            'species_name' => $species->name,
            'trades' => 24,
            'winrate' => 70.83,
            'profit_percent' => 6.4,
            'confidence_score' => 83,
            'evidence' => ['profit_factor' => 2.1],
        ]);

        return [$session, $score, $species];
    }
}
