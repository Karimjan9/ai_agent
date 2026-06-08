<?php

namespace App\Services\MarketData;

use RuntimeException;

class CsvMarketDataProvider implements MarketDataProviderInterface
{
    public function fetchCandles(
        string $symbol,
        string $providerSymbol,
        string $timeframe,
        int $limit = 1000,
    ): array {
        $path = storage_path("app/market-data/{$symbol}_{$timeframe}.csv");

        if (! file_exists($path)) {
            throw new RuntimeException("CSV data topilmadi: {$path}");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("CSV data o'qib bo'lmadi: {$path}");
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return [];
        }

        $rows = [];

        while (($values = fgetcsv($handle)) !== false) {
            if (count($values) !== count($header)) {
                continue;
            }

            $row = array_combine($header, $values);

            if (! $row || empty($row['time'])) {
                continue;
            }

            $rows[] = [
                'time' => $row['time'],
                'open' => (float) ($row['open'] ?? 0),
                'high' => (float) ($row['high'] ?? 0),
                'low' => (float) ($row['low'] ?? 0),
                'close' => (float) ($row['close'] ?? 0),
                'volume' => (float) ($row['volume'] ?? 0),
            ];
        }

        fclose($handle);

        return array_slice($rows, -1 * $limit);
    }
}
