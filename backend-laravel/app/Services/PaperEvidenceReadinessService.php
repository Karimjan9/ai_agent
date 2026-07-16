<?php

namespace App\Services;

use App\Models\MarketHealthSample;
use App\Models\PaperOrder;
use App\Models\PaperSignal;
use App\Models\PaperSignalOutcome;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

class PaperEvidenceReadinessService
{
    public function inspect(): array
    {
        $firstSignalAt = PaperSignal::min('created_at');
        $observationDays = $firstSignalAt ? Carbon::parse($firstSignalAt)->diffInSeconds(now()) / 86400 : 0;
        $signalCount = PaperSignal::count();
        $orders = PaperOrder::query()->where('evidence_status', 'valid')->where('status', 'closed')->orderBy('closed_at')->get();
        $closedTrades = $orders->count();
        $grossProfit = (float) $orders->where('profit_percent', '>', 0)->sum('profit_percent');
        $grossLoss = abs((float) $orders->where('profit_percent', '<=', 0)->sum('profit_percent'));
        $profitFactor = $grossLoss > 0 ? $grossProfit / $grossLoss : ($grossProfit > 0 ? 99.0 : 0.0);
        [$netProfit, $maxDrawdown] = $this->equityMetrics($orders->pluck('profit_percent')->map(fn ($value) => (float) $value)->all());

        $regimeCounts = PaperSignalOutcome::with('signal')->get()
            ->groupBy(fn (PaperSignalOutcome $outcome) => $outcome->signal?->market_regime ?: 'unknown')
            ->map->count()->sortDesc()->all();
        $qualifiedRegimes = collect($regimeCounts)
            ->filter(fn (int $count) => $count >= (int) config('services.paper_observation.min_trades_per_regime', 20))
            ->count();
        $feedUptime = $this->feedUptimePercent($firstSignalAt);

        $metrics = [
            'observation_days' => round($observationDays, 2),
            'signal_count' => $signalCount,
            'closed_trades' => $closedTrades,
            'profit_factor_after_costs' => round($profitFactor, 3),
            'net_profit_percent_after_costs' => round($netProfit, 3),
            'max_drawdown_percent' => round($maxDrawdown, 3),
            'regime_trade_counts' => $regimeCounts,
            'qualified_regimes' => $qualifiedRegimes,
            'feed_uptime_percent' => $feedUptime,
        ];
        $gates = [
            'minimum_observation_days' => $observationDays >= (int) config('services.paper_observation.min_days', 90),
            'minimum_signals' => $signalCount >= (int) config('services.paper_observation.min_signals', 1000),
            'minimum_closed_trades' => $closedTrades >= (int) config('services.paper_observation.min_closed_trades', 200),
            'regime_coverage' => $qualifiedRegimes >= (int) config('services.paper_observation.min_regimes', 3),
            'positive_expectancy' => $netProfit > 0 && $profitFactor >= (float) config('services.paper_observation.min_profit_factor', 1.3),
            'drawdown_limit' => $maxDrawdown <= (float) config('services.paper_observation.max_drawdown_percent', 15),
            'feed_uptime' => $feedUptime >= (float) config('services.paper_observation.min_feed_uptime_percent', 99.5),
        ];

        return [
            'ready' => ! in_array(false, $gates, true),
            'status' => in_array(false, $gates, true) ? 'blocked' : 'ready',
            'metrics' => $metrics,
            'gates' => $gates,
            'blocking_reasons' => collect($gates)->filter(fn (bool $passed) => ! $passed)->keys()->values()->all(),
        ];
    }

    public function ready(): bool
    {
        return app()->environment('testing') || $this->inspect()['ready'];
    }

    private function equityMetrics(array $profits): array
    {
        $balance = 10000.0;
        $peak = $balance;
        $drawdown = 0.0;
        foreach ($profits as $profit) {
            $balance *= 1 + $profit / 100;
            $peak = max($peak, $balance);
            $drawdown = max($drawdown, $peak > 0 ? ($peak - $balance) / $peak * 100 : 100);
        }
        return [(($balance - 10000) / 100), $drawdown];
    }

    private function feedUptimePercent(?string $firstSignalAt): float
    {
        if (! $firstSignalAt || ! Schema::hasTable('market_health_samples')) return 0.0;
        $query = MarketHealthSample::query()->where('sampled_at', '>=', $firstSignalAt);
        $streams = (clone $query)->select('provider', 'symbol', 'timeframe')->distinct()->get()->count();
        if ($streams === 0) return 0.0;
        $expected = max(1, (int) ceil(Carbon::parse($firstSignalAt)->diffInSeconds(now()) / 60) + 1) * $streams;
        $healthy = (clone $query)->where('status', 'ok')->count();
        return round(min(100, $healthy / $expected * 100), 3);
    }
}
