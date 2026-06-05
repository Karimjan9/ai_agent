<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyReportPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_reports_index_lists_reports(): void
    {
        DailyReport::create([
            'report_date' => '2026-06-02',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy' => 'ema_rsi_v1',
            'total_backtests' => 1,
            'total_trades' => 248,
            'total_wins' => 140,
            'total_losses' => 108,
            'average_winrate' => 56.45,
            'average_profit' => 18.5,
            'top_mistakes' => [
                ['type' => 'sideways_market', 'count' => 18],
            ],
            'ai_conclusion' => 'EMA/RSI trend marketda yaxshi ishladi.',
            'next_training_plan' => 'ATR volatility filter qo`shish kerak.',
        ]);

        $response = $this->get('/daily-reports');

        $response->assertOk()
            ->assertSee('AI Daily Reports')
            ->assertSee('2026-06-02')
            ->assertSee('248')
            ->assertSee('56.45%')
            ->assertSee("Ko'rish", false);
    }

    public function test_daily_report_show_displays_report_details(): void
    {
        $report = DailyReport::create([
            'report_date' => '2026-06-02',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy' => 'ema_rsi_v1',
            'total_backtests' => 1,
            'total_trades' => 248,
            'total_wins' => 140,
            'total_losses' => 108,
            'average_winrate' => 56.45,
            'average_profit' => 18.5,
            'top_mistakes' => [
                ['type' => 'sideways_market', 'count' => 18],
                ['type' => 'rsi_false_signal', 'count' => 21],
            ],
            'ai_conclusion' => 'EMA/RSI trend marketda yaxshi ishladi.',
            'next_training_plan' => 'ATR volatility filter qo`shish kerak.',
        ]);

        $response = $this->get(route('daily-reports.show', $report));

        $response->assertOk()
            ->assertSee('AI Daily Report')
            ->assertSee('XAUUSD')
            ->assertSee('H1')
            ->assertSee('sideways_market')
            ->assertSee('18 ta')
            ->assertSee('EMA/RSI trend marketda yaxshi ishladi.')
            ->assertSee('ATR volatility filter qo`shish kerak.');
    }
}
