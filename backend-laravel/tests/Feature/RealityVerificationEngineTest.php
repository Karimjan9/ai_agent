<?php

namespace Tests\Feature;

use App\Models\CertifiedKnowledgeItem;
use App\Models\KnowledgeCemeteryEntry;
use App\Models\QuantLaw;
use App\Models\QuantTheory;
use App\Models\RealityExperiment;
use App\Models\RealityScore;
use App\Models\RealityValidationEvent;
use App\Models\RealityVerificationRun;
use App\Models\SkepticReport;
use App\Models\StrategyScore;
use App\Services\RealityVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RealityVerificationEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_reality_verification_certifies_supported_knowledge(): void
    {
        $this->createQuantLaw();
        $this->createQuantTheory();
        $this->createPaperScores(88, 82, 90, 2.8, 12);

        $run = app(RealityVerificationService::class)->verify();

        $this->assertNotNull($run);
        $this->assertGreaterThan(0, RealityScore::count());
        $this->assertGreaterThan(0, RealityExperiment::count());
        $this->assertGreaterThan(0, RealityValidationEvent::count());
        $this->assertGreaterThan(0, CertifiedKnowledgeItem::count());
    }

    public function test_failed_high_confidence_knowledge_goes_to_cemetery_and_skeptic_report(): void
    {
        $this->createQuantLaw();
        $this->createPaperScores(18, 22, 25, 0.6, -18, true);

        app(RealityVerificationService::class)->verify();

        $this->assertGreaterThan(0, KnowledgeCemeteryEntry::count());
        $this->assertGreaterThan(0, SkepticReport::count());
        $this->assertEquals('reality_failed', RealityScore::first()->validation_status);
    }

    public function test_reality_center_dashboard_manual_verification_and_command_work(): void
    {
        $this->createQuantLaw();
        $this->createPaperScores(78, 76, 80, 2.1, 8);

        $this->post(route('reality-center.verify'))
            ->assertRedirect(route('reality-center.index'));

        $this->get(route('reality-center.index'))
            ->assertOk()
            ->assertSee('Reality Center')
            ->assertSee('Reality Score')
            ->assertSee('Certified Knowledge')
            ->assertSee('Skeptic Reports');

        Artisan::call('reality:verify');

        $this->assertGreaterThan(0, RealityVerificationRun::count());
        $this->assertStringContainsString('Reality verification', Artisan::output());
    }

    private function createQuantLaw(): QuantLaw
    {
        return QuantLaw::create([
            'law_key' => 'law:trend_dependency:adaptability_decay',
            'title' => 'High trend dependency reduces long-term adaptability',
            'statement' => 'trend dependency reduces adaptability across strategies.',
            'law_type' => 'adaptability_law',
            'status' => 'active',
            'confidence_score' => 92,
            'universality_score' => 70,
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

    private function createQuantTheory(): QuantTheory
    {
        return QuantTheory::create([
            'theory_key' => 'theory:adaptive_dominance',
            'title' => 'Adaptive Dominance Theory',
            'thesis' => 'Long-term strategy survival is primarily driven by adaptability.',
            'theory_type' => 'survival_theory',
            'status' => 'accepted',
            'confidence_score' => 84,
            'explanatory_power_score' => 80,
            'predictive_power_score' => 78,
            'evidence_count' => 42,
            'law_count' => 2,
            'causal_edge_count' => 1,
            'root_cause_count' => 1,
            'scope' => [
                'drivers' => ['adaptability', 'trend_dependency'],
                'targets' => ['future_survival'],
            ],
            'metadata' => [],
        ]);
    }

    private function createPaperScores(int $score, int $forward, int $robustness, float $profitFactor, float $netProfit, bool $overfit = false): void
    {
        for ($index = 0; $index < 3; $index++) {
            StrategyScore::create([
                'symbol' => 'XAUUSD',
                'timeframe' => 'H1',
                'strategy' => 'ema_rsi_v'.$index,
                'parameters' => [],
                'score' => $score,
                'train_score' => $score,
                'validation_score' => $score,
                'forward_score' => $forward,
                'robustness_score' => $robustness,
                'is_overfit' => $overfit,
                'mc_risk_of_ruin_percent' => $overfit ? 42 : 4,
                'total_trades' => 40,
                'wins' => $score > 50 ? 28 : 8,
                'losses' => $score > 50 ? 12 : 32,
                'winrate' => $score > 50 ? 70 : 20,
                'net_profit_percent' => $netProfit,
                'max_drawdown_percent' => $overfit ? 32 : 5,
                'profit_factor' => $profitFactor,
                'average_win_percent' => 1.2,
                'average_loss_percent' => -0.8,
                'risk_reward_ratio' => 1.5,
                'max_consecutive_losses' => $overfit ? 8 : 2,
                'stability_score' => $overfit ? 18 : 84,
                'equity_curve' => [],
                'regime_performance' => [],
                'volatility_performance' => [],
                'raw_result' => ['execution_mode' => 'paper_trading'],
            ]);
        }
    }
}
