<?php

namespace App\Services;

use InvalidArgumentException;

class StrategyParameterSchemaService
{
    private const SCHEMAS = [
        'breakout' => [
            'lookback' => ['integer', 10, 100],
            'atr_period' => ['integer', 2, 100],
            'atr_multiplier' => ['numeric', 0.1, 3.0],
            'confirmation_candles' => ['integer', 1, 5],
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
            'rsi_sell_max' => ['numeric', 0, 100],
        ],
        'volatility' => [
            'atr_period' => ['integer', 2, 100], 'atr_threshold' => ['numeric', 0.1, 5.0],
            'lookback' => ['integer', 10, 100],
        ],
        'mean_reversion' => [
            'lookback' => ['integer', 10, 200], 'deviation' => ['numeric', 0.5, 4.0],
            'rsi_period' => ['integer', 2, 100],
        ],
        'session' => [
            'session_start' => ['integer', 0, 23], 'session_end' => ['integer', 1, 24],
            'lookback' => ['integer', 5, 100],
        ],
        'momentum' => [
            'roc_period' => ['integer', 2, 100], 'roc_threshold' => ['numeric', 0.01, 10.0],
            'ema_period' => ['integer', 2, 300],
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
        return self::SCHEMAS[$this->family($strategy)] ?? [];
    }

    public function defaults(string $strategy): array
    {
        return match ($this->family($strategy)) {
            'breakout' => ['lookback' => 20, 'atr_period' => 14, 'atr_multiplier' => 0.2, 'confirmation_candles' => 1],
            'trend', 'ema_rsi' => ['ema_fast' => 50, 'ema_slow' => 200, 'rsi_period' => 14, 'rsi_buy_min' => 50.0, 'rsi_buy_max' => 70.0, 'rsi_sell_min' => 30.0, 'rsi_sell_max' => 50.0],
            'volatility' => ['atr_period' => 14, 'atr_threshold' => 1.2, 'lookback' => 20],
            'mean_reversion' => ['lookback' => 20, 'deviation' => 2.0, 'rsi_period' => 14],
            'session' => ['session_start' => 7, 'session_end' => 16, 'lookback' => 20],
            'momentum' => ['roc_period' => 12, 'roc_threshold' => 0.2, 'ema_period' => 50],
            'fibonacci' => ['lookback' => 50, 'fib_level' => 0.618, 'tolerance' => 0.002, 'candle_confirmation' => true, 'trend_confirmation' => false],
            'macd_trend' => ['ema_trend' => 100, 'macd_fast' => 12, 'macd_slow' => 26, 'macd_signal' => 9, 'rsi_period' => 14],
            default => [],
        };
    }

    public function validate(string $strategy, array $parameters): array
    {
        $family = $this->family($strategy);
        $schema = self::SCHEMAS[$family] ?? null;
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
        $schema = self::SCHEMAS[$family] ?? [];
        $clean = array_intersect_key($parameters, $schema);
        foreach ($clean as $key => $value) {
            [, $min, $max] = array_pad($schema[$key], 3, null);
            if ($min !== null && is_numeric($value)) {
                $clean[$key] = max($min, min($max, $value));
            }
        }
        return $this->validate($strategy, $clean);
    }
}
