<?php

namespace App\Services;

use App\Models\Candle;
use App\Models\LabGeneration;
use App\Models\Symbol;
use App\Services\MarketData\HistoricalDataQualityService;
use App\Services\MarketData\MarketVolumeService;
use Illuminate\Support\Facades\File;
use RuntimeException;

class LabDatasetExportService
{
    public function __construct(
        private HistoricalDataQualityService $quality,
        private MarketVolumeService $volumes,
    ) {}

    public function export(string $symbol, string $timeframe = 'H1', bool $includeVolume = false): string
    {
        $symbol = strtoupper($symbol);
        $symbolId = Symbol::query()->where('code', $symbol)->value('id');
        if (! $symbolId) {
            throw new RuntimeException("{$symbol} symbol topilmadi.");
        }

        $directory = storage_path('app/lab-datasets');
        File::ensureDirectoryExists($directory);
        $path = $directory."/{$symbol}_{$timeframe}".($includeVolume ? '_volume' : '').'.csv';
        $lock = fopen($path.'.lock', 'c');
        // Scheduler, manual dispatch, and full validation can request the
        // same export concurrently. Wait only a bounded interval: a real
        // concurrent export gets a chance to finish, while an orphaned lock
        // still becomes an operational error instead of blocking a worker
        // forever.
        $lockWaitSeconds = max(1, (int) config('services.lab_selection.dataset_export_lock_wait_seconds', 30));
        $lockDeadline = microtime(true) + $lockWaitSeconds;
        $locked = false;
        while ($lock !== false && microtime(true) < $lockDeadline) {
            if (flock($lock, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(250000);
        }
        if ($lock === false || ! $locked) {
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
            $volumeMap = $includeVolume ? $this->volumes->forDataset($symbol, $timeframe) : [];
            $written = 0;
            try {
                fputcsv($handle, $includeVolume
                    ? ['time', 'open', 'high', 'low', 'close', 'volume', 'volume_available']
                    : ['time', 'open', 'high', 'low', 'close', 'volume']);
                foreach (Candle::query()
                    ->where('symbol_id', $symbolId)
                    ->where('timeframe', $timeframe)
                    ->orderBy('time')
                    ->orderBy('id')
                    ->cursor() as $candle) {
                    $candleTime = $candle->time->copy()->utc();
                    $volume = $includeVolume
                        ? ($volumeMap[$candleTime->format('Y-m-d H:i:s')] ?? ['volume' => 0.0, 'available' => false])
                        : ['volume' => $candle->volume, 'available' => null];
                    $row = [
                        $candleTime->format('Y-m-d H:i:s'), $candle->open, $candle->high,
                        $candle->low, $candle->close, $volume['volume'],
                    ];
                    if ($includeVolume) $row[] = $volume['available'] ? 1 : 0;
                    fputcsv($handle, $row);
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
            $volumeQuality = $includeVolume ? $this->volumes->inspect($symbol, $timeframe) : null;
            $manifest = [
                'schema_version' => $includeVolume ? 2 : 1, 'symbol' => $symbol, 'timeframe' => $timeframe,
                'status' => $quality['status'], 'row_count' => $written,
                'first_candle_at' => $quality['first_candle_at'], 'last_candle_at' => $quality['last_candle_at'],
                'missing_open_hours' => $quality['missing_open_hours'], 'gap_intervals' => $quality['gap_intervals'],
                'gap_examples' => $quality['gap_examples'], 'sha256' => hash_file('sha256', $path),
                'volume_quality' => $volumeQuality,
                'generated_at' => now()->utc()->toIso8601String(),
            ];
            File::put($path.'.manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

            if ($quality['status'] !== 'ready') {
                throw new RuntimeException("{$symbol} {$timeframe} historical data blocked: ".implode(' ', $quality['reasons']));
            }
            if ($includeVolume && data_get($volumeQuality, 'status') !== 'passed') {
                throw new RuntimeException("{$symbol} {$timeframe} canonical volume quality gate failed.");
            }

            return $path;
        } finally {
            File::delete($temporaryPath);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Freeze one immutable dataset per generation.  The rolling live export
     * remains useful for the next generation, but a queue that drains over
     * several candles must never compare children against different windows.
     * The snapshot is created lazily as a recovery path for generations that
     * were dispatched before this contract was installed.
     *
     * @return array{path: string, manifest: array<string, mixed>, sha256: string, protocol: string}
     */
    public function ensureGenerationSnapshot(LabGeneration $generation, bool $includeVolume = false): array
    {
        $symbol = strtoupper((string) ($generation->laboratory?->symbol ?? ''));
        $timeframe = strtoupper((string) ($generation->laboratory?->timeframe ?? 'H1'));
        if ($symbol === '') throw new RuntimeException('Generation laboratory symbol topilmadi.');

        $key = $includeVolume ? 'volume' : 'price';
        $context = (array) $generation->trigger_context;
        $existing = (array) data_get($context, "canonical_dataset_snapshots.{$key}", []);
        if (is_file((string) data_get($existing, 'path', ''))
            && (string) data_get($existing, 'sha256', '') !== '') {
            return [
                'path' => (string) $existing['path'],
                'manifest' => (array) data_get($existing, 'manifest', []),
                'sha256' => (string) $existing['sha256'],
                'protocol' => (string) data_get($existing, 'protocol', 'lab_generation_dataset_snapshot_v1'),
            ];
        }

        $sourcePath = $this->export($symbol, $timeframe, $includeVolume);
        $sourceManifestPath = $sourcePath.'.manifest.json';
        $manifest = is_file($sourceManifestPath)
            ? (array) json_decode(File::get($sourceManifestPath), true)
            : [];
        $directory = storage_path('app/lab-datasets/generations');
        File::ensureDirectoryExists($directory);
        $suffix = $includeVolume ? '_volume' : '';
        $snapshotPath = $directory."/G{$generation->generation}_id{$generation->id}_{$symbol}_{$timeframe}{$suffix}.csv";
        $snapshotManifestPath = $snapshotPath.'.manifest.json';
        if (! is_file($snapshotPath) && ! copy($sourcePath, $snapshotPath)) {
            throw new RuntimeException("Generation dataset snapshot publish qilib bo'lmadi: {$snapshotPath}");
        }
        $sha256 = hash_file('sha256', $snapshotPath);
        $manifest['snapshot_protocol'] = 'lab_generation_dataset_snapshot_v1';
        $manifest['snapshot_generation_id'] = $generation->id;
        $manifest['snapshot_generation'] = $generation->generation;
        $manifest['snapshot_sha256'] = $sha256;
        $manifest['snapshot_source_sha256'] = data_get($manifest, 'sha256');
        $manifest['snapshot_frozen_at'] = now()->utc()->toIso8601String();
        File::put($snapshotManifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        data_set($context, "canonical_dataset_snapshots.{$key}", [
            'protocol' => 'lab_generation_dataset_snapshot_v1',
            'generation_id' => $generation->id,
            'generation' => $generation->generation,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'include_volume' => $includeVolume,
            'path' => $snapshotPath,
            'manifest_path' => $snapshotManifestPath,
            'sha256' => $sha256,
            'manifest' => $manifest,
            'frozen_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ]);
        $generation->update(['trigger_context' => $context]);

        return [
            'path' => $snapshotPath,
            'manifest' => $manifest,
            'sha256' => $sha256,
            'protocol' => 'lab_generation_dataset_snapshot_v1',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function rowsFromSnapshot(string $path, ?int $limit = null): array
    {
        if (! is_file($path)) throw new RuntimeException("Generation dataset snapshot topilmadi: {$path}");
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException("Generation dataset snapshot ochilmadi: {$path}");
        // Screening only needs a bounded tail of the snapshot. Keeping the
        // complete CSV in memory made a 132k-candle dataset consume >1 GB in
        // the Laravel worker before it was sliced down to the requested rows.
        // Periodic compaction avoids array_shift's O(n) cost while preserving
        // the exact last-$limit ordering required by the replay contract.
        $boundedLimit = $limit !== null && $limit > 0 ? $limit : null;
        $rows = [];
        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers)) return [];
            while (($values = fgetcsv($handle)) !== false) {
                if (count($values) !== count($headers)) continue;
                $row = array_combine($headers, $values);
                if (! is_array($row) || ! isset($row['time'])) continue;
                foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                    if (array_key_exists($field, $row)) $row[$field] = (float) $row[$field];
                }
                if (array_key_exists('volume_available', $row)) {
                    $row['volume_available'] = (bool) ((int) $row['volume_available']);
                }
                if ($boundedLimit === null) {
                    $rows[] = $row;
                } else {
                    $rows[] = $row;
                    if (count($rows) > ($boundedLimit * 2)) {
                        $rows = array_slice($rows, -$boundedLimit);
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return $boundedLimit !== null && count($rows) > $boundedLimit
            ? array_slice($rows, -$boundedLimit)
            : $rows;
    }
}
