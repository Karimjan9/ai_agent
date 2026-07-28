<?php

namespace App\Services;

use InvalidArgumentException;

class StrategyParameterSchemaService
{
    private const EXECUTION_SCHEMA = [
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
        ],
        'regime_ensemble' => [
            'atr_period' => ['integer', 2, 100], 'lookback' => ['integer', 10, 100],
            'trend_strength_min' => ['numeric', 10, 50], 'pullback_atr_fraction' => ['numeric', 0.1, 2.0],
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

    public function schema(string $strategy): array
    {
        $schema = self::SCHEMAS[$this->family($strategy)] ?? [];
        return $schema ? [...$schema, ...self::EXECUTION_SCHEMA] : [];
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
            'hybrid' => ['trend_weight' => 1.0, 'breakout_weight' => 1.0, 'mean_reversion_weight' => 1.0, 'minimum_confidence' => 1.0, 'high_volatility_wait' => true],
            'regime_ensemble' => ['atr_period' => 14, 'lookback' => 20, 'trend_strength_min' => 20.0, 'pullback_atr_fraction' => .75, 'session_start' => 7, 'session_end' => 16, 'adx_max' => 20.0, 'deviation' => 2.0],
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
                default => false,
            };
            if (! $validType || ($min !== null && ($value < $min || $value > $max))) {
                throw new InvalidArgumentException("{$family}.{$key} schema talabiga mos emas.");
            }
        }

        return $parameters;
    }

    public function clamp(string $strategy, array $parameters): array
    {
        $family = $this->family($strategy);
        $schema = $this->schema($strategy);
        $clean = array_intersect_key($parameters, $schema);
        foreach ($clean as $key => $value) {
            [, $min, $max] = array_pad($schema[$key], 3, null);
            if ($min !== null && is_numeric($value)) {
                $clean[$key] = max($min, min($max, $value));
            }
        }
        return $this->validate($strategy, $clean);
    }

    private function executionDefaults(): array
    {
        return [
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
