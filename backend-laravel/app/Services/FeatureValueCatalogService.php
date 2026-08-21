<?php

namespace App\Services;

use App\Models\FeatureValueCatalog;

class FeatureValueCatalogService
{
    public const PROTOCOL = 'feature_value_catalog_v1';

    /** @return array<string,array<string,mixed>> */
    public function definitions(): array
    {
        $raw = [
            'open' => ['raw', 'price'], 'high' => ['raw', 'price'], 'low' => ['raw', 'price'], 'close' => ['raw', 'price'], 'volume' => ['raw', 'volume'], 'spread_atr_ratio' => ['raw', 'ratio'], 'tick_size' => ['raw', 'price'], 'tick_value' => ['raw', 'currency'],
            'body' => ['primitive', 'price'], 'wick' => ['primitive', 'price'], 'range' => ['primitive', 'price'], 'true_range' => ['primitive', 'price'],
            'atr' => ['normalized', 'price'], 'atr_percentile' => ['normalized', 'percentile'], 'z_score' => ['normalized', 'zscore'], 'slope' => ['normalized', 'ratio'], 'relative_volume' => ['normalized', 'ratio'], 'adx' => ['normalized', 'index'], 'plus_di' => ['normalized', 'index'], 'minus_di' => ['normalized', 'index'], 'macd' => ['normalized', 'price'], 'bollinger_width' => ['normalized', 'ratio'], 'bollinger_percent_b' => ['normalized', 'ratio'], 'adr' => ['normalized', 'price'],
            'confirmed_swing_high' => ['structure', 'price'], 'confirmed_swing_low' => ['structure', 'price'], 'bos_event' => ['structure', 'enum'], 'choch_event' => ['structure', 'enum'], 'mss_event' => ['structure', 'enum'], 'displacement_atr' => ['structure', 'ratio'],
            'fvg' => ['zones_liquidity', 'enum'], 'fvg_midpoint' => ['zones_liquidity', 'price'], 'order_block' => ['zones_liquidity', 'enum'], 'breaker_block' => ['zones_liquidity', 'enum'], 'liquidity_sweep' => ['zones_liquidity', 'enum'], 'liquidity_pool_score' => ['zones_liquidity', 'ratio'], 'session_high' => ['zones_liquidity', 'price'], 'session_low' => ['zones_liquidity', 'price'], 'session_range' => ['zones_liquidity', 'price'], 'dynamic_fib_618' => ['zones_liquidity', 'price'],
            'regime_probability' => ['context', 'probability'], 'transition_hazard' => ['context', 'probability'], 'liquidity_quality' => ['context', 'score'], 'volatility_state' => ['context', 'enum'], 'spread_state' => ['context', 'enum'], 'news_hazard' => ['context', 'boolean'], 'execution_quality' => ['context', 'score'], 'state_confidence' => ['context', 'probability'],
            'mae' => ['outcome_governance', 'percent'], 'mfe' => ['outcome_governance', 'percent'], 'slippage' => ['outcome_governance', 'percent'], 'drawdown' => ['outcome_governance', 'percent'], 'data_freshness_seconds' => ['outcome_governance', 'seconds'], 'lookahead_safe' => ['outcome_governance', 'boolean'],
        ];

        return collect($raw)->mapWithKeys(fn (array $item, string $key): array => [$key => ['layer' => $item[0], 'unit' => $item[1], 'formula_version' => 'v1', 'eligible_lanes' => ['research', 'paper'], 'lookahead_safe' => true]])->all();
    }

    public function seed(): void
    {
        foreach ($this->definitions() as $key => $definition) {
            FeatureValueCatalog::updateOrCreate(['feature_key' => $key], $definition + ['definition' => ['key' => $key, 'protocol' => self::PROTOCOL]]);
        }
    }
}
