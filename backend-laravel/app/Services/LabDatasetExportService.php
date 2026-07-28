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
        $lock = fopen($path.'.lock', 'c');
        // Scheduler, manual dispatch, and full validation can request the
        // same export concurrently.  Waiting indefinitely here leaves the
        // queue worker stuck behind a stale Windows file lock; fail fast so
        // the caller can retry on its normal cadence instead.
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }
            throw new RuntimeException("Dataset export lock olinmadi: {$symbol} {$timeframe}.");
        }

        // Dispatch and full-validation can request the same market export at
        // nearly the same instant.  A per-market OS lock plus a unique temp
        // file prevents one process from moving or deleting another's file.
        $temporaryPath = tempnam($directory, ".{$symbol}_{$timeframe}_");
        if ($temporaryPath === false) {
            flock($lock, LOCK_UN);
            fclose($lock);
            throw new RuntimeException("Dataset temporary faylini yaratib bo'lmadi: {$symbol} {$timeframe}.");
        }

        try {
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
                        $candle->time->format('Y-m-d H:i:s'), $candle->open, $candle->high,
                        $candle->low, $candle->close, $candle->volume,
                    ]);
                    $written++;
                }
            } finally {
                fclose($handle);
            }

            if ($written !== $sourceCount) {
                throw new RuntimeException("Dataset export row-count mismatch: source={$sourceCount}, written={$written}.");
            }

            // rename() cannot replace an existing file consistently on Windows.
            // Copy then remove is safe while the per-market lock is held.
            if (! copy($temporaryPath, $path)) {
                throw new RuntimeException("Dataset faylini publish qilib bo'lmadi: {$path}");
            }
            $quality = $this->quality->inspect($symbol, $timeframe, true);
            $manifest = [
                'schema_version' => 1, 'symbol' => $symbol, 'timeframe' => $timeframe,
                'status' => $quality['status'], 'row_count' => $written,
                'first_candle_at' => $quality['first_candle_at'], 'last_candle_at' => $quality['last_candle_at'],
                'missing_open_hours' => $quality['missing_open_hours'], 'gap_intervals' => $quality['gap_intervals'],
                'gap_examples' => $quality['gap_examples'], 'sha256' => hash_file('sha256', $path),
                'generated_at' => now()->utc()->toIso8601String(),
            ];
            File::put($path.'.manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

            if ($quality['status'] !== 'ready') {
                throw new RuntimeException("{$symbol} {$timeframe} historical data blocked: ".implode(' ', $quality['reasons']));
            }

            return $path;
        } finally {
            File::delete($temporaryPath);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
