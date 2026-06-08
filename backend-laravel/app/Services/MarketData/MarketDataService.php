<?php

namespace App\Services\MarketData;

use App\Models\Candle;
use App\Models\MarketSymbol;
use App\Models\Symbol;
use RuntimeException;

class MarketDataService
{
    public function __construct(
        private CsvMarketDataProvider $csvProvider,
    ) {}

    public function updateCandles(MarketSymbol $marketSymbol, string $timeframe = 'H1', int $limit = 1000): int
    {
        $provider = $this->resolveProvider();
        $candles = $provider->fetchCandles(
            symbol: $marketSymbol->symbol,
            providerSymbol: $marketSymbol->provider_symbol ?? $marketSymbol->symbol,
            timeframe: $timeframe,
            limit: $limit,
        );

        $symbol = Symbol::updateOrCreate(
            ['code' => $marketSymbol->symbol],
            [
                'display_name' => $marketSymbol->name ?? $marketSymbol->symbol,
                'asset_class' => $marketSymbol->market_type,
                'is_active' => $marketSymbol->is_active,
            ],
        );

        $saved = 0;

        foreach ($candles as $candle) {
            Candle::updateOrCreate(
                [
                    'symbol_id' => $symbol->id,
                    'timeframe' => $timeframe,
                    'time' => $candle['time'],
                ],
                [
                    'open' => $candle['open'],
                    'high' => $candle['high'],
                    'low' => $candle['low'],
                    'close' => $candle['close'],
                    'volume' => $candle['volume'] ?? 0,
                    'provider' => config('services.market_data.provider'),
                ],
            );

            $saved++;
        }

        return $saved;
    }

    private function resolveProvider(): MarketDataProviderInterface
    {
        $provider = config('services.market_data.provider', 'csv');

        return match ($provider) {
            'csv' => $this->csvProvider,
            default => throw new RuntimeException("Market data provider topilmadi: {$provider}"),
        };
    }
}
