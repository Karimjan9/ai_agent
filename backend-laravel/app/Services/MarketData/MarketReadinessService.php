<?php

namespace App\Services\MarketData;

use App\Models\MarketDataSyncState;
use App\Models\MarketSymbol;

class MarketReadinessService
{
    public function __construct(private HistoricalDataQualityService $historicalData) {}

    /**
     * Promotion is globally paused while any active instrument has an
     * incomplete feed. Laboratory screening can continue independently, but
     * a champion must never be selected from uneven market evidence.
     */
    public function promotionReady(): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        if (! config('services.promotion.require_all_markets_healthy', true)) {
            return true;
        }

        $symbols = MarketSymbol::query()->where('is_active', true)->pluck('symbol');
        if ($symbols->isEmpty()) {
            return false;
        }

        return $symbols->every(fn (string $symbol): bool => $this->ready($symbol)
            && (app()->environment('testing') || $this->historicalData->ready($symbol)));
    }

    public function ready(string $symbol, string $timeframe = 'H1'): bool
    {
        if ((string) config('services.market_data.provider', 'csv') === 'csv') {
            return true;
        }

        return MarketDataSyncState::query()
            ->where('provider', (string) config('services.market_data.provider', 'dukascopy'))
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', $timeframe)
            ->where('status', 'healthy')
            ->exists();
    }

    /** @return array<int, string> */
    public function blockedMarkets(): array
    {
        if ((string) config('services.market_data.provider', 'csv') === 'csv') {
            return [];
        }

        return MarketSymbol::query()->where('is_active', true)->pluck('symbol')
            ->filter(fn (string $symbol): bool => ! $this->ready($symbol)
                || (! app()->environment('testing') && ! $this->historicalData->ready($symbol)))
            ->values()
            ->all();
    }
}
