<?php

namespace Tests\Feature;

use App\Models\StrategyScore;
use App\Models\TrainingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainingSessionPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_training_sessions_index_lists_sessions(): void
    {
        TrainingSession::create([
            'title' => 'Training Session 2026-06-05 10:00',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'agents_count' => 4,
            'best_strategy' => 'macd_trend_v1',
            'best_score' => 84,
            'worst_strategy' => 'breakout_v1',
            'worst_score' => 38,
            'total_trades' => 766,
            'average_winrate' => 54.85,
            'average_profit' => 12.15,
            'average_drawdown' => 7.5,
            'average_profit_factor' => 1.58,
            'average_stability_score' => 86,
            'ai_conclusion' => 'MACD trend agent eng yaxshi ishladi.',
            'next_training_plan' => 'Breakout ATR filter kuchaytiriladi.',
            'raw_leaderboard' => [],
        ]);

        $response = $this->get('/training-sessions');

        $response->assertOk()
            ->assertSee('Training Sessions')
            ->assertSee('Start New Training Session')
            ->assertSee('MACD_TREND_V1')
            ->assertSee('BREAKOUT_V1')
            ->assertSee('54.85%');
    }

    public function test_training_session_show_displays_session_scores(): void
    {
        $session = TrainingSession::create([
            'title' => 'Training Session 2026-06-05 10:00',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'agents_count' => 4,
            'best_strategy' => 'macd_trend_v1',
            'best_score' => 84,
            'worst_strategy' => 'breakout_v1',
            'worst_score' => 38,
            'total_trades' => 766,
            'average_winrate' => 54.85,
            'average_profit' => 12.15,
            'ai_conclusion' => 'MACD trend agent eng yaxshi ishladi.',
            'next_training_plan' => 'Breakout ATR filter kuchaytiriladi.',
            'raw_leaderboard' => [],
        ]);

        StrategyScore::create([
            'training_session_id' => $session->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy' => 'macd_trend_v1',
            'parameters' => ['ema_trend' => 100],
            'score' => 84,
            'total_trades' => 185,
            'wins' => 113,
            'losses' => 72,
            'winrate' => 61.2,
            'net_profit_percent' => 22.4,
            'max_drawdown_percent' => 6.2,
            'profit_factor' => 1.74,
            'average_win_percent' => 1.1,
            'average_loss_percent' => 0.5,
            'risk_reward_ratio' => 2.2,
            'max_consecutive_losses' => 3,
            'stability_score' => 90,
            'equity_curve' => [10000, 10100, 10050, 10200],
            'regime_performance' => [
                'trend_up' => [
                    'trades' => 12,
                    'wins' => 8,
                    'losses' => 4,
                    'winrate' => 66.67,
                    'profit_percent' => 4.2,
                ],
            ],
            'raw_result' => [],
        ]);

        $response = $this->get(route('training-sessions.show', $session));

        $response->assertOk()
            ->assertSee('Training Session #'.$session->id)
            ->assertSee('MACD_TREND_V1')
            ->assertSee('Equity Curve')
            ->assertSee('equityCurveChart')
            ->assertSee('scoreChart')
            ->assertSee('profitDrawdownChart')
            ->assertSee('winLossChart')
            ->assertSee('stabilityChart')
            ->assertSee('Best Agent Regime Profit')
            ->assertSee('regimeProfitChart')
            ->assertSee('Market Regime Performance')
            ->assertSee('trend_up')
            ->assertSee('Parameters')
            ->assertSee('ema_trend')
            ->assertSee('Session Leaderboard')
            ->assertSee('MACD trend agent eng yaxshi ishladi.')
            ->assertSee('Breakout ATR filter kuchaytiriladi.');
    }
}
