<?php

namespace App\Services;

use App\Models\Candle;
use App\Models\Symbol;
use App\Services\MarketData\HistoricalDataQualityService;
use Illuminate\Support\Facades\File;
use RuntimeException;

class LabDatasetExportService
{
    public function __construct(private HistoricalDataQualityService $quality) {}

    public function export(string $symbol, string $timeframe = 'H1'): string
    {
        $symbol = strtoupper($symbol);
        $symbolId = Symbol::query()->where('code', $symbol)->value('id');
        if (! $symbolId) {
            throw new RuntimeException("{$symbol} symbol topilmadi.");
        }

        $directory = storage_path('app/lab-datasets');
        File::ensureDirectoryExists($directory);
        $path = $directory."/{$symbol}_{$timeframe}.csv";
        $temporaryPath = $path.'.tmp';
        $manifestPath = $path.'.manifest.json';
        $handle = fopen($temporaryPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Dataset temporary faylini ochib bo'lmadi: {$temporaryPath}");
        }

        $sourceCount = Candle::query()->where('symbol_id', $symbolId)->where('timeframe', $timeframe)->count();
        $written = 0;

        try {
            fputcsv($handle, ['time', 'open', 'high', 'low', 'close', 'volume']);
            foreach (Candle::query()
                ->where('symbol_id', $symbolId)
                ->where('timeframe', $timeframe)
                ->orderBy('time')
                ->orderBy('id')
                ->cursor() as $candle) {
                fputcsv($handle, [
                    $candle->time->format('Y-m-d H:i:s'),
                    $candle->open,
                    $candle->high,
                    $candle->low,
                    $candle->close,
                    $candle->volume,
                ]);
                $written++;
            }
        } finally {
            fclose($handle);
        }

        if ($written !== $sourceCount) {
            File::delete($temporaryPath);
            throw new RuntimeException("Dataset export row-count mismatch: source={$sourceCount}, written={$written}.");
        }

        File::move($temporaryPath, $path);
        $quality = $this->quality->inspect($symbol, $timeframe, true);
        $manifest = [
            'schema_version' => 1,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'status' => $quality['status'],
            'row_count' => $written,
            'first_candle_at' => $quality['first_candle_at'],
            'last_candle_at' => $quality['last_candle_at'],
            'missing_open_hours' => $quality['missing_open_hours'],
            'gap_intervals' => $quality['gap_intervals'],
            'gap_examples' => $quality['gap_examples'],
            'sha256' => hash_file('sha256', $path),
            'generated_at' => now()->utc()->toIso8601String(),
        ];
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        if ($quality['status'] !== 'ready') {
            throw new RuntimeException("{$symbol} {$timeframe} historical data blocked: ".implode(' ', $quality['reasons']));
        }

        return $path;
    }
}
