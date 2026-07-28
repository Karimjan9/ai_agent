<?php

namespace App\Services;

/**
 * Keeps optimisation focused on promotion gates instead of one aggregate
 * fitness score.  These values are deliberately the same values used by the
 * forward-promotion gate in MarketChampionService.
 */
class ForwardGateProgressService
{
    public const TRADE_TARGET = 30;
    public const PROFIT_FACTOR_TARGET = 1.30;
    public const ROLLING_WINS_TARGET = 3;
    public const DRAWDOWN_LIMIT = 15.0;
    public const RUIN_LIMIT = 10.0;

    public function deficits(array $result, int $rollingWins = 0): array
    {
        $trades = (int) data_get($result, 'total_trades', 0);
        $profitFactor = (float) data_get($result, 'profit_factor', 0);
        $drawdown = (float) data_get($result, 'max_drawdown_percent', data_get($result, 'max_drawdown', 100));
        $ruin = (float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 100);

        return [
            'trade_deficit' => max(0, self::TRADE_TARGET - $trades),
            'pf_deficit' => round(max(0, self::PROFIT_FACTOR_TARGET - $profitFactor), 4),
            'rolling_deficit' => max(0, self::ROLLING_WINS_TARGET - $rollingWins),
            'drawdown_excess' => round(max(0, $drawdown - self::DRAWDOWN_LIMIT), 4),
            'ruin_excess' => round(max(0, $ruin - self::RUIN_LIMIT), 4),
        ];
    }

    public function snapshot(array $result, int $rollingWins = 0, ?array $frontierBaseline = null): array
    {
        $trades = (int) data_get($result, 'total_trades', 0);
        $profitFactor = (float) data_get($result, 'profit_factor', 0);
        $drawdown = (float) data_get($result, 'max_drawdown_percent', data_get($result, 'max_drawdown', 100));
        $ruin = (float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 100);
        $pbo = data_get($result, 'selection_validation.probability_of_backtest_overfitting');
        $dsr = data_get($result, 'statistical_evidence.deflated_sharpe.deflated_sharpe_probability');
        $bootstrap = data_get($result, 'statistical_evidence.edge_quality.bootstrap_pf.pf_5_percentile_lower_bound');
        $worstRegimePf = data_get($result, 'statistical_evidence.edge_quality.worst_regime_pf');
        $stressCostPf = data_get($result, 'pf_attribution.stress_cost.profit_factor');
        $opportunities = (int) data_get($result, 'entry_funnel.flat_signal_opportunities', 0);
        $accepted = (int) data_get($result, 'entry_funnel.accepted_entries', 0);
        $forward = (float) data_get($result, 'forward_score', 0);
        $frontierForward = (float) data_get($frontierBaseline, 'forward_score', 0);
        $positiveWindows = (int) data_get($result, 'window_survival.positive_windows', 0);
        $catastrophicWindows = (int) data_get($result, 'window_survival.catastrophic_windows', 0);
        $activityAbsence = (int) data_get($result, 'window_survival.activity_absence', 0);

        return [
            'trades' => $trades,
            'profit_factor' => $profitFactor,
            'rolling_wins' => $rollingWins,
            'drawdown' => $drawdown,
            'ruin' => $ruin,
            'pbo' => $pbo === null ? null : (float) $pbo,
            'dsr' => $dsr === null ? null : (float) $dsr,
            'bootstrap_lower_bound' => $bootstrap === null ? null : (float) $bootstrap,
            'worst_regime_pf' => $worstRegimePf === null ? null : (float) $worstRegimePf,
            'stress_cost_pf' => $stressCostPf === null ? null : (float) $stressCostPf,
            'forward_gain' => round($forward - $frontierForward, 4),
            'signal_coverage' => $opportunities > 0 ? round($accepted / $opportunities, 4) : null,
            'coverage_preserving_profit_factor' => $profitFactor >= self::PROFIT_FACTOR_TARGET && $trades >= self::TRADE_TARGET,
            'window_survival' => [
                'positive_windows' => $positiveWindows,
                'catastrophic_windows' => $catastrophicWindows,
                'activity_absence' => $activityAbsence,
            ],
            'deficits' => $this->deficits($result, $rollingWins),
            // Transition milestones make partial progress visible: a strategy
            // should first become tradable, then economically viable, then
            // promotion-ready.
            'milestones' => [
                'trades' => $this->milestone($trades, [15, 24, 34]),
                'profit_factor' => $this->milestone($profitFactor, [1.05, 1.18, 1.36]),
                'rolling_wins' => $this->milestone($rollingWins, [1, 2, 3]),
            ],
            'passes' => [
                'trades' => $trades >= self::TRADE_TARGET,
                'profit_factor' => $profitFactor >= self::PROFIT_FACTOR_TARGET,
                'rolling_wins' => $rollingWins >= self::ROLLING_WINS_TARGET,
                'drawdown' => $drawdown <= self::DRAWDOWN_LIMIT,
                'ruin' => $ruin <= self::RUIN_LIMIT,
                'pbo' => $pbo !== null && (float) $pbo <= .50,
                'dsr' => $dsr !== null && (float) $dsr >= .95,
                'bootstrap_lower_bound' => $bootstrap !== null && (float) $bootstrap >= 1.10,
                'worst_regime_pf' => $worstRegimePf !== null && (float) $worstRegimePf >= 1.0,
                'stress_cost_pf' => $stressCostPf !== null && (float) $stressCostPf >= 1.05,
                'coverage_preserving_profit_factor' => $profitFactor >= self::PROFIT_FACTOR_TARGET && $trades >= self::TRADE_TARGET,
                // A zero-opportunity month is diagnostic absence, not a loss.
                'window_survival' => $positiveWindows >= 3 && $catastrophicWindows === 0,
                'forward_gain' => $frontierBaseline === null || $forward - $frontierForward >= 5,
            ],
        ];
    }

    public function transition(?array $before, array $after, array $baseline = []): array
    {
        if (! $before) {
            return ['baseline' => $baseline['type'] ?? 'new', 'baseline_agent_ids' => $baseline['agent_ids'] ?? [], 'improved' => [], 'worsened' => [], 'after' => $after];
        }

        $improved = [];
        $worsened = [];
        foreach (['trades', 'profit_factor', 'rolling_wins'] as $key) {
            if (($after['milestones'][$key] ?? 0) > ($before['milestones'][$key] ?? 0)) $improved[] = $key;
            if (($after['milestones'][$key] ?? 0) < ($before['milestones'][$key] ?? 0)) $worsened[] = $key;
        }
        foreach (['drawdown', 'ruin'] as $key) {
            if (($after['passes'][$key] ?? false) && ! ($before['passes'][$key] ?? false)) $improved[] = $key;
            if (! ($after['passes'][$key] ?? false) && ($before['passes'][$key] ?? false)) $worsened[] = $key;
        }

        return [
            'baseline' => $baseline['type'] ?? 'parent', 'baseline_agent_ids' => $baseline['agent_ids'] ?? [], 'before' => $before, 'after' => $after,
            'improved' => $improved, 'worsened' => $worsened,
        ];
    }

    private function milestone(float|int $value, array $steps): int
    {
        $level = 0;
        foreach ($steps as $index => $threshold) if ($value >= $threshold) $level = $index + 1;
        return $level;
    }
}
