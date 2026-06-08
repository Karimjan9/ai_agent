<?php

namespace Database\Seeders;

use App\Models\ModelVersion;
use Illuminate\Database\Seeder;

class ModelVersionSeeder extends Seeder
{
    public function run(): void
    {
        $versions = [
            [
                'name' => 'ema_rsi_v1',
                'strategy' => 'ema_rsi_v1',
                'version' => 'v1',
                'generation' => 1,
                'status' => 'testing',
                'description' => "EMA 50/200 va RSI 14 asosidagi boshlang'ich agent.",
                'parameters' => [
                    'ema_fast' => 50,
                    'ema_slow' => 200,
                    'rsi_period' => 14,
                    'rsi_buy_min' => 50,
                    'rsi_buy_max' => 70,
                    'rsi_sell_min' => 30,
                    'rsi_sell_max' => 50,
                ],
            ],
            [
                'name' => 'macd_trend_v1',
                'strategy' => 'macd_trend_v1',
                'version' => 'v1',
                'generation' => 1,
                'status' => 'testing',
                'description' => 'MACD + EMA100 trend filter asosidagi agent.',
                'parameters' => [
                    'ema_trend' => 100,
                    'macd_fast' => 12,
                    'macd_slow' => 26,
                    'macd_signal' => 9,
                    'rsi_period' => 14,
                ],
            ],
            [
                'name' => 'fibonacci_v1',
                'strategy' => 'fibonacci_v1',
                'version' => 'v1',
                'generation' => 1,
                'status' => 'testing',
                'description' => 'Fibonacci 0.618 pullback agent.',
                'parameters' => [
                    'lookback' => 50,
                    'fib_level' => 0.618,
                    'tolerance' => 0.002,
                    'candle_confirmation' => true,
                ],
            ],
            [
                'name' => 'breakout_v1',
                'strategy' => 'breakout_v1',
                'version' => 'v1',
                'generation' => 1,
                'status' => 'testing',
                'description' => 'Range breakout + ATR filter agent.',
                'parameters' => [
                    'lookback' => 20,
                    'atr_period' => 14,
                    'atr_multiplier' => 0.2,
                    'confirmation_candles' => 1,
                ],
            ],
        ];

        foreach ($versions as $version) {
            ModelVersion::updateOrCreate(
                [
                    'strategy' => $version['strategy'],
                    'version' => $version['version'],
                ],
                $version,
            );
        }
    }
}
