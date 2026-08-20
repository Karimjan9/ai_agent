<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;
use App\Models\PaperOrder;
use App\Services\MarketData\MarketReadinessService;

class TradingRiskService
{
    public function __construct(private MarketReadinessService $readiness, private EconomicCalendarService $calendar) {}

    /** @return array{allowed: bool, reason: string, estimated_round_trip_cost_percent: float} */
    public function canOpen(ModelMarketPerformance $candidate, array $signal): array
    {
        $cost = $this->estimatedRoundTripCostPercent($candidate->symbol, (float) ($signal['price'] ?? 0));

        $news = $this->calendar->veto($candidate->symbol);
        if ($news['active']) {
            return ['allowed' => false, 'reason' => 'High-impact economic event execution veto.', 'estimated_round_trip_cost_percent' => $cost];
        }

        if (! $this->readiness->ready($candidate->symbol, $candidate->timeframe)) {
            return ['allowed' => false, 'reason' => 'Market feed is not healthy.', 'estimated_round_trip_cost_percent' => $cost];
        }

        $open = PaperOrder::query()->where('status', 'open')->where('evidence_status', 'valid');
        if ((clone $open)->count() >= (int) config('services.risk.max_open_positions', 3)) {
            return ['allowed' => false, 'reason' => 'Maximum concurrent exposure reached.', 'estimated_round_trip_cost_percent' => $cost];
        }

        $group = $this->exposureGroup($candidate->symbol);
        $groupOpen = $open->get()->filter(fn (PaperOrder $order): bool => $this->exposureGroup($order->symbol) === $group)->count();
        if ($groupOpen >= (int) config('services.risk.max_positions_per_group', 2)) {
            return ['allowed' => false, 'reason' => "Correlated {$group} exposure limit reached.", 'estimated_round_trip_cost_percent' => $cost];
        }

        $dailyPnl = (float) PaperOrder::query()->where('status', 'closed')->where('evidence_status', 'valid')->where('closed_at', '>=', now()->startOfDay())->sum('profit_percent');
        if ($dailyPnl <= -abs((float) config('services.risk.daily_loss_limit_percent', 2))) {
            return ['allowed' => false, 'reason' => 'Daily loss limit reached.', 'estimated_round_trip_cost_percent' => $cost];
        }

        $entry = max(0.0000001, (float) ($signal['price'] ?? 0));
        $stop = (float) ($signal['stop_loss'] ?? $entry);
        $sentinelBudget = data_get($signal, 'risk_sentinel.risk_budget_percent');
        $risk = is_numeric($sentinelBudget)
            ? (float) $sentinelBudget + $cost
            : abs($entry - $stop) / $entry * 100 + $cost;
        if ($risk > (float) config('services.risk.max_risk_per_trade_percent', 1)) {
            return ['allowed' => false, 'reason' => 'Per-trade risk exceeds limit.', 'estimated_round_trip_cost_percent' => $cost];
        }

        return ['allowed' => true, 'reason' => 'Risk controls passed.', 'estimated_round_trip_cost_percent' => $cost, 'risk_percent' => round($risk, 6)];
    }

    public function estimatedRoundTripCostPercent(string $symbol, float $price): float
    {
        $execution = app(ExecutionContractService::class)->parameters($symbol);
        $point = (float) $execution['point_size'];
        $spreadPoints = (float) $execution['spread_points'];
        $slippagePoints = (float) $execution['slippage_points'];

        return round((($spreadPoints + (2 * $slippagePoints)) * $point / max($price, $point)) * 100, 5);
    }

    private function exposureGroup(string $symbol): string
    {
        return str_starts_with(strtoupper($symbol), 'XAU') ? 'metal_usd' : 'usd_fx';
    }
}
