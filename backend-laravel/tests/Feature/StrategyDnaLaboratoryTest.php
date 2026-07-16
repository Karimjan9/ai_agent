<?php

namespace Tests\Feature;

use App\Models\ModelVersion;
use App\Models\StrategyDnaProfile;
use App\Models\StrategyScore;
use App\Models\TrainingSession;
use App\Services\AgentEvolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StrategyDnaLaboratoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_strategy_dna_profiles_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('strategy_dna_profiles'));
        $this->assertTrue(Schema::hasColumns('strategy_dna_profiles', [
            'strategy_score_id',
            'aggression_score',
            'trend_dependency',
            'range_dependency',
            'volatility_sensitivity',
            'adaptability_score',
            'recovery_score',
            'survival_score',
            'dna_summary',
        ]));
    }

    public function test_dna_laboratory_page_shows_profile_leaders(): void
    {
        $score = StrategyScore::create([
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy' => 'ema_rsi_v8',
            'score' => 88,
            'raw_result' => [],
        ]);

        StrategyDnaProfile::create([
            'strategy_score_id' => $score->id,
            'aggression_score' => 72,
            'trend_dependency' => 91,
            'range_dependency' => 18,
            'volatility_sensitivity' => 42,
            'adaptability_score' => 84,
            'recovery_score' => 78,
            'survival_score' => 88,
            'dna_summary' => 'EMA RSI V8 is a trend-focused medium-risk strategy.',
        ]);

        $this->get(route('strategy-lab.dna-laboratory'))
            ->assertOk()
            ->assertSee('DNA Laboratory')
            ->assertSee('Most Aggressive Agent')
            ->assertSee('EMA_RSI_V8')
            ->assertSee('EMA RSI V8 is a trend-focused medium-risk strategy.');
    }

    public function test_evolution_proposal_uses_strategy_dna_problem(): void
    {
        ModelVersion::create([
            'name' => 'ema_rsi_v8',
            'strategy' => 'ema_rsi_v8',
            'version' => 'v8',
            'generation' => 8,
            'status' => 'testing',
            'parameters' => ['ema_fast' => 50],
            'metadata' => [],
        ]);

        $session = TrainingSession::create([
            'title' => 'DNA Session',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'agents_count' => 1,
            'best_strategy' => 'ema_rsi_v8',
            'best_score' => 62,
            'worst_strategy' => 'ema_rsi_v8',
            'worst_score' => 62,
            'total_trades' => 100,
            'average_winrate' => 58,
            'average_profit' => 10,
            'raw_leaderboard' => [],
        ]);

        $score = StrategyScore::create([
            'training_session_id' => $session->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy' => 'ema_rsi_v8',
            'score' => 62,
            'raw_result' => [],
        ]);

        StrategyDnaProfile::create([
            'strategy_score_id' => $score->id,
            'aggression_score' => 50,
            'trend_dependency' => 97,
            'range_dependency' => 3,
            'volatility_sensitivity' => 30,
            'adaptability_score' => 60,
            'recovery_score' => 70,
            'survival_score' => 75,
            'dna_summary' => 'Trend dependency is too high.',
        ]);

        $proposal = app(AgentEvolutionService::class)->createProposalFromSession($session);

        $this->assertNotNull($proposal);
        $this->assertSame('excessive_trend_dependency', $proposal->main_problem);
        $this->assertStringContainsString('trend dependency', $proposal->reason);
        $this->assertArrayNotHasKey('range_capability', $proposal->new_parameters);
        $this->assertArrayHasKey('ema_fast', $proposal->new_parameters);
        $this->assertDatabaseCount('evolution_proposals', 5);

        app(AgentEvolutionService::class)->createProposalFromSession($session);
        $this->assertDatabaseCount('evolution_proposals', 5);
    }
}
