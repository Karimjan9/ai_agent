<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;

/**
 * Converts evaluation evidence into bounded, falsifiable generation work.
 * It is deliberately separate from promotion gates: learning can be soft,
 * while promotion remains strict.
 */
class AgentEvolutionQualityService
{
    public function curriculum(array $result): array
    {
        $deficits = [
            'trade_deficit' => max(0, 30 - (int) data_get($result, 'total_trades', 0)),
            'pf_deficit' => max(0, 1.30 - (float) data_get($result, 'profit_factor', 0)),
            'rolling_deficit' => max(0, 3 - (int) data_get($result, 'rolling_forward_wins', data_get($result, 'monthly_passport.rolling_forward_wins', 0))),
            'drawdown_excess' => max(0, (float) data_get($result, 'max_drawdown_percent', data_get($result, 'max_drawdown', 0)) - 15),
            'ruin_excess' => max(0, (float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 0) - 10),
            'stress_pf_deficit' => max(0, 1.05 - (float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 0)),
        ];
        $priority = [
            'drawdown_risk' => $deficits['drawdown_excess'] / 15 + $deficits['ruin_excess'] / 10,
            'stress_cost' => $deficits['stress_pf_deficit'] / 1.05,
            'trade_frequency' => $deficits['trade_deficit'] / 30,
            'profit_factor' => $deficits['pf_deficit'] / 1.30,
            'rolling_regime' => $deficits['rolling_deficit'] / 3,
        ];
        arsort($priority);
        $target = (string) array_key_first($priority);
        return [
            'protocol' => 'gate_deficit_curriculum_v1', 'deficits' => $deficits,
            'primary_target' => $target, 'priority' => $priority,
            'bounded_bundle' => $this->bundle($target),
            'rule' => 'One coherent failure bundle per mutation; no gate threshold is altered.',
        ];
    }

    public function noRegressionContract(?array $parent, array $child): array
    {
        if (! is_array($parent) || $parent === []) {
            return ['status' => 'baseline_unavailable', 'preserved' => [], 'regressed' => [], 'rule' => 'No parent claim available to preserve.'];
        }
        $gates = [
            'trade_count' => ['path' => 'total_trades', 'threshold' => 30, 'higher' => true],
            'profit_factor' => ['path' => 'profit_factor', 'threshold' => 1.30, 'higher' => true],
            'rolling_wins' => ['path' => 'rolling_forward_wins', 'threshold' => 3, 'higher' => true],
            'drawdown' => ['path' => 'max_drawdown_percent', 'threshold' => 15, 'higher' => false],
            'ruin_risk' => ['path' => 'monte_carlo.risk_of_ruin_percent', 'threshold' => 10, 'higher' => false],
            'stress_cost_pf' => ['path' => 'pf_attribution.stress_cost.profit_factor', 'threshold' => 1.05, 'higher' => true],
        ];
        $preserved = []; $regressed = [];
        foreach ($gates as $name => $gate) {
            $before = (float) data_get($parent, $gate['path'], $name === 'drawdown' ? 100 : 0);
            $after = (float) data_get($child, $gate['path'], $name === 'drawdown' ? 100 : 0);
            $parentPassed = $gate['higher'] ? $before >= $gate['threshold'] : $before <= $gate['threshold'];
            if (! $parentPassed) continue;
            $childPassed = $gate['higher'] ? $after >= $gate['threshold'] : $after <= $gate['threshold'];
            if ($childPassed) $preserved[] = $name;
            else $regressed[] = $name;
        }
        return [
            'status' => $regressed === [] ? 'passed' : 'failed', 'preserved' => $preserved, 'regressed' => $regressed,
            'rule' => 'A mutation may repair one deficit only if it preserves every gate its parent had already passed.',
        ];
    }

    /**
     * Evaluate a capability child against every selected contributor. The
     * legacy single-parent contract remains the causal lane; robust and
     * architecture lanes need this composite view so parent A cannot hide a
     * regression against parent C merely because the compatibility columns
     * still expose only A/B.
     *
     * @param array<int, array{model_version_id?: int|string, metrics?: array}> $parents
     */
    public function noRegressionAcrossParents(array $parents, array $child): array
    {
        $baselines = collect($parents)
            ->filter(fn ($parent): bool => is_array($parent) && is_array(data_get($parent, 'metrics')))
            ->values();
        if ($baselines->isEmpty()) {
            return ['status' => 'baseline_unavailable', 'parents' => [], 'preserved' => [], 'regressed' => [],
                'rule' => 'No contributing parent claim available to preserve.'];
        }

        $perParent = [];
        foreach ($baselines as $parent) {
            $contract = $this->noRegressionContract((array) data_get($parent, 'metrics'), $child);
            $perParent[(string) data_get($parent, 'model_version_id', count($perParent))] = $contract;
        }
        $regressed = collect($perParent)->flatMap(fn (array $contract): array => (array) data_get($contract, 'regressed', []))
            ->unique()->values()->all();
        $preserved = collect($perParent)->flatMap(fn (array $contract): array => (array) data_get($contract, 'preserved', []))
            ->unique()->values()->all();

        return [
            'status' => $regressed === [] ? 'passed' : 'failed',
            'parent_count' => $baselines->count(),
            'parents' => $perParent,
            'preserved' => $preserved,
            'regressed' => $regressed,
            'rule' => 'A capability child must preserve every gate already passed by every contributing parent; diagnostics retain parent-specific regressions.',
        ];
    }

    public function capabilityVector(array $result): array
    {
        $regimes = (array) data_get($result, 'regime_performance', []);
        $pf = max(0, (float) data_get($result, 'profit_factor', 0));
        $coverage = max(0, min(1, (float) data_get($result, 'opportunity_metrics.coverage', data_get($result, 'diagnostic_telemetry.signal_coverage', 0))));
        $dd = max(0, (float) data_get($result, 'max_drawdown_percent', data_get($result, 'max_drawdown', 100)));
        $transition = (array) data_get($result, 'transition_homework', []);
        $newsAssessed = data_get($result, 'red_team.scenarios.news_window.status') === 'assessed';
        return [
            'entry' => round(min(100, $coverage * min(2, $pf) * 50), 2),
            'exit' => round(min(100, $pf * 35), 2),
            'trend' => round(min(100, collect($regimes)->only(['trend_up', 'trend_down'])->avg(fn ($r) => max(0, (float) data_get($r, 'profit_percent', 0))) * 20), 2),
            'range' => round(min(100, max(0, (float) data_get($regimes, 'range.profit_percent', 0)) * 20), 2),
            'transition' => round(min(100, max(0, (float) data_get($transition, 'score', 0))), 2),
            'cost' => round(min(100, max(0, (float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 0)) * 50), 2),
            'news' => $newsAssessed ? ((bool) data_get($result, 'red_team.scenarios.news_window.pass') ? 100.0 : 0.0) : null,
            'coverage' => round($coverage * 100, 2),
            'abstention' => round(min(100, max(0, (float) data_get($transition, 'abstention_quality', 0))), 2),
            'calibration' => round(min(100, max(0, (float) data_get($result, 'statistical_evidence.edge_quality.confidence_calibration.score', 0))), 2),
            'risk' => round(max(0, min(100, 100 - ($dd * 3) - ((float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 100) * 2))), 2),
        ];
    }

    public function operatingEnvelope(array $result): array
    {
        $regimes = collect((array) data_get($result, 'regime_performance', []));
        $allowed = $regimes->filter(fn ($r) => (float) data_get($r, 'profit_percent', 0) > 0)->keys()->values()->all();
        return [
            'status' => $allowed === [] ? 'provisional' : 'measured', 'allowed_regimes' => $allowed,
            'allowed_sessions' => array_keys(array_filter((array) data_get($result, 'pf_attribution.by_session', []), fn ($r) => (float) data_get($r, 'net_pf', 0) >= 1.0)),
            'volatility_profile' => (array) data_get($result, 'volatility_performance', []),
            'outside_envelope_policy' => 'WAIT is valid abstention outside the measured envelope; missed opportunities inside it are coverage failures.',
        ];
    }

    public function pairedExperiment(LabAgent $agent, ?array $parent, array $current): array
    {
        $siblings = ModelMarketPerformance::query()->where('symbol', $agent->symbol)->where('timeframe', $agent->timeframe)
            ->where('strategy_family', $agent->strategy_family)->whereHas('modelVersion', fn ($q) => $q->where('generation', $agent->generation?->generation))
            ->where('model_version_id', '!=', $agent->model_version_id)->get();
        $alternative = $siblings->sortByDesc('forward_score')->first();
        if (! is_array($parent) || ! $alternative) return ['status' => 'pending', 'rule' => 'Parent, targeted child and alternative must share the same replay protocol.'];
        $currentScore = (float) data_get($current, 'forward_score', 0);
        $parentScore = (float) data_get($parent, 'forward_score', 0);
        $alternativeScore = (float) $alternative->forward_score;
        return ['status' => $currentScore > $parentScore && $currentScore > $alternativeScore ? 'confirmed' : 'not_confirmed',
            'parent_score' => $parentScore, 'targeted_score' => $currentScore, 'alternative_score' => $alternativeScore,
            'rule' => 'Targeted mutation is retained only when it beats parent and same-protocol alternative.'];
    }

    private function bundle(string $target): array
    {
        return match ($target) {
            'trade_frequency' => ['loss_cooldown_candles', 'session_start', 'session_end', 'minimum_signal_confidence'],
            'profit_factor' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles'],
            'stress_cost' => ['atr_target_multiplier', 'trailing_atr_multiplier', 'high_volatility_risk_multiplier'],
            'drawdown_risk' => ['high_volatility_risk_multiplier', 'loss_cooldown_candles', 'max_loss_streak_before_wait'],
            default => ['lookback', 'session_start', 'session_end', 'minimum_signal_confidence'],
        };
    }
}
