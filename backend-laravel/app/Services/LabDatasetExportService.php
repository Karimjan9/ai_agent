<?php

namespace App\Services;

use App\Models\Candle;
use App\Models\LabGeneration;
use App\Models\Symbol;
use App\Services\MarketData\HistoricalDataQualityService;
use App\Services\MarketData\MarketVolumeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use RuntimeException;

class LabDatasetExportService
{
    public function __construct(
        private HistoricalDataQualityService $quality,
        private MarketVolumeService $volumes,
        private \App\Services\MarketData\DukascopyMarketDataProvider $foundationProvider,
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
        $existingPath = (string) data_get($existing, 'path', '');
        $existingSha = (string) data_get($existing, 'sha256', '');
        if (is_file($existingPath) && $existingSha !== '') {
            $actualSha = hash_file('sha256', $existingPath);
            if (! is_string($actualSha) || ! hash_equals($existingSha, $actualSha)) {
                throw new RuntimeException("Generation dataset snapshot hash mismatch; evidence is frozen and replay is blocked: {$existingPath}");
            }

            return [
                'path' => $existingPath,
                'manifest' => (array) data_get($existing, 'manifest', []),
                'sha256' => $existingSha,
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

    /**
     * Freeze the H1 regime input for an M15 generation. The snapshot is
     * bounded at the last closed H1 candle; an open H1 candle can therefore
     * never leak into M15 screening, full replay, or cache identity.
     *
     * @return array{path: string, manifest: array<string, mixed>, sha256: string, protocol: string}
     */
    public function ensureGenerationRegimeSnapshot(LabGeneration $generation): array
    {
        $symbol = strtoupper((string) ($generation->laboratory?->symbol ?? ''));
        $timeframe = strtoupper((string) ($generation->laboratory?->timeframe ?? 'H1'));
        if ($symbol === '') {
            throw new RuntimeException('Generation laboratory symbol topilmadi.');
        }
        if ($timeframe !== 'M15') {
            throw new RuntimeException('H1 regime snapshot faqat M15 generation uchun kerak.');
        }

        $directory = storage_path('app/lab-datasets/generations');
        File::ensureDirectoryExists($directory);
        $lockPath = $directory."/.G{$generation->generation}_id{$generation->id}_{$symbol}_H1_regime.lock";
        $lock = fopen($lockPath, 'c');
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
            throw new RuntimeException("M15 H1 regime snapshot lock olinmadi: {$symbol} G{$generation->generation}.");
        }

        try {
            // Another scheduler/worker may have published the snapshot while
            // this caller was waiting. Refresh the generation context before
            // deciding whether materialization is still necessary.
            $generation->refresh();

            return $this->materializeGenerationRegimeSnapshot($generation);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * The caller holds the per-generation regime publication lock.
     *
     * @return array{path: string, manifest: array<string, mixed>, sha256: string, protocol: string}
     */
    private function materializeGenerationRegimeSnapshot(LabGeneration $generation): array
    {
        $symbol = strtoupper((string) ($generation->laboratory?->symbol ?? ''));
        $timeframe = strtoupper((string) ($generation->laboratory?->timeframe ?? 'H1'));
        if ($symbol === '') {
            throw new RuntimeException('Generation laboratory symbol topilmadi.');
        }
        if ($timeframe !== 'M15') {
            throw new RuntimeException('H1 regime snapshot faqat M15 generation uchun kerak.');
        }

        $context = (array) $generation->trigger_context;
        $existing = (array) data_get($context, 'canonical_dataset_snapshots.regime', []);
        $existingPath = (string) data_get($existing, 'path', '');
        $existingSha = (string) data_get($existing, 'sha256', '');
        if (is_file($existingPath) && $existingSha !== '') {
            $actualSha = hash_file('sha256', $existingPath);
            if (! is_string($actualSha) || ! hash_equals($existingSha, $actualSha)) {
                throw new RuntimeException("M15 H1 regime snapshot hash mismatch; evidence is frozen: {$existingPath}");
            }

            return [
                'path' => $existingPath,
                'manifest' => (array) data_get($existing, 'manifest', []),
                'sha256' => $existingSha,
                'protocol' => (string) data_get($existing, 'protocol', 'lab_generation_regime_snapshot_v1'),
            ];
        }

        $sourcePath = $this->export($symbol, 'H1', false);
        $rows = $this->rowsFromSnapshot($sourcePath);
        $closedCutoff = now()->utc()->startOfHour()->subHour();
        $rows = array_values(array_filter($rows, static function (array $row) use ($closedCutoff): bool {
            try {
                return CarbonImmutable::parse((string) ($row['time'] ?? ''), 'UTC')->lessThanOrEqualTo($closedCutoff);
            } catch (\Throwable) {
                return false;
            }
        }));
        if (count($rows) < 204) {
            throw new RuntimeException("M15 H1 closed regime snapshot uchun candle yetarli emas: {$symbol} rows=".count($rows));
        }

        $directory = storage_path('app/lab-datasets/generations');
        File::ensureDirectoryExists($directory);
        $snapshotPath = $directory."/G{$generation->generation}_id{$generation->id}_{$symbol}_H1_regime.csv";
        $snapshotManifestPath = $snapshotPath.'.manifest.json';
        $temporaryPath = tempnam($directory, ".{$symbol}_H1_regime_");
        if ($temporaryPath === false) {
            throw new RuntimeException("M15 H1 regime temporary fayli yaratilmadi: {$symbol}.");
        }

        try {
            $handle = fopen($temporaryPath, 'wb');
            if ($handle === false) {
                throw new RuntimeException("M15 H1 regime temporary fayli ochilmadi: {$symbol}.");
            }
            fputcsv($handle, ['time', 'open', 'high', 'low', 'close', 'volume']);
            foreach ($rows as $row) {
                fputcsv($handle, [
                    (string) $row['time'],
                    (float) ($row['open'] ?? 0),
                    (float) ($row['high'] ?? 0),
                    (float) ($row['low'] ?? 0),
                    (float) ($row['close'] ?? 0),
                    (float) ($row['volume'] ?? 0),
                ]);
            }
            fclose($handle);
            if (! copy($temporaryPath, $snapshotPath)) {
                throw new RuntimeException("M15 H1 regime snapshot publish qilinmadi: {$snapshotPath}");
            }
            $sha256 = hash_file('sha256', $snapshotPath);
            $first = CarbonImmutable::parse((string) $rows[0]['time'], 'UTC');
            $last = CarbonImmutable::parse((string) $rows[array_key_last($rows)]['time'], 'UTC');
            $sourceManifest = is_file($sourcePath.'.manifest.json')
                ? (array) json_decode(File::get($sourcePath.'.manifest.json'), true)
                : [];
            $manifest = [
                'protocol' => 'lab_generation_regime_snapshot_v1',
                'source_protocol' => 'lab_generation_dataset_snapshot_v1',
                'source_path' => $sourcePath,
                'source_sha256' => data_get($sourceManifest, 'sha256'),
                'symbol' => $symbol,
                'timeframe' => 'H1',
                'row_count' => count($rows),
                'first_candle_at' => $first->toIso8601String(),
                'last_closed_candle_at' => $last->toIso8601String(),
                'closed_candle_cutoff' => $closedCutoff->toIso8601String(),
                'sha256' => $sha256,
                'promotion_evidence' => false,
                'rule' => 'M15 may use only the H1 regime known after the H1 candle closes; H1 is never an M15 genetic parent.',
                'generated_at' => now()->utc()->toIso8601String(),
            ];
            File::put($snapshotManifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
            data_set($context, 'canonical_dataset_snapshots.regime', [
                'protocol' => 'lab_generation_regime_snapshot_v1',
                'generation_id' => $generation->id,
                'generation' => $generation->generation,
                'symbol' => $symbol,
                'timeframe' => 'H1',
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
                'protocol' => 'lab_generation_regime_snapshot_v1',
            ];
        } finally {
            File::delete($temporaryPath);
        }
    }

    /**
     * Freeze the long historical training archive separately from the
     * canonical Twelve rolling/paper stream. Twelve's current plan may not
     * expose the 2005 baseline, while Dukascopy remains an explicit research
     * archive. This file is never written into canonical candles and its
     * manifest is permanently marked as non-promotion evidence.
     *
     * @return array{path: string, manifest: array<string, mixed>, sha256: string, protocol: string}
     */
    public function ensureFoundationDataset(string $symbol, string $timeframe = 'H1'): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        if ($timeframe === 'M15') {
            return $this->ensureM15FoundationDataset($symbol);
        }
        if ($timeframe !== 'H1') {
            throw new RuntimeException('Foundation archive hozircha faqat H1 uchun qo\'llab-quvvatlanadi.');
        }

        $directory = storage_path('app/lab-datasets/foundation');
        File::ensureDirectoryExists($directory);
        $path = $directory."/{$symbol}_{$timeframe}_2005-2025.csv";
        $manifestPath = $path.'.manifest.json';
        if ($existing = $this->validFoundationSnapshot($path, $manifestPath)) {
            return $existing;
        }

        $lock = fopen($path.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException("Foundation archive lock ochilmadi: {$symbol} {$timeframe}.");
        }
        $lockWaitSeconds = max(1, (int) config('services.lab_selection.foundation_export_lock_wait_seconds', 30));
        $deadline = microtime(true) + $lockWaitSeconds;
        $locked = false;
        while (microtime(true) < $deadline) {
            if (flock($lock, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(250000);
        }
        if (! $locked) {
            fclose($lock);
            throw new RuntimeException("Foundation archive lock olinmadi: {$symbol} {$timeframe}.");
        }

        // Another scheduler/worker may have completed the archive while this
        // caller was waiting for the lock. Re-check the immutable target
        // before opening a second network export.
        if ($existing = $this->validFoundationSnapshot($path, $manifestPath)) {
            flock($lock, LOCK_UN);
            fclose($lock);

            return $existing;
        }

        $from = CarbonImmutable::create(2005, 1, 2, 0, 0, 0, 'UTC');
        $to = CarbonImmutable::create(2026, 1, 1, 0, 0, 0, 'UTC');

        // A previously published foundation file may have a valid immutable
        // hash but fail the newly enforced OHLC geometry gate. Repair that
        // exact archive in place before considering a network refetch; the
        // source hash remains auditable and the repair is deterministic.
        $existingManifest = json_decode((string) @file_get_contents($manifestPath), true);
        $existingHash = is_array($existingManifest) ? (string) data_get($existingManifest, 'sha256', '') : '';
        $existingActualHash = is_file($path) ? hash_file('sha256', $path) : false;
        if (is_array($existingManifest)
            && $existingHash !== ''
            && is_string($existingActualHash)
            && hash_equals($existingHash, $existingActualHash)
            && (int) data_get($existingManifest, 'row_count', 0) >= 202) {
            try {
                return $this->materializeFoundationFromArchive(
                    $path,
                    $existingManifest,
                    $path,
                    $manifestPath,
                    $from,
                    $to,
                );
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        // A previous immutable generation snapshot is a valid historical
        // source for the same foundation lane. Reuse it locally when it
        // already covers the strict baseline; this avoids a 21-year network
        // refetch and preserves the source hash in the new manifest. The
        // source is copied/filter-published, never modified in place.
        if ($archive = $this->findReusableFoundationArchive($symbol, $timeframe, $from, $to)) {
            try {
                return $this->materializeFoundationFromArchive(
                    $archive['path'],
                    $archive['manifest'],
                    $path,
                    $manifestPath,
                    $from,
                    $to,
                );
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        $temporaryPath = tempnam($directory, ".{$symbol}_{$timeframe}_foundation_");
        if ($temporaryPath === false) {
            flock($lock, LOCK_UN);
            fclose($lock);
            throw new RuntimeException("Foundation archive temporary fayli yaratilmadi: {$symbol} {$timeframe}.");
        }

        $written = 0;
        $first = null;
        $last = null;
        $lastTime = null;
        $monthCounts = [];
        $normalizedOhlcRows = 0;
        $originalTickFallback = config('services.dukascopy.tick_fallback_enabled');
        config()->set(
            'services.dukascopy.tick_fallback_enabled',
            (bool) config('services.dukascopy.foundation_tick_fallback_enabled', false),
        );

        try {
            $handle = fopen($temporaryPath, 'wb');
            if ($handle === false) {
                throw new RuntimeException("Foundation archive temporary fayli ochilmadi: {$temporaryPath}");
            }
            fputcsv($handle, ['time', 'open', 'high', 'low', 'close', 'volume']);
            try {
                for ($cursor = $from; $cursor->lessThan($to); $cursor = $chunkTo) {
                    $chunkTo = $cursor->startOfMonth()->addMonth();
                    if ($chunkTo->greaterThan($to)) {
                        $chunkTo = $to;
                    }
                    $rows = $this->foundationProvider->fetchCandles(
                        symbol: $symbol,
                        providerSymbol: $symbol,
                        timeframe: $timeframe,
                        limit: 5000,
                        from: $cursor,
                        to: $chunkTo,
                    );
                    $monthKey = $cursor->format('Y-m');
                    $monthCounts[$monthKey] = 0;
                    foreach ($rows as $row) {
                        $time = CarbonImmutable::parse((string) ($row['time'] ?? ''), 'UTC');
                        if ($time->lessThan($from) || $time->greaterThanOrEqualTo($to)) {
                            continue;
                        }
                        if ($lastTime !== null && $time->lessThanOrEqualTo($lastTime)) {
                            continue;
                        }
                        $values = [
                            (string) $time->format('Y-m-d H:i:s'),
                            (float) ($row['open'] ?? 0),
                            (float) ($row['high'] ?? 0),
                            (float) ($row['low'] ?? 0),
                            (float) ($row['close'] ?? 0),
                            (float) ($row['volume'] ?? 0),
                        ];
                        if (! is_finite($values[1]) || ! is_finite($values[2])
                            || ! is_finite($values[3]) || ! is_finite($values[4])) {
                            continue;
                        }
                        [$values, $wasNormalized] = $this->normalizeFoundationOhlcValues($values, $symbol);
                        if ($wasNormalized) {
                            $normalizedOhlcRows++;
                        }
                        fputcsv($handle, $values);
                        $lastTime = $time;
                        $first ??= $time;
                        $last = $time;
                        $written++;
                        $monthCounts[$monthKey]++;
                    }
                    if ($monthCounts[$monthKey] < 100) {
                        throw new RuntimeException("Foundation archive oyida candle yo\'q: {$symbol} {$monthKey}.");
                    }
                }
            } finally {
                fclose($handle);
            }

            // The first tradable XAU candle may appear on Sunday at 23:00 UTC
            // after the requested 2005-01-02 midnight boundary. Treat the
            // first 24 hours as the market-open tolerance, rather than
            // rejecting an otherwise complete foundation archive.
            if ($written < 202 || $first === null || $first->greaterThan($from->addDay())) {
                throw new RuntimeException("Foundation archive baseline yetarli emas: {$symbol} rows={$written}, first=".($first?->toIso8601String() ?? 'none'));
            }
            $continuity = $this->quality->inspectCsvContinuity($temporaryPath, $symbol, $timeframe);
            if ($continuity['status'] !== 'ready') {
                throw $this->foundationContinuityException($symbol, $timeframe, $continuity);
            }
            $gapQuality = [
                'protocol' => 'foundation_gap_control_v1',
                'status' => 'passed',
                'source_missing_rows' => 0,
                'repaired_rows' => 0,
                'unresolved_rows' => 0,
                'repair_intervals' => [],
                'promotion_evidence' => false,
            ];
            if (! copy($temporaryPath, $path)) {
                throw new RuntimeException("Foundation archive publish qilinmadi: {$path}");
            }
            $sha256 = hash_file('sha256', $path);
            $manifest = [
                'protocol' => 'foundation_training_archive_v1',
                'source_provider' => 'dukascopy',
                'source_role' => 'foundation_training_only',
                'canonical_rolling_provider' => (string) config('services.market_data.canonical_provider', 'twelve'),
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'foundation_start' => $from->toIso8601String(),
                'foundation_end' => $to->subSecond()->toIso8601String(),
                'row_count' => $written,
                'first_candle_at' => $first->toIso8601String(),
                'last_candle_at' => $last?->toIso8601String(),
                'month_counts' => $monthCounts,
                'ohlc_quality' => [
                    'protocol' => 'foundation_ohlc_geometry_v1',
                    'normalized_rows' => $normalizedOhlcRows,
                    'final_invalid_rows' => 0,
                    'promotion_evidence' => false,
                ],
                'continuity' => $continuity,
                'gap_quality' => $gapQuality,
                'sha256' => $sha256,
                'promotion_evidence' => false,
                'rule' => 'Dukascopy supplies only the pre-2026 foundation score; Twelve remains the canonical rolling, forward, paper and holdout stream.',
                'generated_at' => now()->utc()->toIso8601String(),
            ];
            File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

            return [
                'path' => $path,
                'manifest' => $manifest,
                'sha256' => $sha256,
                'protocol' => 'foundation_training_archive_v1',
            ];
        } finally {
            config()->set('services.dukascopy.tick_fallback_enabled', $originalTickFallback);
            File::delete($temporaryPath);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Build the M15 foundation from preserved canonical M15 history before
     * the independent 2026 rolling stream. M15 never borrows H1 prices as a
     * foundation; H1 is supplied separately as a closed regime source.
     *
     * @return array{path: string, manifest: array<string, mixed>, sha256: string, protocol: string}
     */
    private function ensureM15FoundationDataset(string $symbol): array
    {
        $directory = storage_path('app/lab-datasets/foundation');
        File::ensureDirectoryExists($directory);
        $path = $directory."/{$symbol}_M15_2025-foundation.csv";
        $manifestPath = $path.'.manifest.json';
        if ($existing = $this->validFoundationSnapshot($path, $manifestPath)) {
            return $existing;
        }

        $lock = fopen($path.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException("M15 foundation lock ochilmadi: {$symbol}.");
        }
        $lockWaitSeconds = max(1, (int) config('services.lab_selection.foundation_export_lock_wait_seconds', 30));
        $deadline = microtime(true) + $lockWaitSeconds;
        $locked = false;
        while (microtime(true) < $deadline) {
            if (flock($lock, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(250000);
        }
        if (! $locked) {
            fclose($lock);
            throw new RuntimeException("M15 foundation lock olinmadi: {$symbol}.");
        }

        try {
            if ($existing = $this->validFoundationSnapshot($path, $manifestPath)) {
                return $existing;
            }

            $symbolId = Symbol::query()->where('code', $symbol)->value('id');
            if (! $symbolId) {
                throw new RuntimeException("{$symbol} symbol topilmadi.");
            }
            $from = CarbonImmutable::parse(
                (string) config('services.lab_selection.m15_foundation_start', '2025-11-01 00:00:00'),
                'UTC',
            );
            $to = CarbonImmutable::parse(
                (string) config('services.lab_selection.m15_foundation_end', '2025-12-31 23:59:59'),
                'UTC',
            )->addSecond();
            $minimumRows = max(1, (int) config('services.lab_selection.m15_foundation_minimum_rows', 2000));
            $candles = Candle::query()
                ->where('symbol_id', $symbolId)
                ->where('timeframe', 'M15')
                ->where('time', '>=', $from)
                ->where('time', '<', $to)
                ->orderBy('time')
                ->orderBy('id')
                ->get();
            if ($candles->count() < $minimumRows) {
                throw new RuntimeException("M15 foundation baseline yetarli emas: {$symbol} rows={$candles->count()}, minimum={$minimumRows}.");
            }

            $volumeMap = $this->volumes->forDataset($symbol, 'M15');
            $temporaryPath = tempnam($directory, ".{$symbol}_M15_foundation_");
            if ($temporaryPath === false) {
                throw new RuntimeException("M15 foundation temporary fayli yaratilmadi: {$symbol}.");
            }

            $written = 0;
            $first = null;
            $last = null;
            try {
                $handle = fopen($temporaryPath, 'wb');
                if ($handle === false) {
                    throw new RuntimeException("M15 foundation temporary fayli ochilmadi: {$symbol}.");
                }
                fputcsv($handle, ['time', 'open', 'high', 'low', 'close', 'volume', 'volume_available']);
                foreach ($candles as $candle) {
                    $time = $candle->time->copy()->utc();
                    $volume = $volumeMap[$time->format('Y-m-d H:i:s')] ?? ['volume' => 0.0, 'available' => false];
                    $volumeAvailable = (bool) data_get($volume, 'available', false);
                    fputcsv($handle, [
                        $time->format('Y-m-d H:i:s'),
                        (float) $candle->open,
                        (float) $candle->high,
                        (float) $candle->low,
                        (float) $candle->close,
                        (float) data_get($volume, 'volume', 0.0),
                        $volumeAvailable ? 1 : 0,
                    ]);
                    $first ??= $time;
                    $last = $time;
                    $written++;
                }
                fclose($handle);

                $continuity = $this->quality->inspectCsvContinuity($temporaryPath, $symbol, 'M15');
                if ($continuity['status'] !== 'ready') {
                    throw $this->foundationContinuityException($symbol, 'M15', $continuity);
                }
                if (! copy($temporaryPath, $path)) {
                    throw new RuntimeException("M15 foundation archive publish qilinmadi: {$path}");
                }
                $sha256 = hash_file('sha256', $path);
                $manifest = [
                    'protocol' => 'foundation_training_archive_v1',
                    'source_provider' => 'database_canonical_price_history',
                    'source_role' => 'm15_foundation_training_only',
                    'canonical_rolling_provider' => (string) config('services.market_data.canonical_provider', 'twelve'),
                    'symbol' => $symbol,
                    'timeframe' => 'M15',
                    'first_candle_at' => $first?->toIso8601String(),
                    'last_candle_at' => $last?->toIso8601String(),
                    'foundation_start' => $first?->toIso8601String(),
                    'foundation_end' => $last?->toIso8601String(),
                    'row_count' => $written,
                    'minimum_rows' => $minimumRows,
                    'continuity' => $continuity,
                    'gap_quality' => [
                        'protocol' => 'foundation_gap_control_v1',
                        'status' => 'passed',
                        'source_missing_rows' => 0,
                        'repaired_rows' => 0,
                        'unresolved_rows' => 0,
                        'repair_intervals' => [],
                        'promotion_evidence' => false,
                    ],
                    'volume_quality' => $this->volumes->inspect($symbol, 'M15', $from, $to),
                    'sha256' => $sha256,
                    'promotion_evidence' => false,
                    'rule' => 'M15 uses its own preserved pre-2026 price foundation; H1 is a closed regime context only.',
                    'generated_at' => now()->utc()->toIso8601String(),
                ];
                File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

                return [
                    'path' => $path,
                    'manifest' => $manifest,
                    'sha256' => $sha256,
                    'protocol' => 'foundation_training_archive_v1',
                ];
            } finally {
                File::delete($temporaryPath);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{path: string, manifest: array<string, mixed>, sha256: string, protocol: string} */
    public function ensureGenerationFoundationSnapshot(LabGeneration $generation): array
    {
        $symbol = strtoupper((string) ($generation->laboratory?->symbol ?? ''));
        $timeframe = strtoupper((string) ($generation->laboratory?->timeframe ?? 'H1'));
        if ($symbol === '') {
            throw new RuntimeException('Generation laboratory symbol topilmadi.');
        }

        $context = (array) $generation->trigger_context;
        $existing = (array) data_get($context, 'canonical_dataset_snapshots.foundation', []);
        $existingPath = (string) data_get($existing, 'path', '');
        $existingSha = (string) data_get($existing, 'sha256', '');
        if (is_file($existingPath) && $existingSha !== '') {
            $existingManifestPath = (string) data_get($existing, 'manifest_path', $existingPath.'.manifest.json');
            $validated = $this->validFoundationSnapshot($existingPath, $existingManifestPath);
            if ($validated === null) {
                throw new RuntimeException("Foundation dataset snapshot continuity passport invalid; evidence is frozen and replay is blocked: {$existingPath}");
            }
            $validatedSha = (string) ($validated['sha256'] ?? '');
            $sourceArchiveSha = (string) data_get($validated, 'manifest.source_archive_sha256', '');
            $hashChangedByContinuityRepair = $validatedSha !== ''
                && ! hash_equals($existingSha, $validatedSha);
            if ($hashChangedByContinuityRepair && ! hash_equals($existingSha, $sourceArchiveSha)) {
                throw new RuntimeException("Foundation dataset snapshot hash mismatch; evidence is frozen and replay is blocked: {$existingPath}");
            }

            // The archive can be revalidated after a continuity-passport
            // repair without changing its immutable CSV hash. Refresh the
            // generation context with the canonical manifest so admission
            // reads the same continuity evidence that validFoundationSnapshot
            // just verified, rather than a stale pre-passport projection.
            $foundationContext = (array) data_get($context, 'canonical_dataset_snapshots.foundation', []);
            $foundationContext['protocol'] = $validated['protocol'] ?? 'foundation_training_archive_v1';
            $foundationContext['manifest'] = $validated['manifest'] ?? [];
            $foundationContext['manifest_path'] = $existingManifestPath;
            $foundationContext['sha256'] = $validatedSha !== '' ? $validatedSha : $existingSha;
            if ($hashChangedByContinuityRepair) {
                $foundationContext['repair'] = [
                    'protocol' => 'foundation_continuity_repair_v1',
                    'source_archive_sha256' => $existingSha,
                    'repaired_sha256' => $validatedSha,
                    'promotion_evidence' => false,
                    'reconciled_at' => now()->utc()->toIso8601String(),
                ];
            }
            data_set($context, 'canonical_dataset_snapshots.foundation', $foundationContext);
            $generation->update(['trigger_context' => $context]);

            return $validated;
        }

        $snapshot = $this->ensureFoundationDataset($symbol, $timeframe);
        data_set($context, 'canonical_dataset_snapshots.foundation', [
            'protocol' => $snapshot['protocol'],
            'generation_id' => $generation->id,
            'generation' => $generation->generation,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'path' => $snapshot['path'],
            'manifest' => $snapshot['manifest'],
            'sha256' => $snapshot['sha256'],
            'frozen_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ]);
        $generation->update(['trigger_context' => $context]);

        return $snapshot;
    }

    /** @return array{path: string, manifest: array<string, mixed>, sha256: string, protocol: string}|null */
    private function validFoundationSnapshot(string $path, string $manifestPath): ?array
    {
        if (! is_file($path) || ! is_file($manifestPath)) {
            return null;
        }

        $manifest = json_decode((string) File::get($manifestPath), true);
        $timeframe = strtoupper((string) data_get($manifest, 'timeframe', 'H1'));
        $minimumRows = $timeframe === 'M15'
            ? max(1, (int) config('services.lab_selection.m15_foundation_minimum_rows', 2000))
            : 202;
        if (! is_array($manifest)
            || data_get($manifest, 'protocol') !== 'foundation_training_archive_v1'
            || (int) data_get($manifest, 'row_count', 0) < $minimumRows
            || (string) data_get($manifest, 'sha256', '') === '') {
            return null;
        }

        $actualHash = hash_file('sha256', $path);
        if (! is_string($actualHash) || ! hash_equals((string) $manifest['sha256'], $actualHash)) {
            return null;
        }

        $quality = $this->foundationOhlcQuality($path);
        if (! $quality['valid'] || $quality['rows'] !== (int) data_get($manifest, 'row_count', 0)) {
            return null;
        }
        $continuity = data_get($manifest, 'continuity');
        if (! is_array($continuity)
            || data_get($continuity, 'protocol') !== HistoricalDataQualityService::FOUNDATION_CONTINUITY_PROTOCOL
            || data_get($continuity, 'status') !== 'ready'
            || (int) data_get($continuity, 'row_count', -1) !== (int) data_get($manifest, 'row_count', 0)
            || (int) data_get($continuity, 'unexpected_gap_count', 0) !== 0
            || (int) data_get($continuity, 'missing_open_candles', 0) !== 0
            || (int) data_get($continuity, 'invalid_rows', 0) !== 0) {
            return null;
        }

        $gapStatus = (string) data_get($manifest, 'gap_quality.status', '');
        $unresolvedGaps = (int) data_get($manifest, 'gap_quality.unresolved_rows', 0);
        if (! in_array($gapStatus, ['passed', 'repaired'], true) || $unresolvedGaps > 0) {
            return null;
        }

        return [
            'path' => $path,
            'manifest' => $manifest,
            'sha256' => (string) $manifest['sha256'],
            'protocol' => 'foundation_training_archive_v1',
        ];
    }

    /** @return array{path: string, manifest: array<string, mixed>}|null */
    private function findReusableFoundationArchive(
        string $symbol,
        string $timeframe,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): ?array {
        $directory = storage_path('app/lab-datasets/generations');
        $manifestPaths = glob($directory.'/*'.$symbol.'_'.$timeframe.'.csv.manifest.json') ?: [];
        usort($manifestPaths, static function (string $left, string $right): int {
            return strcmp($right, $left);
        });

        foreach ($manifestPaths as $manifestPath) {
            $manifest = json_decode((string) @file_get_contents($manifestPath), true);
            if (! is_array($manifest)
                || (string) data_get($manifest, 'status', '') !== 'ready'
                || (int) data_get($manifest, 'row_count', 0) < 202
                || ! data_get($manifest, 'first_candle_at')
                || ! data_get($manifest, 'last_candle_at')) {
                continue;
            }

            $archivePath = substr($manifestPath, 0, -strlen('.manifest.json'));
            if (! is_file($archivePath)) {
                continue;
            }

            try {
                $first = CarbonImmutable::parse((string) data_get($manifest, 'first_candle_at'), 'UTC');
                $last = CarbonImmutable::parse((string) data_get($manifest, 'last_candle_at'), 'UTC');
            } catch (\Throwable) {
                continue;
            }

            if ($first->greaterThan($from->addHours(22)) || $last->lessThan($to->subDay())) {
                continue;
            }

            $expectedHash = (string) data_get($manifest, 'sha256', '');
            $actualHash = hash_file('sha256', $archivePath);
            if ($expectedHash === '' || ! is_string($actualHash) || ! hash_equals($expectedHash, $actualHash)) {
                continue;
            }

            return ['path' => $archivePath, 'manifest' => $manifest];
        }

        return null;
    }

    /** @return array{path: string, manifest: array<string, mixed>, sha256: string, protocol: string} */
    private function materializeFoundationFromArchive(
        string $archivePath,
        array $archiveManifest,
        string $path,
        string $manifestPath,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $temporaryPath = tempnam(dirname($path), '.foundation_archive_');
        if ($temporaryPath === false) {
            throw new RuntimeException('Foundation archive recovery temporary fayli yaratilmadi.');
        }

        try {
            $repaired = $this->foundationRowsWithGapRepair(
                $archivePath,
                $archiveManifest,
                $from,
                $to,
            );
            $this->writeFoundationRowsCsv($temporaryPath, $repaired['rows']);
            $summary = $this->summarizeFoundationRows($repaired['rows']);
            $written = $summary['written'];
            $first = $summary['first'];
            $last = $summary['last'];
            $monthCounts = $summary['month_counts'];
            $normalizedOhlcRows = (int) ($repaired['normalized_rows'] ?? 0);
            $gapQuality = (array) ($repaired['gap_quality'] ?? []);

            // Match the network-export validator above: a weekend/market-open
            // delay of up to one day is valid for the 2005-01-02 boundary.
            $minimumFirst = $from->addDay();
            if ($written < 202 || $first === null || $first->greaterThan($minimumFirst)
                || $last === null || $last->lessThan($to->subDay())) {
                throw new RuntimeException('Foundation archive recovery baseline yetarli emas.');
            }
            $symbol = strtoupper((string) data_get($archiveManifest, 'symbol', ''));
            $timeframe = strtoupper((string) data_get($archiveManifest, 'timeframe', 'H1'));
            $continuity = $this->quality->inspectCsvContinuity($temporaryPath, $symbol, $timeframe);
            if ($continuity['status'] !== 'ready') {
                throw $this->foundationContinuityException($symbol, $timeframe, $continuity);
            }
            if (! copy($temporaryPath, $path)) {
                throw new RuntimeException("Foundation archive recovery publish qilinmadi: {$path}");
            }
            $sha256 = hash_file('sha256', $path);
            $manifest = [
                'protocol' => 'foundation_training_archive_v1',
                'source_provider' => 'historical_generation_snapshot',
                'source_role' => 'foundation_training_only',
                'source_archive_path' => $archivePath,
                'source_archive_sha256' => (string) data_get($archiveManifest, 'sha256', ''),
                'canonical_rolling_provider' => (string) config('services.market_data.canonical_provider', 'twelve'),
                'symbol' => (string) data_get($archiveManifest, 'symbol', ''),
                'timeframe' => (string) data_get($archiveManifest, 'timeframe', 'H1'),
                'foundation_start' => $from->toIso8601String(),
                'foundation_end' => $to->subSecond()->toIso8601String(),
                'row_count' => $written,
                'first_candle_at' => $first->toIso8601String(),
                'last_candle_at' => $last->toIso8601String(),
                'month_counts' => $monthCounts,
                'ohlc_quality' => [
                    'protocol' => 'foundation_ohlc_geometry_v1',
                    'source_invalid_rows_repaired' => $normalizedOhlcRows,
                    'normalized_rows' => $normalizedOhlcRows,
                    'final_invalid_rows' => 0,
                    'promotion_evidence' => false,
                ],
                'continuity' => $continuity,
                'gap_quality' => $gapQuality,
                'sha256' => $sha256,
                'promotion_evidence' => false,
                'reuse_protocol' => 'immutable_generation_archive_foundation_reuse_v1',
                'rule' => 'An immutable prior generation archive may seed only the pre-2026 foundation score; rolling, forward, paper and holdout streams remain generation-local.',
                'generated_at' => now()->utc()->toIso8601String(),
            ];
            File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

            return [
                'path' => $path,
                'manifest' => $manifest,
                'sha256' => $sha256,
                'protocol' => 'foundation_training_archive_v1',
            ];
        } finally {
            File::delete($temporaryPath);
        }
    }

    /**
     * Read a foundation archive into a keyed H1 stream, identify unexpected
     * holes, and backfill only those exact candles. The archive stays outside
     * canonical candles and its repair metadata is persisted in the manifest.
     *
     * @return array{rows: array<int, array<int, mixed>>, gap_quality: array<string, mixed>, normalized_rows: int}
     */
    private function foundationRowsWithGapRepair(
        string $archivePath,
        array $archiveManifest,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $symbol = strtoupper((string) data_get($archiveManifest, 'symbol', ''));
        if ($symbol === '') {
            throw new RuntimeException('Foundation archive gap repair symboli topilmadi.');
        }

        $input = fopen($archivePath, 'rb');
        if ($input === false) {
            throw new RuntimeException("Foundation archive gap repair fayli ochilmadi: {$archivePath}");
        }

        $rowsByTime = [];
        $normalizedRows = 0;
        try {
            $headers = fgetcsv($input);
            if (! is_array($headers)) {
                throw new RuntimeException('Foundation archive gap repair header topilmadi.');
            }
            $headers = array_map(static fn ($header): string => strtolower(trim((string) $header)), $headers);
            $timeIndex = array_search('time', $headers, true);
            $openIndex = array_search('open', $headers, true);
            $highIndex = array_search('high', $headers, true);
            $lowIndex = array_search('low', $headers, true);
            $closeIndex = array_search('close', $headers, true);
            $volumeIndex = array_search('volume', $headers, true);
            if ($timeIndex === false || $openIndex === false || $highIndex === false
                || $lowIndex === false || $closeIndex === false) {
                throw new RuntimeException('Foundation archive gap repair OHLC header toliq emas.');
            }

            while (($values = fgetcsv($input)) !== false) {
                $rawTime = $values[$timeIndex] ?? null;
                if (! is_string($rawTime) || trim($rawTime) === '') {
                    continue;
                }
                try {
                    $time = CarbonImmutable::parse($rawTime, 'UTC');
                } catch (\Throwable) {
                    continue;
                }
                if ($time->lessThan($from) || $time->greaterThanOrEqualTo($to)) {
                    continue;
                }

                $key = $time->format('Y-m-d H:i:s');
                if (isset($rowsByTime[$key])) {
                    continue;
                }

                $row = [
                    $key,
                    (float) ($values[$openIndex] ?? 0),
                    (float) ($values[$highIndex] ?? 0),
                    (float) ($values[$lowIndex] ?? 0),
                    (float) ($values[$closeIndex] ?? 0),
                    $volumeIndex === false ? 0.0 : (float) ($values[$volumeIndex] ?? 0),
                ];
                if (! is_finite($row[1]) || ! is_finite($row[2])
                    || ! is_finite($row[3]) || ! is_finite($row[4])) {
                    continue;
                }
                [$row, $wasNormalized] = $this->normalizeFoundationOhlcValues($row, $symbol);
                if ($wasNormalized) {
                    $normalizedRows++;
                }
                $rowsByTime[$key] = $row;
            }
        } finally {
            fclose($input);
        }

        if (count($rowsByTime) < 202) {
            throw new RuntimeException("Foundation archive gap repair baseline yetarli emas: {$symbol} rows=".count($rowsByTime));
        }

        ksort($rowsByTime, SORT_STRING);
        $missing = [];
        $previous = null;
        foreach (array_keys($rowsByTime) as $key) {
            $current = CarbonImmutable::parse($key, 'UTC');
            if ($previous !== null
                && ! $this->quality->isScheduledClosure($previous, $current, $symbol)) {
                for ($cursor = $previous->addHour(); $cursor->lessThan($current); $cursor = $cursor->addHour()) {
                    if ($this->quality->isExpectedMarketOpen($cursor, $symbol)) {
                        $missing[$cursor->format('Y-m-d H:i:s')] = $cursor;
                    }
                }
            }
            $previous = $current;
        }

        $sourceMissingRows = count($missing);
        $repairIntervals = [];
        if ($sourceMissingRows > 0) {
            $intervals = [];
            $intervalStart = null;
            $intervalEnd = null;
            $intervalKeys = [];
            foreach ($missing as $key => $time) {
                if ($intervalStart === null) {
                    $intervalStart = $time;
                    $intervalEnd = $time;
                    $intervalKeys = [$key];
                    continue;
                }

                if ($intervalEnd->addHour()->equalTo($time)) {
                    $intervalEnd = $time;
                    $intervalKeys[] = $key;
                    continue;
                }

                $intervals[] = [
                    'from' => $intervalStart,
                    'to' => $intervalEnd->addHour(),
                    'keys' => $intervalKeys,
                ];
                $intervalStart = $time;
                $intervalEnd = $time;
                $intervalKeys = [$key];
            }
            if ($intervalStart !== null && $intervalEnd !== null) {
                $intervals[] = [
                    'from' => $intervalStart,
                    'to' => $intervalEnd->addHour(),
                    'keys' => $intervalKeys,
                ];
            }

            $originalTransport = config('services.dukascopy.transport');
            $originalTickFallback = config('services.dukascopy.tick_fallback_enabled');
            try {
                foreach ($intervals as $interval) {
                    $providersUsed = [];
                    $errors = [];
                    $remaining = $interval['keys'];
                    $providerAttempts = [
                        ['name' => 'legacy', 'transport' => 'legacy', 'tick_fallback' => false],
                        ['name' => 'jetta_tick', 'transport' => 'jetta', 'tick_fallback' => true],
                    ];

                    foreach ($providerAttempts as $attempt) {
                        if ($remaining === []) {
                            break;
                        }
                        config()->set('services.dukascopy.transport', $attempt['transport']);
                        config()->set('services.dukascopy.tick_fallback_enabled', $attempt['tick_fallback']);
                        try {
                            $providerRows = $this->foundationProvider->fetchCandles(
                                symbol: $symbol,
                                providerSymbol: $symbol,
                                timeframe: 'H1',
                                limit: max(1000, count($remaining)),
                                from: $interval['from'],
                                to: $interval['to'],
                            );
                            $added = 0;
                            foreach ($providerRows as $providerRow) {
                                $providerTime = CarbonImmutable::parse((string) ($providerRow['time'] ?? ''), 'UTC');
                                $key = $providerTime->format('Y-m-d H:i:s');
                                if (! isset($missing[$key]) || isset($rowsByTime[$key])) {
                                    continue;
                                }
                                $row = [
                                    $key,
                                    (float) ($providerRow['open'] ?? 0),
                                    (float) ($providerRow['high'] ?? 0),
                                    (float) ($providerRow['low'] ?? 0),
                                    (float) ($providerRow['close'] ?? 0),
                                    (float) ($providerRow['volume'] ?? 0),
                                ];
                                if (! is_finite($row[1]) || ! is_finite($row[2])
                                    || ! is_finite($row[3]) || ! is_finite($row[4])) {
                                    continue;
                                }
                                [$row, $wasNormalized] = $this->normalizeFoundationOhlcValues($row, $symbol);
                                if ($wasNormalized) {
                                    $normalizedRows++;
                                }
                                $rowsByTime[$key] = $row;
                                $added++;
                            }
                            if ($added > 0) {
                                $providersUsed[] = $attempt['name'];
                            }
                            $remaining = array_values(array_filter(
                                $remaining,
                                static fn (string $key): bool => ! isset($rowsByTime[$key]),
                            ));
                        } catch (\Throwable $exception) {
                            $errors[] = $attempt['name'].': '.$exception->getMessage();
                        }
                    }

                    if ($remaining !== []) {
                        $details = $errors !== [] ? ' attempts='.implode(' | ', $errors) : '';
                        throw new RuntimeException(sprintf(
                            'Foundation archive gap repair unresolved: %s %s-%s missing=%d%s',
                            $symbol,
                            $interval['from']->toDateTimeString(),
                            $interval['to']->toDateTimeString(),
                            count($remaining),
                            $details,
                        ));
                    }

                    $repairIntervals[] = [
                        'from' => $interval['from']->toIso8601String(),
                        'to' => $interval['to']->toIso8601String(),
                        'rows' => count($interval['keys']),
                        'providers' => array_values(array_unique($providersUsed)),
                    ];
                }
            } finally {
                config()->set('services.dukascopy.transport', $originalTransport);
                config()->set('services.dukascopy.tick_fallback_enabled', $originalTickFallback);
            }
        }

        ksort($rowsByTime, SORT_STRING);

        return [
            'rows' => array_values($rowsByTime),
            'gap_quality' => [
                'protocol' => 'foundation_gap_control_v1',
                'status' => $sourceMissingRows > 0 ? 'repaired' : 'passed',
                'source_missing_rows' => $sourceMissingRows,
                'repaired_rows' => array_sum(array_map(
                    static fn (array $interval): int => (int) ($interval['rows'] ?? 0),
                    $repairIntervals,
                )),
                'unresolved_rows' => 0,
                'repair_intervals' => $repairIntervals,
                'promotion_evidence' => false,
            ],
            'normalized_rows' => $normalizedRows,
        ];
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function writeFoundationRowsCsv(string $path, array $rows): void
    {
        $output = fopen($path, 'wb');
        if ($output === false) {
            throw new RuntimeException("Foundation archive CSV yozilmadi: {$path}");
        }
        try {
            fputcsv($output, ['time', 'open', 'high', 'low', 'close', 'volume']);
            foreach ($rows as $row) {
                fputcsv($output, $row);
            }
        } finally {
            fclose($output);
        }
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function summarizeFoundationRows(array $rows): array
    {
        $first = null;
        $last = null;
        $monthCounts = [];
        foreach ($rows as $row) {
            $time = CarbonImmutable::parse((string) ($row[0] ?? ''), 'UTC');
            $first ??= $time;
            $last = $time;
            $monthKey = $time->format('Y-m');
            $monthCounts[$monthKey] = ($monthCounts[$monthKey] ?? 0) + 1;
        }

        return [
            'written' => count($rows),
            'first' => $first,
            'last' => $last,
            'month_counts' => $monthCounts,
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

    /**
     * @return array{0: array<int, mixed>, 1: bool}
     */
    private function normalizeFoundationOhlcValues(array $row, string $symbol): array
    {
        $open = (float) ($row[1] ?? 0);
        $high = (float) ($row[2] ?? 0);
        $low = (float) ($row[3] ?? 0);
        $close = (float) ($row[4] ?? 0);
        if (! is_finite($open) || ! is_finite($high) || ! is_finite($low) || ! is_finite($close)
            || $open <= 0 || $high <= 0 || $low <= 0 || $close <= 0) {
            throw new RuntimeException("Foundation archive non-positive yoki finite bo'lmagan OHLC qaytardi: {$symbol}");
        }

        $expectedHigh = max($open, $high, $low, $close);
        $expectedLow = min($open, $high, $low, $close);
        $point = str_starts_with(strtoupper($symbol), 'XAU') ? 0.01 : 0.00001;
        $maxAdjustment = max(abs($expectedHigh - $high), abs($low - $expectedLow));
        if ($maxAdjustment > ($point * 2.1)) {
            throw new RuntimeException("Foundation archive OHLC geometry tolerance oshdi: {$symbol} adjustment={$maxAdjustment}");
        }

        $changed = abs($expectedHigh - $high) > 1e-12 || abs($expectedLow - $low) > 1e-12;
        $row[2] = $expectedHigh;
        $row[3] = $expectedLow;

        return [$row, $changed];
    }

    /** @return array{valid: bool, rows: int, invalid_rows: int} */
    private function foundationOhlcQuality(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['valid' => false, 'rows' => 0, 'invalid_rows' => 0];
        }

        $rows = 0;
        $invalidRows = 0;
        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers)) {
                return ['valid' => false, 'rows' => 0, 'invalid_rows' => 0];
            }
            $headers = array_map(static fn ($header): string => strtolower(trim((string) $header)), $headers);
            $indices = [];
            foreach (['open', 'high', 'low', 'close'] as $field) {
                $index = array_search($field, $headers, true);
                if ($index === false) {
                    return ['valid' => false, 'rows' => 0, 'invalid_rows' => 0];
                }
                $indices[$field] = $index;
            }
            while (($values = fgetcsv($handle)) !== false) {
                $rows++;
                $open = (float) ($values[$indices['open']] ?? 0);
                $high = (float) ($values[$indices['high']] ?? 0);
                $low = (float) ($values[$indices['low']] ?? 0);
                $close = (float) ($values[$indices['close']] ?? 0);
                if (! is_finite($open) || ! is_finite($high) || ! is_finite($low) || ! is_finite($close)
                    || $open <= 0 || $high <= 0 || $low <= 0 || $close <= 0
                    || $high < max($open, $close) || $low > min($open, $close) || $high < $low) {
                    $invalidRows++;
                }
            }
        } finally {
            fclose($handle);
        }

        return ['valid' => $invalidRows === 0, 'rows' => $rows, 'invalid_rows' => $invalidRows];
    }

    private function foundationContinuityException(string $symbol, string $timeframe, array $continuity): RuntimeException
    {
        $examples = collect((array) data_get($continuity, 'gap_examples', []))
            ->map(static fn (array $gap): string => (string) ($gap['after'] ?? '').' -> '.(string) ($gap['before'] ?? ''))
            ->filter()
            ->take(3)
            ->implode('; ');
        $suffix = $examples !== '' ? ' examples='.$examples : '';

        return new RuntimeException(
            "Foundation dataset continuity gate failed: {$symbol} {$timeframe}; "
            .implode(',', (array) data_get($continuity, 'reasons', ['FOUNDATION_DATASET_CONTINUITY_BLOCKED']))
            .$suffix,
        );
    }
}
