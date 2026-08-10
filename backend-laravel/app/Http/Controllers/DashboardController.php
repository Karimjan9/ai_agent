<?php

namespace App\Http\Controllers;

use App\Models\DailyReport;
use App\Services\CanonicalLabResultService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function __construct(private CanonicalLabResultService $labResults) {}

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
        return view('pages.strategy-lab', [
            'labSummary' => $this->backtestSummary(),
            'strategies' => $this->strategyRegistry(),
        ]);
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
        $run = $this->labResults->latest();
        if (! $run) {
            return [
                'strategy' => '—',
                'instrument' => '—',
                'timeframe' => '—',
                'period' => '—',
                'trades' => 0,
                'winrate' => '—',
                'profit_factor' => '—',
                'max_drawdown' => '—',
                'net_profit' => '—',
                'conclusion' => 'Hali canonical lab evidence mavjud emas.',
                'source' => CanonicalLabResultService::SOURCE,
                'run_id' => null,
            ];
        }

        $summary = $this->labResults->summary($run);

        return [
            ...$summary,
            'winrate' => $summary['winrate'] === null ? '—' : $summary['winrate'].'%',
            'profit_factor' => number_format((float) $summary['profit_factor'], 2),
            'max_drawdown' => $summary['max_drawdown'].'%',
            'net_profit' => ($summary['net_profit'] > 0 ? '+' : '').$summary['net_profit'].'%',
            'conclusion' => $summary['conclusion'] !== ''
                ? $summary['conclusion']
                : 'Canonical lab replay yakunlandi; qo‘shimcha xulosa mavjud emas.',
        ];
    }

    private function latestDailyReport(): ?DailyReport
    {
        if (! Schema::hasTable('daily_reports')) {
            return null;
        }

        $query = DailyReport::query();
        if (Schema::hasColumn('daily_reports', 'source')) {
            $query->where('source', CanonicalLabResultService::SOURCE);
        }

        return $query->latest('report_date')->latest('id')->first();
    }

    private function latestMistakes()
    {
        return $this->labResults->latestRejections();
    }

    /** @return array<int, array{strategy:string,label:string}> */
    private function strategyRegistry(): array
    {
        if (app()->environment('testing')) {
            return [];
        }

        return Cache::remember('ai-strategy-registry-v1', now()->addMinutes(5), function (): array {
            try {
                $response = Http::timeout(5)
                    ->acceptJson()
                    ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                    ->get(rtrim(config('services.ai_service.url'), '/').'/api/strategies');
                if ($response->failed()) {
                    return [];
                }

                return collect((array) $response->json('agents', []))
                    ->map(fn (array $agent): array => [
                        'strategy' => (string) ($agent['strategy'] ?? ''),
                        'label' => (string) ($agent['label'] ?? $agent['strategy'] ?? ''),
                    ])
                    ->filter(fn (array $agent): bool => $agent['strategy'] !== '')
                    ->values()
                    ->all();
            } catch (ConnectionException) {
                return [];
            }
        });
    }
}
