<?php

namespace App\Services;

use App\Models\FeatureSnapshot;

/** Exposes only strategy-relevant, provenance-eligible values to a proposal. */
class StrategyFeatureBundleService
{
    private const BUNDLES = [
        'fibonacci_structure_pullback' => ['confirmed_swing_high', 'confirmed_swing_low', 'dynamic_fib_618', 'liquidity_sweep', 'displacement_atr', 'spread_atr_ratio', 'session_range', 'state_confidence'],
        'bos_retest_continuation' => ['bos_event', 'displacement_atr', 'relative_volume', 'fvg', 'session_range', 'spread_atr_ratio', 'slippage'],
        'choch_reversal' => ['choch_event', 'mss_event', 'liquidity_sweep', 'transition_hazard', 'volatility_state', 'news_hazard', 'state_confidence'],
        'liquidity_sweep_reversion' => ['liquidity_sweep', 'liquidity_pool_score', 'adx', 'bollinger_width', 'bollinger_percent_b', 'spread_atr_ratio'],
        'range_reentry' => ['adx', 'bollinger_width', 'bollinger_percent_b', 'z_score', 'atr_percentile', 'spread_atr_ratio'],
        'session_breakout' => ['session_high', 'session_low', 'session_range', 'displacement_atr', 'relative_volume', 'spread_atr_ratio'],
        'risk_execution' => ['tick_size', 'tick_value', 'atr', 'spread_atr_ratio', 'slippage', 'drawdown'],
    ];

    public function __construct(private FeatureProvenanceValidator $validator) {}

    /** @return array<string,mixed> */
    public function for(string $strategy, ?FeatureSnapshot $snapshot, string $lane = 'paper'): array
    {
        $keys = self::BUNDLES[$strategy] ?? self::BUNDLES['risk_execution'];
        if (! $snapshot) {
            return ['status' => 'missing_snapshot', 'values' => [], 'required_keys' => $keys, 'promotion_evidence' => false];
        }
        $values = array_intersect_key((array) $snapshot->values, array_flip($keys));
        $provenance = array_intersect_key((array) $snapshot->provenance, array_flip(array_keys($values)));
        $check = $this->validator->validate($values, $provenance, $lane);

        return ['status' => $check['valid'] ? 'eligible' : 'blocked', 'snapshot_id' => $snapshot->id, 'data_hash' => $snapshot->data_hash, 'values' => $values, 'missing_keys' => array_values(array_diff($keys, array_keys($values))), 'provenance_reasons' => $check['reasons'], 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function latestFor(string $strategy, string $symbol, string $timeframe, string $lane = 'paper'): array
    {
        return $this->for($strategy, FeatureSnapshot::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->latest('as_of')->first(), $lane);
    }
}
