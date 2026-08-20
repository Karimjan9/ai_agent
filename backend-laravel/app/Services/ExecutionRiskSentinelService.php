<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;
use App\Models\PaperOrder;
use App\Models\PaperSignal;
use App\Models\RiskSentinelDecision;

/** Independent sizing/veto authority. It cannot promote, override, or widen a strategy signal. */
class ExecutionRiskSentinelService
{
    public const PROTOCOL = 'execution_risk_sentinel_v1';

    /** @return array<string,mixed> */
    public function assess(ModelMarketPerformance $candidate, array $signal, array $contract): array
    {
        $entry = max(0.0000001, (float) ($contract['entry_price'] ?? $signal['price'] ?? 0));
        $stop = (float) ($contract['stop_loss'] ?? $entry);
        $target = (float) ($contract['take_profit'] ?? $entry);
        $stopDistance = abs($entry - $stop);
        if ($stopDistance <= 0 || abs($entry - $target) <= 0) {
            return $this->veto('INVALID_EXIT_CONTRACT');
        }

        $equity = $this->equity();
        $drawdown = $this->drawdown();
        $maximumDrawdown = (float) config('services.risk.sentinel_max_drawdown_percent', 15);
        if ($drawdown >= $maximumDrawdown) {
            return $this->veto('MAX_DRAWDOWN_REACHED', $equity, $drawdown);
        }
        $riskOfRuin = (float) data_get($candidate->metrics, 'risk_of_ruin_percent', 0);
        if ($riskOfRuin > (float) config('services.risk.sentinel_max_risk_of_ruin_percent', 10)) {
            return $this->veto('RISK_OF_RUIN_LIMIT', $equity, $drawdown);
        }

        $confidence = max(0, min(1, (float) ($signal['confidence'] ?? 0) / 100));
        $confidenceMultiplier = max(.25, $confidence);
        $regimeMultiplier = (bool) data_get($signal, 'transition.active', false) ? .5 : (str_contains((string) ($signal['volatility_regime'] ?? ''), 'high') ? .5 : 1.0);
        $spreadRatio = data_get($signal, 'spread_atr_ratio');
        $executionMultiplier = is_numeric($spreadRatio) && (float) $spreadRatio <= .25 ? 1.0 : .5;
        $drawdownMultiplier = max(.25, 1 - ($drawdown / max($maximumDrawdown, .0001)));
        $group = $this->exposureGroup($candidate->symbol);
        $groupOpen = PaperOrder::query()->where('status', 'open')->where('evidence_status', 'valid')->get()->filter(fn (PaperOrder $order): bool => $this->exposureGroup($order->symbol) === $group)->count();
        $correlationMultiplier = $groupOpen > 0 ? .5 : 1.0;
        $baseRisk = min((float) config('services.risk.max_risk_per_trade_percent', 1), (float) config('services.risk.sentinel_capped_fractional_risk_percent', .75));
        $budget = $baseRisk * $confidenceMultiplier * $regimeMultiplier * $executionMultiplier * $drawdownMultiplier * $correlationMultiplier;
        $stopPercent = $stopDistance / $entry * 100;
        $size = min((float) data_get($contract, 'execution_contract.parameters.max_leverage', 5), $budget / max($stopPercent, .000001));
        $rr = abs($target - $entry) / $stopDistance;
        if ($rr < (float) config('services.risk.sentinel_min_reward_risk', 1)) {
            return $this->veto('REWARD_RISK_TOO_LOW', $equity, $drawdown, $budget);
        }

        return ['approved' => true, 'reason_code' => 'CAPPED_FRACTIONAL_RISK_APPROVED', 'equity' => round($equity, 4), 'drawdown_percent' => round($drawdown, 4), 'risk_budget_percent' => round($budget, 6), 'position_size_multiple' => round(max(0, $size), 6), 'tactic_contract' => (array) data_get($contract, 'tactical_contract', []), 'guards' => ['martingale' => 'forbidden', 'full_kelly' => 'forbidden', 'live_geometric_compounding' => 'forbidden', 'risk_increase_after_loss' => 'forbidden'], 'protocol' => self::PROTOCOL, 'promotion_evidence' => false];
    }

    public function record(PaperSignal $signal, ModelMarketPerformance $candidate, array $plan): RiskSentinelDecision
    {
        return RiskSentinelDecision::updateOrCreate(['decision_key' => "paper-sentinel:{$signal->id}"], [
            'paper_signal_id' => $signal->id, 'model_market_performance_id' => $candidate->id, 'symbol' => $candidate->symbol, 'timeframe' => $candidate->timeframe,
            'decision' => $plan['approved'] ? 'APPROVE' : 'VETO', 'reason_code' => $plan['reason_code'], 'equity' => $plan['equity'] ?? null,
            'risk_budget_percent' => $plan['risk_budget_percent'] ?? null, 'position_size_multiple' => $plan['position_size_multiple'] ?? null, 'plan' => $plan, 'decided_at' => now(),
        ]);
    }

    private function equity(): float
    {
        $equity = (float) config('services.risk.paper_starting_equity', 10000);
        foreach (PaperOrder::query()->where('status', 'closed')->where('evidence_status', 'valid')->orderBy('closed_at')->pluck('profit_percent') as $return) {
            $equity *= 1 + ((float) $return / 100);
        }

        return $equity;
    }

    private function drawdown(): float
    {
        $equity = (float) config('services.risk.paper_starting_equity', 10000);
        $peak = $equity;
        $maximum = 0;
        foreach (PaperOrder::query()->where('status', 'closed')->where('evidence_status', 'valid')->orderBy('closed_at')->pluck('profit_percent') as $return) {
            $equity *= 1 + ((float) $return / 100);
            $peak = max($peak, $equity);
            $maximum = max($maximum, ($peak - $equity) / max($peak, .0001) * 100);
        }

        return $maximum;
    }

    private function veto(string $reason, ?float $equity = null, ?float $drawdown = null, ?float $budget = null): array
    {
        return ['approved' => false, 'reason_code' => $reason, 'equity' => $equity, 'drawdown_percent' => $drawdown, 'risk_budget_percent' => $budget, 'position_size_multiple' => 0, 'protocol' => self::PROTOCOL, 'promotion_evidence' => false];
    }

    private function exposureGroup(string $symbol): string
    {
        return str_starts_with(strtoupper($symbol), 'XAU') ? 'metal_usd' : 'usd_fx';
    }
}
