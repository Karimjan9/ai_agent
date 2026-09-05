<?php

namespace Tests\Feature;

use App\Services\LabDatasetExportService;
use App\Services\MarketData\HistoricalDataQualityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class FoundationValidationReceiptTest extends TestCase
{
    public function test_persistent_receipt_is_content_addressed_and_changed_bytes_force_full_validation(): void
    {
        $directory = storage_path('framework/testing/foundation-validation-receipt');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/foundation-'.uniqid('', true).'.csv';
        $manifestPath = $path.'.manifest.json';
        $rows = ['time,open,high,low,close,volume'];
        for ($index = 0; $index < 202; $index++) {
            $rows[] = sprintf('2024-01-%02dT00:00:00Z,100,101,99,100.5,1', ($index % 28) + 1);
        }
        File::put($path, implode(PHP_EOL, $rows).PHP_EOL);
        $manifest = $this->manifest($path, 202);
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $service = app(LabDatasetExportService::class);
        $method = new ReflectionMethod($service, 'validFoundationSnapshot');
        $memo = new ReflectionProperty(LabDatasetExportService::class, 'validatedFoundationSnapshots');
        $memo->setValue(null, []);

        $validated = $method->invoke($service, $path, $manifestPath);
        $this->assertIsArray($validated);
        $cacheKey = $this->cacheKey($service, $path, $manifestPath, 202);
        $this->assertIsArray(Cache::get('lab:foundation-validation:'.$cacheKey));

        // Simulate a fresh queue worker. The process-local memo is empty, so
        // only the persistent content-addressed receipt can accelerate this.
        $memo->setValue(null, []);
        $this->assertIsArray($method->invoke($service, $path, $manifestPath));

        // An invalid OHLC byte plus its matching new manifest hash must not be
        // hidden by the old receipt. The new content key has no authority yet.
        $rows[1] = '2024-01-01T00:00:00Z,100,90,99,100.5,1';
        File::put($path, implode(PHP_EOL, $rows).PHP_EOL);
        File::put($manifestPath, json_encode($this->manifest($path, 202), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        $memo->setValue(null, []);
        $this->assertNull($method->invoke($service, $path, $manifestPath));

        Cache::forget('lab:foundation-validation:'.$cacheKey);
        File::delete([$path, $manifestPath]);
    }

    /** @return array<string, mixed> */
    private function manifest(string $path, int $rows): array
    {
        return [
            'protocol' => 'foundation_training_archive_v1',
            'timeframe' => 'H1',
            'row_count' => $rows,
            'last_candle_at' => '2024-12-31T23:00:00Z',
            'sha256' => hash_file('sha256', $path),
            'continuity' => [
                'protocol' => HistoricalDataQualityService::FOUNDATION_CONTINUITY_PROTOCOL,
                'status' => 'ready',
                'row_count' => $rows,
                'unexpected_gap_count' => 0,
                'missing_open_candles' => 0,
                'invalid_rows' => 0,
            ],
            'gap_quality' => ['status' => 'passed', 'unresolved_rows' => 0],
        ];
    }

    private function cacheKey(LabDatasetExportService $service, string $path, string $manifestPath, int $minimumRows): string
    {
        $protocol = (new ReflectionClass(LabDatasetExportService::class))
            ->getConstant('FOUNDATION_VALIDATION_CACHE_PROTOCOL');
        $cutoff = (new ReflectionMethod($service, 'trainingCutoff'))->invoke($service);

        return hash('sha256', implode('|', [
            $protocol,
            str_replace('\\', '/', (string) realpath($path)),
            (string) hash_file('sha256', $path),
            (string) hash_file('sha256', $manifestPath),
            'H1',
            (string) $minimumRows,
            $cutoff->toIso8601String(),
        ]));
    }
}
