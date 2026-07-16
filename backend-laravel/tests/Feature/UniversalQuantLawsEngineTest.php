<?php

namespace Tests\Feature;

use App\Models\KnowledgeClaim;
use App\Models\QuantLaw;
use App\Models\QuantLawCandidate;
use App\Models\QuantLawConflict;
use App\Models\QuantLawDiscoveryRun;
use App\Models\QuantLawEvidence;
use App\Models\QuantLawGraphEdge;
use App\Models\StrategyDnaProfile;
use App\Models\StrategyScore;
use App\Models\TrainingSession;
use App\Models\UniversalDriverRanking;
use App\Services\UniversalQuantLawsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class UniversalQuantLawsEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_law_discovery_creates_candidates_laws_evidence_graph_conflicts_and_drivers(): void
    {
        $this->createLawEvidence();

        $run = app(UniversalQuantLawsService::class)->discover();

        $this->assertNotNull($run);
        $this->assertDatabaseHas('quant_law_discovery_runs', [
            'id' => $run->id,
            'status' => 'success',
        ]);
        $this->assertGreaterThan(0, QuantLawCandidate::count());
        $this->assertGreaterThan(0, QuantLaw::count());
        $this->assertGreaterThan(0, QuantLawEvidence::count());
        $this->assertGreaterThan(0, QuantLawGraphEdge::count());
        $this->assertGreaterThan(0, QuantLawConflict::count());
        $this->assertGreaterThan(0, UniversalDriverRanking::count());
    }

    public function test_quant_laws_dashboard_and_manual_discovery_work(): void
    {
        $this->createLawEvidence();

        $this->post(route('quant-laws.discover'))
            ->assertRedirect(route('quant-laws.index'));

        $this->get(route('quant-laws.index'))
            ->assertOk()
            ->assertSee('Quant Laws')
            ->assertSee('Universal Laws Library')
            ->assertSee('Emerging Law Candidates')
            ->assertSee('Law Graph')
            ->assertSee('Top Drivers');
    }

    public function test_law_discovery_command_runs(): void
    {
        $this->createLawEvidence();

        Artisan::call('laws:discover');

        $this->assertGreaterThan(0, QuantLawDiscoveryRun::count());
        $this->assertStringContainsString('Quant laws discovery', Artisan::output());
    }

    private function createLawEvidence(): void
    {
        foreach (['ema_rsi_v12', 'breakout_v8', 'fibonacci_v5'] as $index => $strategy) {
            $session = TrainingSession::create([
                'title' => 'Quant Laws Session '.$index,
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'agents_count' => 1,
                'best_strategy' => $strategy,
                'best_score' => 72,
                'worst_strategy' => $strategy,
                'worst_score' => 42,
                'total_trades' => 30,
                'average_winrate' => 52,
                'average_profit' => 1.1,
                'average_drawdown' => 8.4,
                'average_profit_factor' => 1.2,
                'average_stability_score' => 58,
                'ai_conclusion' => 'Law evidence.',
                'next_training_plan' => 'Discover laws.',
                'raw_leaderboard' => [],
                'status' => 'completed',
            ]);

            $score = StrategyScore::create([
                'training_session_id' => $session->id,
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'strategy' => $strategy,
                'parameters' => ['ema_fast' => 45, 'rsi_period' => 14, 'confirmation' => 'trend', 'vol_filter' => true],
                'score' => 72,
                'robustness_score' => 54,
                'stability_score' => 55,
                'total_trades' => 30,
                'wins' => 16,
                'losses' => 14,
                'winrate' => 53,
                'net_profit_percent' => 1.1,
                'max_drawdown_percent' => 8.4,
                'profit_factor' => 1.2,
                'volatility_performance' => [
                    'low_volatility' => [
                        'net_profit_percent' => $strategy === 'breakout_v8' ? -2.4 : -0.3,
                        'winrate' => 38,
                        'trades' => 12,
                    ],
                ],
                'raw_result' => [],
            ]);

            StrategyDnaProfile::create([
                'strategy_score_id' => $score->id,
                'aggression_score' => 58,
                'trend_dependency' => 88 + $index,
                'range_dependency' => 24,
                'volatility_sensitivity' => 62,
                'adaptability_score' => 38 + $index,
                'recovery_score' => 43,
                'survival_score' => 44,
                'dna_summary' => 'High trend dependency with weak adaptability.',
            ]);
        }

        KnowledgeClaim::create([
            'title' => 'Trend dependency improves adaptability in rare trend markets',
            'claim' => 'High trend dependency improves adaptability when rare persistent trend markets dominate.',
            'claim_type' => 'strategy_behavior',
            'confidence_score' => 88,
            'evidence_count' => 12,
            'status' => 'provisional',
            'scope' => ['driver' => 'trend_dependency', 'target' => 'adaptability'],
            'metadata' => ['test' => true],
            'last_seen_at' => now(),
        ]);
    }
}
