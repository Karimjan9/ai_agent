<?php

namespace App\Http\Controllers;

use App\Models\DailyReport;
use App\Models\Mistake;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function dashboard(): View
    {
        $backtestSummary = $this->backtestSummary();
        $latestDailyReport = $this->latestDailyReport();

        return view('pages.dashboard', [
            'metrics' => [
                ['label' => 'Strategiya', 'value' => $backtestSummary['strategy'], 'tone' => 'blue'],
                ['label' => 'Instrument', 'value' => $backtestSummary['instrument'], 'tone' => 'green'],
                ['label' => 'Timeframe', 'value' => $backtestSummary['timeframe'], 'tone' => 'yellow'],
                ['label' => 'Trades', 'value' => $backtestSummary['trades'], 'tone' => 'blue'],
                ['label' => 'Winrate', 'value' => $backtestSummary['winrate'], 'tone' => 'green'],
                ['label' => 'Profit Factor', 'value' => $backtestSummary['profit_factor'], 'tone' => 'green'],
                ['label' => 'Max Drawdown', 'value' => $backtestSummary['max_drawdown'], 'tone' => 'red'],
                ['label' => 'Net Profit', 'value' => $backtestSummary['net_profit'], 'tone' => 'green'],
            ],
            'backtestSummary' => $backtestSummary,
            'latestConclusion' => $backtestSummary['conclusion'],
            'latestDailyReport' => $latestDailyReport,
        ]);
    }

    public function marketData(): View
    {
        return view('pages.market-data');
    }

    public function strategyLab(): View
    {
        return view('pages.strategy-lab');
    }

    public function backtestResults(): View
    {
        return view('pages.backtest-results', [
            'backtestSummary' => $this->backtestSummary(),
        ]);
    }

    public function mistakeJournal(): View
    {
        return view('pages.mistake-journal', [
            'mistakes' => $this->latestMistakes(),
        ]);
    }

    public function dailyReport(): View
    {
        return view('pages.ai-daily-report', [
            'dailyReport' => $this->latestDailyReport(),
        ]);
    }

    private function backtestSummary(): array
    {
        return [
            'strategy' => 'EMA_RSI_V1',
            'instrument' => 'XAU/USD',
            'timeframe' => 'H1',
            'period' => '2023-01-01 - 2025-12-31',
            'trades' => '248',
            'winrate' => '56.4%',
            'profit_factor' => '1.42',
            'max_drawdown' => '8.7%',
            'net_profit' => '+18.5%',
            'conclusion' => "Trend paytida yaxshi, flat bozorda ko'p xato qiladi.",
        ];
    }

    private function latestDailyReport(): ?DailyReport
    {
        if (! Schema::hasTable('daily_reports')) {
            return null;
        }

        return DailyReport::query()
            ->latest('report_date')
            ->latest('id')
            ->first();
    }

    private function latestMistakes()
    {
        if (! Schema::hasTable('mistakes')) {
            return collect();
        }

        return Mistake::query()
            ->latest()
            ->limit(20)
            ->get();
    }
}
