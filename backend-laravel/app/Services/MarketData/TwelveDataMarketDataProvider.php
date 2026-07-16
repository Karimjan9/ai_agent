<?php

namespace App\Services\MarketData;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TwelveDataMarketDataProvider implements MarketDataProviderInterface
{
    public function fetchCandles(
        string $symbol,
        string $providerSymbol,
        string $timeframe,
        int $limit = 1000,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array {
        $apiKey = trim((string) config('services.twelve_data.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('TWELVE_DATA_API_KEY sozlanmagan.');
        }

        $query = [
            'symbol' => $this->instrument($symbol, $providerSymbol),
            'interval' => $this->interval($timeframe),
            'outputsize' => max(1, min($limit, (int) config('services.twelve_data.max_output_size', 5000))),
            'timezone' => 'UTC',
            'order' => 'ASC',
        ];
        if ($from) $query['start_date'] = CarbonImmutable::instance($from)->utc()->format('Y-m-d H:i:s');
        if ($to) $query['end_date'] = CarbonImmutable::instance($to)->utc()->format('Y-m-d H:i:s');

        $response = Http::withHeaders(['Authorization' => 'apikey '.$apiKey])
            ->acceptJson()->timeout((int) config('services.twelve_data.timeout_seconds', 30))
            ->get(rtrim((string) config('services.twelve_data.base_url'), '/').'/time_series', $query);
        $payload = $response->json();
        if ($response->failed() || ($payload['status'] ?? 'ok') === 'error') {
            throw new RuntimeException('Twelve Data candle fetch failed: '.(string) ($payload['message'] ?? $response->body()));
        }

        return collect($payload['values'] ?? [])
            ->map(function (array $row): array {
                $time = CarbonImmutable::parse((string) ($row['datetime'] ?? ''), 'UTC');
                return [
                    'time' => $time->format('Y-m-d H:i:s'),
                    'open' => (float) ($row['open'] ?? 0), 'high' => (float) ($row['high'] ?? 0),
                    'low' => (float) ($row['low'] ?? 0), 'close' => (float) ($row['close'] ?? 0),
                    'volume' => (float) ($row['volume'] ?? 0),
                ];
            })
            ->filter(function (array $row) use ($from, $to): bool {
                $time = CarbonImmutable::parse($row['time'], 'UTC');
                return (! $from || $time->greaterThanOrEqualTo(CarbonImmutable::instance($from)->utc()))
                    && (! $to || $time->lessThan(CarbonImmutable::instance($to)->utc()));
            })
            ->sortBy('time')->values()->all();
    }

    private function interval(string $timeframe): string
    {
        return match (strtoupper($timeframe)) {
            'M15' => '15min', 'H1' => '1h',
            default => throw new RuntimeException("Twelve Data timeframe qo'llab-quvvatlanmaydi: {$timeframe}"),
        };
    }

    private function instrument(string $symbol, string $providerSymbol): string
    {
        $configured = config('services.twelve_data.instruments.'.strtoupper($symbol));
        if (is_string($configured) && $configured !== '') return $configured;
        $clean = strtoupper(str_replace(['/', '_'], '', $providerSymbol ?: $symbol));
        return strlen($clean) === 6 ? substr($clean, 0, 3).'/'.substr($clean, 3) : $clean;
    }
}
