<?php

namespace App\Services\MarketData;

use Carbon\Carbon;
use RuntimeException;

class CsvMarketDataProvider implements MarketDataProviderInterface
{
    public function fetchCandles(
        string $symbol,
        string $providerSymbol,
        string $timeframe,
        int $limit = 1000,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array {
        $path = $this->resolvePath($symbol, $timeframe);

        if (! file_exists($path)) {
            throw new RuntimeException("CSV data topilmadi: {$path}");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("CSV data o'qib bo'lmadi: {$path}");
        }

        $firstLine = fgets($handle);

        if ($firstLine === false) {
            fclose($handle);

            return [];
        }

        $delimiter = str_contains($firstLine, ';') ? ';' : ',';
        $header = str_getcsv(trim($firstLine), $delimiter);
        $header = array_map(fn (string $column): string => strtolower(trim($column)), $header);

        $rows = [];

        while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($values) !== count($header)) {
                continue;
            }

            $row = array_combine($header, $values);
            $time = $row['time'] ?? $row['date'] ?? null;

            if (! $row || empty($time)) {
                continue;
            }

            $rows[] = [
                'time' => $this->normalizeTime((string) $time),
                'open' => (float) ($row['open'] ?? 0),
                'high' => (float) ($row['high'] ?? 0),
                'low' => (float) ($row['low'] ?? 0),
                'close' => (float) ($row['close'] ?? 0),
                'volume' => (float) ($row['volume'] ?? 0),
            ];
        }

        fclose($handle);

        $rows = array_filter($rows, function (array $row) use ($from, $to): bool {
            if ($from && $row['time'] < $from->format('Y-m-d H:i:s')) {
                return false;
            }

            if ($to && $row['time'] > $to->format('Y-m-d H:i:s')) {
                return false;
            }

            return true;
        });

        return array_slice(array_values($rows), -1 * $limit);
    }

    private function resolvePath(string $symbol, string $timeframe): string
    {
        $paths = [
            storage_path("app/market-data/{$symbol}_{$timeframe}.csv"),
            storage_path("app/market-data/{$symbol}_".strtolower($timeframe).'.csv'),
        ];

        if ($symbol === 'XAUUSD' && strtoupper($timeframe) === 'H1') {
            $paths[] = storage_path('app/market-data/XAU_1h_data.csv');
        }

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return $paths[0];
    }

    private function normalizeTime(string $value): string
    {
        $value = trim($value);

        foreach (['Y.m.d H:i', 'Y.m.d H:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                continue;
            }
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }
}
