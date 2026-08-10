<?php

namespace App\Console\Commands;

use App\Models\DailyReport;
use App\Services\CanonicalLabResultService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class GenerateDailyTradingReport extends Command
{
    protected $signature = 'trading:daily-report {date?}';

    protected $description = 'Generate daily AI training report';

    public function __construct(private CanonicalLabResultService $labResults)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = $this->argument('date')
            ? Carbon::parse($this->argument('date'))
            : now();

        $runs = $this->labResults->forDate($date);

        if ($runs->isEmpty()) {
            $this->info('Bu kunda backtest topilmadi.');

            return self::SUCCESS;
        }

        $aggregate = $this->labResults->aggregate($runs);
        $topMistakes = $this->labResults->topMistakes($date);
        $totalTrades = $aggregate['total_trades'];
        $totalWins = $aggregate['total_wins'];
        $totalLosses = $aggregate['total_losses'];
        $averageWinrate = $aggregate['average_winrate'];
        $averageProfit = $aggregate['average_profit'];

        $aiConclusion = $this->makeAiConclusion($averageWinrate, $averageProfit, $topMistakes);
        $nextPlan = $this->makeNextTrainingPlan($topMistakes);
        $reportData = [
            'symbol' => $aggregate['symbol'],
            'timeframe' => $aggregate['timeframe'],
            'strategy' => $aggregate['strategy'],
            'source' => CanonicalLabResultService::SOURCE,
            'evidence_run_ids' => $runs->pluck('run_id')->values()->all(),
            'total_backtests' => $aggregate['total_backtests'],
            'total_trades' => $totalTrades,
            'total_wins' => $totalWins,
            'total_losses' => $totalLosses,
            'average_winrate' => $averageWinrate,
            'average_profit' => $averageProfit,
            'top_mistakes' => $topMistakes,
            'ai_conclusion' => $aiConclusion,
            'next_training_plan' => $nextPlan,
            'metrics' => [
                'total_backtests' => $runs->count(),
                'total_trades' => $totalTrades,
                'total_wins' => $totalWins,
                'total_losses' => $totalLosses,
                'average_winrate' => $averageWinrate,
                'average_profit' => $averageProfit,
                'average_profit_factor' => $aggregate['average_profit_factor'],
                'average_drawdown' => $aggregate['average_drawdown'],
                'source' => CanonicalLabResultService::SOURCE,
                'run_ids' => $runs->pluck('run_id')->values()->all(),
                'top_mistakes' => $topMistakes,
            ],
            'conclusion' => $aiConclusion,
            'recommendations' => ['next_training_plan' => $nextPlan],
        ];

        DailyReport::updateOrCreate(
            ['report_date' => $date->toDateString()],
            $this->onlyExistingColumns('daily_reports', $reportData),
        );

        $this->info('Daily AI report yaratildi.');

        return self::SUCCESS;
    }

    private function makeAiConclusion(float $winrate, float $profit, array $topMistakes): string
    {
        $mainMistake = $topMistakes[0]['type'] ?? null;
        $text = "Bugungi trening natijasi: o'rtacha winrate {$winrate}%, o'rtacha profit {$profit}%. ";

        if ($winrate >= 55 && $profit > 0) {
            $text .= 'Strategiyalar umumiy hisobda yaxshi ishladi. ';
        } elseif ($winrate < 45) {
            $text .= 'Strategiyalar zaif natija berdi. ';
        } else {
            $text .= "Natija o'rtacha, qo'shimcha filterlar kerak. ";
        }

        if ($mainMistake === 'trend_against_entry') {
            $text .= "Eng katta muammo trendga qarshi kirish bo'ldi.";
        } elseif ($mainMistake === 'late_entry') {
            $text .= "Eng katta muammo signalga kech kirish bo'ldi.";
        } elseif ($mainMistake === 'rsi_false_signal') {
            $text .= "Eng katta muammo RSI false signal bo'ldi.";
        } elseif ($mainMistake === 'sideways_market') {
            $text .= "Eng katta muammo sideways market bo'ldi.";
        } elseif ($mainMistake === 'stop_loss_too_close') {
            $text .= "Eng katta muammo stop-loss juda yaqin bo'lgani bo'ldi.";
        } elseif ($mainMistake === 'unknown_loss') {
            $text .= "Ko'p losslar aniq klassifikatsiya qilinmadi, market regime tahlili kerak.";
        } else {
            $text .= 'Xatolar chuqurroq tahlil qilinishi kerak.';
        }

        return $text;
    }

    private function makeNextTrainingPlan(array $topMistakes): string
    {
        $mainMistake = $topMistakes[0]['type'] ?? null;

        return match ($mainMistake) {
            'trend_against_entry' => 'Keyingi treningda EMA trend filter kuchaytiriladi va qarshi-trend signallar kamaytiriladi.',
            'late_entry' => 'Keyingi treningda RSI chegaralari qayta tekshiriladi va entry timing optimizatsiya qilinadi.',
            'rsi_false_signal' => 'Keyingi treningda RSI signalini trend strength va EMA slope bilan tasdiqlash qo`shiladi.',
            'sideways_market' => 'Keyingi treningda ATR volatility va sideways market filter qo`shiladi.',
            'stop_loss_too_close' => 'Keyingi treningda stop-loss ATR asosida dinamik sozlanadi.',
            'unknown_loss' => 'Keyingi treningda ATR, volatility va market regime filter qo`shiladi.',
            default => 'Keyingi treningda strategiya boshqa timeframe va periodlarda qayta test qilinadi.',
        };
    }

    private function onlyExistingColumns(string $table, array $data): array
    {
        return array_filter(
            $data,
            fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
