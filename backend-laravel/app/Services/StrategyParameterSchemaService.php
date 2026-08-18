<?php

namespace App\Services;

use InvalidArgumentException;

class StrategyParameterSchemaService
{
    private const EXECUTION_SCHEMA = [
        'volume_lane' => ['string', ['none', 'breakout_volume_confirmation', 'transition_volume_router', 'low_volume_risk_firewall', 'relative_volume_confirmation_v1']],
        'atr_stop_multiplier' => ['numeric', 0.5, 4.0],
        'atr_target_multiplier' => ['numeric', 0.75, 8.0],
        'trailing_atr_multiplier' => ['numeric', 0.0, 4.0],
        'time_stop_candles' => ['integer', 0, 240],
        'high_volatility_risk_multiplier' => ['numeric', 0.1, 1.0],
        'max_spread_atr_ratio' => ['numeric', 0.01, 0.5],
        'avoid_high_volatility' => ['boolean'],
        'minimum_signal_confidence' => ['numeric', 0.0, 1.0],
        'max_loss_streak_before_wait' => ['integer', 1, 10],
        'loss_cooldown_candles' => ['integer', 1, 48],
        'loss_streak_wait_candles' => ['integer', 1, 96],
        'recovery_probe_risk_multiplier' => ['numeric', 0.1, 1.0],
        'weak_regime_min_samples' => ['integer', 15, 100],
        'weak_regime_wait_candles' => ['integer', 1, 96],
        'transition_firewall_enabled' => ['boolean'],
        'transition_wait_candles' => ['integer', 1, 6],
        'state_machine_variant' => ['string', ['none', 'neutral_transition_cooldown_reentry_v1']],
        // Structural entry topology is deliberately separate from scalar
        // timing genes.  It changes how specialists are admitted to an
        // entry, so a shadow result cannot be mistaken for a wait/EMA/ROC
        // perturbation.
        'entry_topology_variant' => ['string', [
            'frozen', 'regime_consensus_v1', 'transition_hazard_v1',
            'breakout_retest_v1', 'trend_regime_confirmation_v1',
            'range_reentry_confirmation_v1', 'volatility_persistence_v1',
        ]],
        // Regime classification is a separate executable research axis. It
        // changes how the closed candle stream is labelled before routing;
        // it is never inferred from a calendar label or promoted by itself.
        'regime_classifier_variant' => ['string', [
            'frozen', 'adx_hysteresis_v1', 'ema_slope_consensus_v1',
            'volatility_adaptive_v1',
        ]],
        'confidence_calibration_enabled' => ['boolean'],
        'confidence_calibration_min_samples' => ['integer', 15, 200],
        'confidence_ev_lower_bound_enabled' => ['boolean'],
        // Temporal survival is a research-only abstention contract. These
        // genes are deliberately separate from transition/EMA/ROC/lookback
        // mutations so a temporal failure cannot be relabelled as a generic
        // parameter improvement.
        'temporal_survival_enabled' => ['boolean'],
        'adaptive_signal_expiry_enabled' => ['boolean'],
        'drift_abstention_enabled' => ['boolean'],
        'signal_max_age_candles' => ['integer', 1, 24],
        'signal_decay_half_life_candles' => ['integer', 1, 24],
        'temporal_followthrough_window' => ['integer', 1, 12],
        'temporal_followthrough_min_rate' => ['numeric', 0.0, 1.0],
        'temporal_followthrough_atr_fraction' => ['numeric', 0.05, 2.0],
        'temporal_volatility_ratio_max' => ['numeric', 1.0, 4.0],
        'temporal_spread_atr_ratio_max' => ['numeric', 0.01, 0.5],
        'temporal_drift_zscore_max' => ['numeric', 0.5, 5.0],
        'temporal_confidence_decay_floor' => ['numeric', 0.0, 1.0],
        'temporal_loss_streak_limit' => ['integer', 1, 10],
        'temporal_min_history' => ['integer', 5, 100],
        'temporal_drift_lookback_candles' => ['integer', 10, 200],
        'dynamic_cooldown_enabled' => ['boolean'],
        'cooldown_shadow_min_samples' => ['integer', 3, 50],
        'cooldown_shadow_edge_pf' => ['numeric', 0.8, 2.0],
        'meta_label_enabled' => ['boolean'],
        'meta_label_min_history' => ['integer', 5, 100],
        'meta_label_min_pf' => ['numeric', 0.5, 2.0],
        'meta_label_risk_multiplier' => ['numeric', 0.1, 1.0],
        'partial_take_profit_fraction' => ['numeric', 0.0, 0.75],
        'partial_target_atr_multiplier' => ['numeric', 0.25, 4.0],
    ];

    private const SCHEMAS = [
        'breakout' => [
            'lookback' => ['integer', 10, 100],
            'atr_period' => ['integer', 2, 100],
            'atr_multiplier' => ['numeric', 0.1, 3.0],
            'confirmation_candles' => ['integer', 1, 5],
            'retest_required' => ['boolean'], 'trend_strength_min' => ['numeric', 10, 50],
        ],
        'ema_rsi' => [
            'ema_fast' => ['integer', 2, 200], 'ema_slow' => ['integer', 3, 500],
            'rsi_period' => ['integer', 2, 100], 'rsi_buy_min' => ['numeric', 0, 100],
            'rsi_buy_max' => ['numeric', 0, 100], 'rsi_sell_min' => ['numeric', 0, 100],
            'rsi_sell_max' => ['numeric', 0, 100],
        ],
        'fibonacci' => [
            'lookback' => ['integer', 10, 300], 'fib_level' => ['numeric', 0, 1],
            'tolerance' => ['numeric', 0.0001, 0.05],
            'candle_confirmation' => ['boolean'], 'trend_confirmation' => ['boolean'],
        ],
        'macd_trend' => [
            'ema_trend' => ['integer', 10, 500], 'macd_fast' => ['integer', 2, 100],
            'macd_slow' => ['integer', 3, 200], 'macd_signal' => ['integer', 2, 100],
            'rsi_period' => ['integer', 2, 100],
        ],
        'trend' => [
            'ema_fast' => ['integer', 2, 200], 'ema_slow' => ['integer', 3, 500],
            'rsi_period' => ['integer', 2, 100], 'rsi_buy_min' => ['numeric', 0, 100],
            'rsi_buy_max' => ['numeric', 0, 100], 'rsi_sell_min' => ['numeric', 0, 100],
            'rsi_sell_max' => ['numeric', 0, 100], 'trend_strength_min' => ['numeric', 10, 50],
            'pullback_atr_fraction' => ['numeric', 0.1, 2.0],
        ],
        'volatility' => [
            'atr_period' => ['integer', 2, 100], 'atr_threshold' => ['numeric', 0.1, 5.0],
            'lookback' => ['integer', 10, 100], 'compression_ratio' => ['numeric', 0.3, 1.0],
            'expansion_multiplier' => ['numeric', 1.0, 3.0],
        ],
        'mean_reversion' => [
            'lookback' => ['integer', 10, 200], 'deviation' => ['numeric', 0.5, 4.0],
            'rsi_period' => ['integer', 2, 100], 'adx_max' => ['numeric', 5, 35], 'low_volatility_only' => ['boolean'],
        ],
        'session' => [
            'session_start' => ['integer', 0, 23], 'session_end' => ['integer', 1, 24],
            'lookback' => ['integer', 5, 100],
        ],
        'momentum' => [
            'roc_period' => ['integer', 2, 100], 'roc_threshold' => ['numeric', 0.01, 10.0],
            'ema_period' => ['integer', 2, 300],
        ],
        'hybrid' => [
            'trend_weight' => ['numeric', 0.0, 3.0], 'breakout_weight' => ['numeric', 0.0, 3.0],
            'mean_reversion_weight' => ['numeric', 0.0, 3.0], 'minimum_confidence' => ['numeric', 0.1, 6.0],
            'high_volatility_wait' => ['boolean'],
            'trend_roc_period' => ['integer', 4, 60], 'trend_roc_threshold' => ['numeric', .01, 5.0], 'trend_ema_period' => ['integer', 10, 300],
            'breakout_atr_period' => ['integer', 2, 100], 'breakout_atr_threshold' => ['numeric', .1, 5.0], 'breakout_lookback' => ['integer', 10, 100],
            'breakout_compression_ratio' => ['numeric', .3, 1.0], 'breakout_expansion_multiplier' => ['numeric', 1.0, 3.0],
            'range_lookback' => ['integer', 10, 200], 'range_deviation' => ['numeric', .5, 4.0], 'range_adx_max' => ['numeric', 5, 35],
            'range_low_volatility_only' => ['boolean'], 'range_reentry_required' => ['boolean'],
            'range_signal_mode' => ['string', ['reentry', 'mean_reversion', 'inverse_extreme', 'mid_cross']],
            'session_filter_enabled' => ['boolean'],
            'session_start' => ['integer', 0, 23], 'session_end' => ['integer', 1, 24],
        ],
        'differential_router' => [
            'trend_weight' => ['numeric', 0.0, 3.0], 'breakout_weight' => ['numeric', 0.0, 3.0],
            'mean_reversion_weight' => ['numeric', 0.0, 3.0], 'minimum_confidence' => ['numeric', 0.1, 6.0],
            'high_volatility_wait' => ['boolean'], 'differential_target_min_signal_confidence' => ['numeric', 0.0, 1.0],
            'trend_down_strength_min' => ['numeric', 10, 50],
            'trend_down_pullback_atr_fraction' => ['numeric', .1, 2.0], 'trend_down_risk_multiplier' => ['numeric', .1, 1.0], 'trend_up_risk_multiplier' => ['numeric', .1, 1.0],
            'trend_up_strength_min' => ['numeric', 10, 50], 'trend_up_pullback_atr_fraction' => ['numeric', .1, 2.0],
            'trend_up_roc_period' => ['integer', 4, 60], 'trend_up_roc_threshold' => ['numeric', .01, 5.0], 'trend_up_ema_period' => ['integer', 10, 300],
            'trend_down_roc_period' => ['integer', 4, 60], 'trend_down_roc_threshold' => ['numeric', .01, 5.0], 'trend_down_ema_period' => ['integer', 10, 300],
            'range_lookback' => ['integer', 10, 200], 'range_deviation' => ['numeric', .5, 4.0],
            'range_adx_max' => ['numeric', 5, 35], 'range_low_volatility_only' => ['boolean'], 'range_reentry_required' => ['boolean'],
            'range_signal_mode' => ['string', ['reentry', 'mean_reversion', 'inverse_extreme', 'mid_cross']],
            'trend_roc_period' => ['integer', 4, 60], 'trend_roc_threshold' => ['numeric', .01, 5.0], 'trend_ema_period' => ['integer', 10, 300],
            'breakout_atr_period' => ['integer', 2, 100], 'breakout_atr_threshold' => ['numeric', .1, 5.0], 'breakout_lookback' => ['integer', 10, 100],
            'breakout_compression_ratio' => ['numeric', .3, 1.0], 'breakout_expansion_multiplier' => ['numeric', 1.0, 3.0],
            'session_filter_enabled' => ['boolean'], 'session_start' => ['integer', 0, 23], 'session_end' => ['integer', 1, 24],
            'differential_target_session_filter_enabled' => ['boolean'], 'differential_target_session_start' => ['integer', 0, 23], 'differential_target_session_end' => ['integer', 1, 24],
            'differential_target_regime' => ['string', ['trend_up', 'range', 'trend_down']],
            'differential_replay_mode' => ['string', ['portfolio', 'paired_isolated']],
            'differential_router_version' => ['string', ['v1', 'v2']],
        ],
        'regime_ensemble' => [
            'atr_period' => ['integer', 2, 100], 'lookback' => ['integer', 10, 100],
            'trend_strength_min' => ['numeric', 10, 50], 'pullback_atr_fraction' => ['numeric', 0.1, 2.0],
            'trend_down_strength_min' => ['numeric', 10, 50], 'trend_down_pullback_atr_fraction' => ['numeric', 0.1, 2.0],
            'trend_down_risk_multiplier' => ['numeric', 0.1, 1.0],
            'session_start' => ['integer', 0, 23], 'session_end' => ['integer', 1, 24],
            'adx_max' => ['numeric', 5, 35], 'deviation' => ['numeric', 0.5, 4.0],
        ],
    ];

    public function family(string $strategy): string
    {
        $family = preg_replace('/^(xauusd|eurusd|gbpusd)_/', '', strtolower($strategy));
        $family = preg_replace('/_g\d+_a\d+$/', '', $family ?? strtolower($strategy));
        return preg_replace('/_v\d+$/', '', $family) ?: $family;
    }

    /**
     * Return the runtime function identity sent to Python. Composite agents
     * may preserve a parent architecture in metadata, but that parent must
     * never replace the differential/ensemble runtime selected by the model
     * family.
     */
    public function runtimeBaseStrategy(string $strategy, ?string $baseStrategy = null, ?string $family = null): string
    {
        $strategyFamily = $this->family($strategy);
        $declaredFamily = strtolower(trim((string) $family));
        $specialized = ['differential_router', 'regime_ensemble'];

        if (in_array($strategyFamily, $specialized, true)) return $strategyFamily.'_v1';
        if (in_array($declaredFamily, $specialized, true)) return $declaredFamily.'_v1';

        $base = trim((string) $baseStrategy);
        return $base !== '' ? $base : (($declaredFamily ?: $strategyFamily).'_v1');
    }

    public function schema(string $strategy): array
    {
        $schema = self::SCHEMAS[$this->family($strategy)] ?? [];
        return $schema ? [...$schema, ...self::EXECUTION_SCHEMA] : [];
    }

    /**
     * Produce the stable identity representation used by every parameter
     * hash. JSON/database hydration may expose an integer schema value as a
     * float (or a numeric value with harmless trailing precision); that is a
     * serialization detail, not a new executable topology.
     */
    public function canonicalizeForIdentity(string $strategy, array $parameters): array
    {
        $schema = $this->schema($strategy);
        $canonical = [];

        foreach ($parameters as $key => $value) {
            [$type] = array_pad($schema[$key] ?? [], 3, null);

            $canonical[$key] = match ($type) {
                'integer' => is_numeric($value) ? (int) round((float) $value) : $value,
                'numeric' => is_numeric($value) ? round((float) $value, 12) : $value,
                'boolean' => (bool) $value,
                'string' => (string) $value,
                default => $value,
            };
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }

    public function defaults(string $strategy): array
    {
        $defaults = match ($this->family($strategy)) {
            'breakout' => ['lookback' => 20, 'atr_period' => 14, 'atr_multiplier' => 0.2, 'confirmation_candles' => 1, 'retest_required' => true, 'trend_strength_min' => 20.0],
            'trend', 'ema_rsi' => ['ema_fast' => 50, 'ema_slow' => 200, 'rsi_period' => 14, 'rsi_buy_min' => 50.0, 'rsi_buy_max' => 70.0, 'rsi_sell_min' => 30.0, 'rsi_sell_max' => 50.0, 'trend_strength_min' => 20.0, 'pullback_atr_fraction' => 0.75],
            'volatility' => ['atr_period' => 14, 'atr_threshold' => 1.2, 'lookback' => 20, 'compression_ratio' => 0.75, 'expansion_multiplier' => 1.2],
            'mean_reversion' => ['lookback' => 20, 'deviation' => 2.0, 'rsi_period' => 14, 'adx_max' => 20.0, 'low_volatility_only' => true],
            'session' => ['session_start' => 7, 'session_end' => 16, 'lookback' => 20],
            'momentum' => ['roc_period' => 12, 'roc_threshold' => 0.2, 'ema_period' => 50],
            'hybrid' => ['trend_weight' => 1.0, 'breakout_weight' => 1.0, 'mean_reversion_weight' => 1.0, 'minimum_confidence' => 1.0, 'high_volatility_wait' => true,
                'trend_roc_period' => 12, 'trend_roc_threshold' => .2, 'trend_ema_period' => 50,
                'breakout_atr_period' => 14, 'breakout_atr_threshold' => 1.2, 'breakout_lookback' => 20,
                'breakout_compression_ratio' => .75, 'breakout_expansion_multiplier' => 1.2,
                'range_lookback' => 20, 'range_deviation' => 2.0, 'range_adx_max' => 20.0, 'range_low_volatility_only' => true,
                'range_reentry_required' => true, 'range_signal_mode' => 'reentry',
                'session_filter_enabled' => false, 'session_start' => 0, 'session_end' => 24],
            'differential_router' => ['trend_weight' => 1.0, 'breakout_weight' => 1.0, 'mean_reversion_weight' => 1.0, 'minimum_confidence' => 1.0,
                'high_volatility_wait' => true, 'differential_target_min_signal_confidence' => .34,
                'trend_down_strength_min' => 20.0, 'trend_down_pullback_atr_fraction' => .75, 'trend_down_risk_multiplier' => .5, 'trend_up_risk_multiplier' => 1.0,
                'trend_up_strength_min' => 20.0, 'trend_up_pullback_atr_fraction' => .75,
                'trend_up_roc_period' => 12, 'trend_up_roc_threshold' => .2, 'trend_up_ema_period' => 50,
                'trend_down_roc_period' => 12, 'trend_down_roc_threshold' => .2, 'trend_down_ema_period' => 50,
                'range_lookback' => 20, 'range_deviation' => 2.0, 'range_adx_max' => 20.0, 'range_low_volatility_only' => false,
                'range_reentry_required' => true, 'range_signal_mode' => 'reentry',
                'trend_roc_period' => 12, 'trend_roc_threshold' => .2, 'trend_ema_period' => 50,
                'breakout_atr_period' => 14, 'breakout_atr_threshold' => 1.2, 'breakout_lookback' => 20,
                'breakout_compression_ratio' => .75, 'breakout_expansion_multiplier' => 1.2,
                'session_filter_enabled' => false, 'session_start' => 0, 'session_end' => 24,
                'differential_target_session_filter_enabled' => false, 'differential_target_session_start' => 7, 'differential_target_session_end' => 16,
                'differential_target_regime' => 'trend_down', 'differential_replay_mode' => 'paired_isolated', 'differential_router_version' => 'v2'],
            'regime_ensemble' => ['atr_period' => 14, 'lookback' => 20, 'trend_strength_min' => 20.0, 'pullback_atr_fraction' => .75,
                'trend_down_strength_min' => 28.0, 'trend_down_pullback_atr_fraction' => .60, 'trend_down_risk_multiplier' => .50,
                'session_start' => 7, 'session_end' => 16, 'adx_max' => 20.0, 'deviation' => 2.0],
            'fibonacci' => ['lookback' => 50, 'fib_level' => 0.618, 'tolerance' => 0.002, 'candle_confirmation' => true, 'trend_confirmation' => false],
            'macd_trend' => ['ema_trend' => 100, 'macd_fast' => 12, 'macd_slow' => 26, 'macd_signal' => 9, 'rsi_period' => 14],
            default => [],
        };
        return $defaults ? [...$defaults, ...$this->executionDefaults()] : [];
    }

    public function validate(string $strategy, array $parameters): array
    {
        $family = $this->family($strategy);
        $schema = $this->schema($strategy);
        if (! $schema) {
            throw new InvalidArgumentException("Parameter schema topilmadi: {$family}");
        }

        $unknown = array_diff(array_keys($parameters), array_keys($schema));
        if ($unknown) {
            throw new InvalidArgumentException("{$family} uchun noma'lum parametrlar: ".implode(', ', $unknown));
        }

        foreach ($parameters as $key => $value) {
            [$type, $min, $max] = array_pad($schema[$key], 3, null);
            $validType = match ($type) {
                'integer' => is_int($value),
                'numeric' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'string' => is_string($value) && in_array($value, (array) $min, true),
                default => false,
            };
            $outOfRange = in_array($type, ['integer', 'numeric'], true)
                && $min !== null && ($value < $min || $value > $max);
            if (! $validType || $outOfRange) {
                throw new InvalidArgumentException("{$family}.{$key} schema talabiga mos emas.");
            }
        }

        return $parameters;
    }

    /**
     * Repair relationships between genes before a generated child is
     * evaluated.  Scalar range validation alone permits executable but
     * semantically inverted strategies (for example ema_fast >= ema_slow),
     * which turns evolutionary search into mostly invalid signal experiments.
     * This is used only for newly generated children; historical evidence is
     * never rewritten.
     */
    public function normalizeForGeneration(string $strategy, array $parameters): array
    {
        $family = $this->family($strategy);

        if (in_array($family, ['trend', 'ema_rsi'], true)
            && isset($parameters['ema_fast'], $parameters['ema_slow'])) {
            $fast = (int) $parameters['ema_fast'];
            $slow = (int) $parameters['ema_slow'];
            if ($fast >= $slow) {
                $parameters['ema_slow'] = min(500, max(3, $fast + 1));
            }
        }

        if (in_array($family, ['trend', 'ema_rsi'], true)) {
            foreach ([['rsi_buy_min', 'rsi_buy_max'], ['rsi_sell_min', 'rsi_sell_max']] as [$lower, $upper]) {
                if (isset($parameters[$lower], $parameters[$upper])
                    && (float) $parameters[$lower] > (float) $parameters[$upper]) {
                    [$parameters[$lower], $parameters[$upper]] = [$parameters[$upper], $parameters[$lower]];
                }
            }
        }

        if ($family === 'macd_trend'
            && isset($parameters['macd_fast'], $parameters['macd_slow'])
            && (int) $parameters['macd_fast'] >= (int) $parameters['macd_slow']) {
            $parameters['macd_slow'] = min(200, max(3, (int) $parameters['macd_fast'] + 1));
        }

        if (in_array($family, ['hybrid', 'differential_router'], true)
            && isset($parameters['session_start'], $parameters['session_end'])
            && (int) $parameters['session_start'] >= (int) $parameters['session_end']) {
            $parameters['session_end'] = min(24, max(1, (int) $parameters['session_start'] + 1));
            if ((int) $parameters['session_start'] >= 23) $parameters['session_start'] = 22;
        }

        return $parameters;
    }

    public function clamp(string $strategy, array $parameters): array
    {
        $family = $this->family($strategy);
        $schema = $this->schema($strategy);
        $clean = array_intersect_key($parameters, $schema);
        foreach ($clean as $key => $value) {
            [$type, $min, $max] = array_pad($schema[$key], 3, null);
            if (in_array($type, ['integer', 'numeric'], true) && $min !== null && is_numeric($value)) {
                $clean[$key] = max($min, min($max, $value));
            }
        }
        return $this->validate($strategy, $clean);
    }

    private function executionDefaults(): array
    {
        return [
            // The runtime schema already exposes this routing coordinate;
            // keep its canonical default present in every generated model so
            // the volume/M15 shadow lane can make a truthful one-gene probe.
            'volume_lane' => 'none',
            'atr_stop_multiplier' => 1.5,
            'atr_target_multiplier' => 2.5,
            'trailing_atr_multiplier' => 0.0,
            'time_stop_candles' => 0,
            'high_volatility_risk_multiplier' => 0.5,
            'max_spread_atr_ratio' => 0.25,
            'avoid_high_volatility' => false,
            'minimum_signal_confidence' => 0.35,
            'max_loss_streak_before_wait' => 4,
            'loss_cooldown_candles' => 4,
            'loss_streak_wait_candles' => 4,
            'recovery_probe_risk_multiplier' => 0.5,
            'weak_regime_min_samples' => 15,
            'weak_regime_wait_candles' => 4,
            'transition_firewall_enabled' => false,
            'transition_wait_candles' => 2,
            'state_machine_variant' => 'none',
            'entry_topology_variant' => 'frozen',
            'regime_classifier_variant' => 'frozen',
            'confidence_calibration_enabled' => true,
            'confidence_calibration_min_samples' => 15,
            'confidence_ev_lower_bound_enabled' => true,
            'temporal_survival_enabled' => false,
            'adaptive_signal_expiry_enabled' => false,
            'drift_abstention_enabled' => false,
            'signal_max_age_candles' => 2,
            'signal_decay_half_life_candles' => 3,
            'temporal_followthrough_window' => 3,
            'temporal_followthrough_min_rate' => .40,
            'temporal_followthrough_atr_fraction' => .25,
            'temporal_volatility_ratio_max' => 2.5,
            'temporal_spread_atr_ratio_max' => .25,
            'temporal_drift_zscore_max' => 2.5,
            'temporal_confidence_decay_floor' => .35,
            'temporal_loss_streak_limit' => 4,
            'temporal_min_history' => 12,
            'temporal_drift_lookback_candles' => 48,
            'dynamic_cooldown_enabled' => true,
            'cooldown_shadow_min_samples' => 5,
            'cooldown_shadow_edge_pf' => 1.1,
            'meta_label_enabled' => false,
            'meta_label_min_history' => 10,
            'meta_label_min_pf' => 1.0,
            'meta_label_risk_multiplier' => .5,
            'partial_take_profit_fraction' => 0.0,
            'partial_target_atr_multiplier' => 1.0,
        ];
    }
}
