<?php

namespace App\Services\MarketData;

use App\Models\Candle;
use App\Models\Symbol;

class CandlePayloadService
{
    public function candlesForBacktest(string $symbol, string $timeframe, ?int $limit = null): array
    {
        $symbolModel = Symbol::query()
            ->where('code', $symbol)
            ->first();

        if (! $symbolModel) {
            return [];
        }

        $query = Candle::query()
            ->where('symbol_id', $symbolModel->id)
            ->where('timeframe', $timeframe);

        if ($limit !== null) {
            $candles = $query->orderByDesc('time')->limit($limit)->get()->sortBy('time')->values();
        } else {
            $candles = $query->orderBy('time')->get();
        }

        return $candles
            ->map(fn (Candle $candle): array => [
                'time' => $candle->time->format('Y-m-d H:i:s'),
                'open' => (float) $candle->open,
                'high' => (float) $candle->high,
                'low' => (float) $candle->low,
                'close' => (float) $candle->close,
                'volume' => (float) ($candle->volume ?? 0),
            ])
            ->all();
    }
}
