<?php

namespace App\Services\MarketData;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OandaMarketDataProvider implements MarketDataProviderInterface
{
    public function fetchCandles(
        string $symbol,
        string $providerSymbol,
        string $timeframe,
        int $limit = 1000,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array {
        $token = (string) config('services.oanda.token');

        if ($token === '') {
            throw new RuntimeException('OANDA_API_TOKEN sozlanmagan.');
        }

        $granularity = $this->granularity($timeframe);
        $baseUrl = rtrim((string) config('services.oanda.base_url'), '/');
        $instrument = $this->instrument($providerSymbol ?: $symbol);
        $query = ['price' => 'M', 'granularity' => $granularity];

        if ($from && $to) {
            $query['from'] = $from->format(DATE_RFC3339);
            $query['to'] = $to->format(DATE_RFC3339);
        } else {
            $query['count'] = max(1, min($limit, 5000));
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->get("{$baseUrl}/v3/instruments/{$instrument}/candles", $query);

        if ($response->failed()) {
            throw new RuntimeException('OANDA candle data olishda xatolik: '.$response->body());
        }

        return collect($response->json('candles', []))
            ->filter(fn (array $candle): bool => (bool) ($candle['complete'] ?? false))
            ->map(function (array $candle): array {
                $mid = $candle['mid'] ?? [];

                return [
                    'time' => $candle['time'],
                    'open' => (float) ($mid['o'] ?? 0),
                    'high' => (float) ($mid['h'] ?? 0),
                    'low' => (float) ($mid['l'] ?? 0),
                    'close' => (float) ($mid['c'] ?? 0),
                    'volume' => (float) ($candle['volume'] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    private function granularity(string $timeframe): string
    {
        return match (strtoupper($timeframe)) {
            'M15' => 'M15',
            'H1' => 'H1',
            default => throw new RuntimeException("OANDA timeframe qo'llab-quvvatlanmaydi: {$timeframe}"),
        };
    }

    private function instrument(string $symbol): string
    {
        $symbol = strtoupper(str_replace(['/', '_'], '', $symbol));

        return strlen($symbol) === 6 ? substr($symbol, 0, 3).'_'.substr($symbol, 3) : $symbol;
    }
}
