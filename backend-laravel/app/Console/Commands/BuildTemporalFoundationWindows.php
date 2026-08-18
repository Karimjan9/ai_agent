<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Services\ExecutionContractService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class BuildTemporalFoundationWindows extends Command
{
    protected $signature = 'trading:build-temporal-foundation-windows
        {symbol=XAUUSD}
        {--timeframe=H1}
        {--source= : Existing immutable foundation CSV}
        {--manifest-path= : Output manifest path relative to storage/app}
        {--force : Replace only previously generated temporal-window files}';

    protected $description = 'Split an immutable foundation archive into three sealed chronological temporal-ablation windows';

    public function handle(ExecutionContractService $execution): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $source = (string) ($this->option('source') ?: storage_path("app/lab-datasets/foundation/{$symbol}_{$timeframe}_2005-2025.csv"));
        if (! is_file($source)) return $this->failCommand('Foundation source topilmadi: '.$source);

        $sourceManifestPath = $source.'.manifest.json';
        $sourceManifest = is_file($sourceManifestPath)
            ? (array) json_decode((string) file_get_contents($sourceManifestPath), true)
            : [];
        $sourceHash = (string) hash_file('sha256', $source);
        if ($sourceHash === '') return $this->failCommand('Foundation source hash hisoblanmadi.');

        $sourceProvider = strtolower(trim((string) (
            data_get($sourceManifest, 'provider')
            ?: data_get($sourceManifest, 'source_provider')
        )));
        $canonicalProvider = strtolower((string) config('services.market_data.canonical_provider', 'twelve'));
        if ($sourceProvider === ''
            || $sourceProvider === 'historical_generation_snapshot'
            || $sourceProvider === $canonicalProvider
            || filled(data_get($sourceManifest, 'source_archive_sha256'))
            || filled(data_get($sourceManifest, 'reuse_protocol'))) {
            return $this->failCommand(
                'Temporal evidence source must be a fresh independent provider export; '
                .'historical_generation_snapshot/reused or canonical rolling data qabul qilinmaydi.'
            );
        }
        if (filled(data_get($sourceManifest, 'sha256'))
            && ! hash_equals((string) data_get($sourceManifest, 'sha256'), $sourceHash)) {
            return $this->failCommand('Foundation source manifest hash mismatch.');
        }

        $sourceSymbol = strtoupper((string) data_get($sourceManifest, 'symbol', ''));
        $sourceTimeframe = strtoupper((string) data_get($sourceManifest, 'timeframe', ''));
        if (($sourceSymbol !== '' && $sourceSymbol !== $symbol)
            || ($sourceTimeframe !== '' && $sourceTimeframe !== $timeframe)) {
            return $this->failCommand('Foundation source symbol/timeframe manifest bilan mos emas.');
        }

        $rescueRanges = $this->priorGenerationRanges($symbol, $timeframe);
        if ($rescueRanges === []) {
            return $this->failCommand('Prior generation temporal range attestation topilmadi; evidence fail-closed qoldi.');
        }

        $handle = fopen($source, 'rb');
        if ($handle === false) return $this->failCommand('Foundation source ochilmadi.');
        $header = fgetcsv($handle);
        $rows = [];
        $previousTime = null;
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || count($row) < 5) continue;
            try {
                $time = CarbonImmutable::parse((string) ($row[0] ?? ''), 'UTC')->utc();
            } catch (\Throwable) {
                fclose($handle);
                return $this->failCommand('Foundation source’da yaroqsiz candle timestamp topildi.');
            }
            if ($previousTime !== null && $time->lessThanOrEqualTo($previousTime)) {
                fclose($handle);
                return $this->failCommand('Foundation source chronological yoki duplicate candle tekshiruvidan otmadi.');
            }
            $rows[] = ['row' => $row, 'time' => $time];
            $previousTime = $time;
        }
        fclose($handle);

        $gap = max(24, (int) config('services.rescue_circuit_breaker.window_minimum_candles', 24));
        $intervalMinutes = $timeframe === 'M15' ? 15 : 60;
        $earliestRescue = collect($rescueRanges)
            ->map(fn (array $range): CarbonImmutable => $range['first'])
            ->sort()
            ->first();
        if (! $earliestRescue instanceof CarbonImmutable) {
            return $this->failCommand('Prior generation temporal boundary yaroqsiz.');
        }
        // Use only the prefix before the first prior-generation candle. This
        // is stronger than comparing whole-file hashes: a different hash can
        // still contain the exact candles used by G37-G46.
        $safeCutoff = $earliestRescue->subMinutes($gap * $intervalMinutes);
        $sourceOverlapCount = collect($rows)
            ->filter(fn (array $item): bool => $this->timeInRanges($item['time'], $rescueRanges))
            ->count();
        $safeRows = collect($rows)
            ->filter(fn (array $item): bool => $item['time']->lessThan($safeCutoff))
            ->values()
            ->all();
        $windowMinimum = (int) config('services.rescue_circuit_breaker.window_minimum_candles', 24);
        if (count($safeRows) < 3 * $windowMinimum + 2 * $gap) {
            return $this->failCommand('Foundation source uchta window uchun yetarli emas.');
        }

        $directory = storage_path('app/lab-datasets/temporal-ablation');
        File::ensureDirectoryExists($directory);
        $independentFoundationPath = $directory."/{$symbol}_{$timeframe}_independent_foundation.csv";
        $independentFoundationManifestPath = $independentFoundationPath.'.manifest.json';
        $manifestPath = (string) ($this->option('manifest-path') ?: "temporal-ablation/{$symbol}_{$timeframe}_manifest.json");
        $resolvedManifestPath = str_starts_with(strtolower($manifestPath), strtolower(storage_path('app')))
            ? $manifestPath
            : storage_path('app/'.ltrim($manifestPath, '/\\'));
        if (! $this->option('force')
            && (is_file($independentFoundationPath) || is_file($resolvedManifestPath))) {
            return $this->failCommand('Independent temporal evidence mavjud; qayta yaratish uchun --force kerak.');
        }

        $temporaryFoundation = $independentFoundationPath.'.'.getmypid().'.tmp';
        $foundationWriter = fopen($temporaryFoundation, 'wb');
        if ($foundationWriter === false) return $this->failCommand('Independent foundation temporary file ochilmadi.');
        fputcsv($foundationWriter, $header);
        foreach ($safeRows as $item) fputcsv($foundationWriter, $item['row']);
        fclose($foundationWriter);
        if (! copy($temporaryFoundation, $independentFoundationPath)) {
            @unlink($temporaryFoundation);
            return $this->failCommand('Independent foundation publish bo\'lmadi.');
        }
        @unlink($temporaryFoundation);
        $independentFoundationHash = (string) hash_file('sha256', $independentFoundationPath);
        $safeFirst = $safeRows[0]['time'];
        $safeLast = $safeRows[count($safeRows) - 1]['time'];
        $overlapAttestation = [
            'protocol' => 'temporal_independent_source_attestation_v1',
            'provider_independent_from_canonical' => $sourceProvider !== $canonicalProvider,
            'source_rows_excluded_due_to_prior_generation_overlap' => $sourceOverlapCount,
            'accepted_rows_overlap_with_prior_generations' => 0,
            'accepted_first_candle_at' => $safeFirst->toIso8601String(),
            'accepted_last_candle_at' => $safeLast->toIso8601String(),
            'safe_cutoff' => $safeCutoff->toIso8601String(),
            'prior_generation_range_count' => count($rescueRanges),
            'promotion_evidence' => false,
        ];
        File::put($independentFoundationManifestPath, json_encode([
            'protocol' => 'temporal_independent_foundation_v1',
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'provider' => $sourceProvider,
            'source_role' => 'independent_temporal_ablation_only',
            'row_count' => count($safeRows),
            'first_candle_at' => $safeFirst->toIso8601String(),
            'last_candle_at' => $safeLast->toIso8601String(),
            'sha256' => $independentFoundationHash,
            'source_content_hash' => $sourceHash,
            'source_path' => $source,
            'attestation' => $overlapAttestation,
            'promotion_evidence' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $usable = count($safeRows) - (2 * $gap);
        $chunk = intdiv($usable, 3);
        $roles = ['development', 'validation', 'sealed_holdout'];
        $contract = $execution->for($symbol, $timeframe);
        $windows = [];

        foreach ($roles as $index => $role) {
            $start = ($index * $chunk) + ($index * $gap);
            $length = $index === 2 ? count($rows) - $start : $chunk;
            $slice = array_slice($safeRows, $start, $length);
            if (count($slice) < $windowMinimum) {
                return $this->failCommand("{$role}: window coverage yetarli emas.");
            }
            $path = $directory."/{$symbol}_{$timeframe}_{$role}.csv";
            if (is_file($path) && ! $this->option('force')) {
                return $this->failCommand("Window mavjud; overwrite uchun --force kerak: {$path}");
            }
            $temporary = $path.'.'.getmypid().'.tmp';
            $writer = fopen($temporary, 'wb');
            if ($writer === false) return $this->failCommand('Window temporary file ochilmadi.');
            fputcsv($writer, $header);
            foreach ($slice as $item) fputcsv($writer, $item['row']);
            fclose($writer);
            if (! copy($temporary, $path)) {
                @unlink($temporary);
                return $this->failCommand('Window publish bo‘lmadi: '.$path);
            }
            @unlink($temporary);
            $hash = (string) hash_file('sha256', $path);
            $first = $slice[0]['time']->toIso8601String();
            $last = $slice[count($slice) - 1]['time']->toIso8601String();
            $windows[] = [
                'window_id' => "{$symbol}_{$timeframe}_{$role}_v1",
                'role' => $role,
                'chronological_order' => $index + 1,
                'dataset_path' => $path,
                'data_hash' => $hash,
                'execution_hash' => $contract['execution_hash'],
                'first_candle_at' => $first,
                'last_candle_at' => $last,
                'candle_count' => count($slice),
                'independent_from_rescue' => true,
                'sealed' => true,
                'purge_candles' => $gap,
                'embargo_candles' => $gap,
                'overlap_ratio' => 0.0,
                'attestation' => 'derived_from_independent_source_non_overlap_v1',
                'source_content_hash' => $sourceHash,
                'foundation_content_hash' => $independentFoundationHash,
            ];
        }

        File::ensureDirectoryExists(dirname($resolvedManifestPath));
        $foundationManifest = [
            'content_hash' => $independentFoundationHash,
            'source_content_hash' => $sourceHash,
            'source' => $sourceProvider,
            'source_provider' => $sourceProvider,
            'source_role' => 'independent_temporal_ablation_only',
            'timezone' => 'UTC',
            'new_candles' => count($safeRows),
            'row_count' => count($safeRows),
            'overlap_ratio' => 0.0,
            'overlap_candle_count' => 0,
            'coverage_start' => $safeFirst->toIso8601String(),
            'coverage_end' => $safeLast->toIso8601String(),
            'path' => $independentFoundationPath,
            'source_path' => $source,
            'source_manifest_path' => $sourceManifestPath,
            'excluded_rescue_overlap_candles' => $sourceOverlapCount,
            'excluded_rescue_ranges' => $rescueRanges,
            'independent_from_rescue' => true,
            'attestation' => $overlapAttestation,
        ];
        $manifest = [
            'protocol' => 'temporal_clean_ablation_manifest_v2',
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'hypothesis' => 'temporal_survival_plus_drift_abstention_v1',
            'independent_attestation' => true,
            'independent_holdout' => true,
            'foundation_dataset_path' => $independentFoundationPath,
            'foundation_dataset_manifest_path' => $independentFoundationManifestPath,
            'foundation_dataset' => $foundationManifest,
            'source_dataset' => [
                'path' => $source,
                'content_hash' => $sourceHash,
                'provider' => $sourceProvider,
                'row_count' => (int) data_get($sourceManifest, 'row_count', count($rows)),
                'coverage_start' => data_get($sourceManifest, 'first_candle_at'),
                'coverage_end' => data_get($sourceManifest, 'last_candle_at'),
            ],
            'prior_generation_ranges' => $rescueRanges,
            'data_identity_protocol' => 'content_and_timestamp_disjoint_v1',
            'attestation' => $overlapAttestation,
            'temporal_threshold' => (float) config('services.rescue_circuit_breaker.temporal_threshold', 1.0),
            'windows' => $windows,
            'created_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ];
        File::put($resolvedManifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        $this->line(json_encode([
            'manifest' => $resolvedManifestPath,
            'foundation_hash' => $independentFoundationHash,
            'source_hash' => $sourceHash,
            'source_provider' => $sourceProvider,
            'excluded_rescue_overlap_candles' => $sourceOverlapCount,
            'windows' => $windows,
            'execution_hash' => $contract['execution_hash'],
            'promotion_evidence' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function failCommand(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    /** @return array<int, array{first: CarbonImmutable, last: CarbonImmutable, manifest: string}> */
    private function priorGenerationRanges(string $symbol, string $timeframe): array
    {
        $lab = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->first();
        if (! $lab) return [];

        // Only the prior targeted-failure family is the circuit-breaker
        // boundary. Normal generations may legitimately use the long
        // foundation archive; treating every historical generation as rescue
        // evidence would make an independent pre-2025 source impossible.
        $targetedGenerations = $lab->generations()->get()->filter(
            fn ($generation): bool => is_array(data_get($generation->trigger_context, 'targeted_failure_profile'))
                && data_get($generation->trigger_context, 'targeted_failure_profile') !== [],
        );
        if ($targetedGenerations->isEmpty()) return [];

        $ranges = [];
        foreach ($targetedGenerations as $generation) {
            $pattern = storage_path("app/lab-datasets/generations/G{$generation->generation}_id{$generation->id}_{$symbol}_{$timeframe}*.manifest.json");
            foreach (glob($pattern) ?: [] as $path) {
                $manifest = json_decode((string) file_get_contents($path), true);
                if (! is_array($manifest)) continue;
                $first = $this->timestamp(data_get($manifest, 'first_candle_at'));
                $last = $this->timestamp(data_get($manifest, 'last_candle_at'));
                if (! $first || ! $last || $last->lessThan($first)) continue;
                $key = $first->toIso8601String().'|'.$last->toIso8601String();
                $ranges[$key] = ['first' => $first, 'last' => $last, 'manifest' => $path];
            }
        }

        usort($ranges, fn (array $left, array $right): int => $left['first']->getTimestamp() <=> $right['first']->getTimestamp());

        return array_values($ranges);
    }

    /** @param array<int, array{first: CarbonImmutable, last: CarbonImmutable}> $ranges */
    private function timeInRanges(CarbonImmutable $time, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($time->greaterThanOrEqualTo($range['first']) && $time->lessThanOrEqualTo($range['last'])) return true;
        }

        return false;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! filled($value)) return null;
        try {
            return CarbonImmutable::parse((string) $value, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
