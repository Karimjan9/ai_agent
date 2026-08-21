<?php

namespace App\Services;

use App\Models\MarketStateSnapshot;

/** Converts the latest causal market snapshot into a confidence-aware state contract. */
class MarketStateEstimatorService
{
    public const PROTOCOL = 'market_state_estimator_v1';

    /** @return array<string,mixed> */
    public function estimate(string $symbol, string $timeframe, array $execution = [], array $news = []): array
    {
        $snapshot = MarketStateSnapshot::query()->with('probabilities')->where('symbol', $symbol)->where('timeframe', $timeframe)->latest('time')->first();
        $features = (array) ($snapshot?->features ?? []);
        $probability = $snapshot?->probabilities->max('probability') ?? 0;
        $transition = min(1, max((float) data_get($features, 'transition_hazard', 0), (float) ($features['fake_breakout'] ?? false) * .75, (float) ($snapshot?->panic_score ?? 0) / 200));
        $spread = is_numeric($execution['spread_atr_ratio'] ?? null) ? (float) $execution['spread_atr_ratio'] : null;
        $volatility = max((float) ($snapshot?->expansion_score ?? 0), (float) ($snapshot?->panic_score ?? 0)) >= 70 ? 'high' : ((float) ($snapshot?->compression_score ?? 0) >= 70 ? 'low' : 'normal');

        return ['protocol' => self::PROTOCOL, 'state' => $snapshot?->market_state ?? 'unknown', 'regime_probability' => round((float) $probability, 4), 'transition_hazard' => round($transition, 4), 'liquidity_quality' => round((float) ($snapshot?->liquidity_proxy_score ?? 0) / 100, 4), 'volatility_state' => $volatility, 'spread_state' => $spread === null ? 'unknown' : ($spread > .25 ? 'high' : 'normal'), 'news_hazard' => (bool) ($news['active'] ?? false), 'execution_quality' => $spread === null ? 0 : round(max(0, 1 - ($spread / .25)), 4), 'state_confidence' => round((float) ($snapshot?->confidence_score ?? 0) / 100, 4), 'snapshot_id' => $snapshot?->id, 'promotion_evidence' => false];
    }
}
