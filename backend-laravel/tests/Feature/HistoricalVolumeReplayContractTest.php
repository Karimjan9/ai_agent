<?php

namespace Tests\Feature;

use App\Services\LabDatasetExportService;
use App\Services\MarketData\MarketVolumeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class HistoricalVolumeReplayContractTest extends TestCase
{
    public function test_foundation_volume_view_is_hash_bound_and_writes_explicit_availability(): void
    {
        $directory = storage_path('framework/testing/historical-volume-contract');
        File::ensureDirectoryExists($directory);
        $sourcePath = $directory.'/XAUUSD_H1_2005-2025.csv';
        $handle = fopen($sourcePath, 'wb');
        fputcsv($handle, ['time', 'open', 'high', 'low', 'close', 'volume']);
        $start = CarbonImmutable::parse('2025-01-01 00:00:00', 'UTC');
        for ($index = 0; $index < 202; $index++) {
            fputcsv($handle, [
                $start->addHours($index)->format('Y-m-d H:i:s'),
                1800,
                1801,
                1799,
                1800.5,
                12.5,
            ]);
        }
        fclose($handle);
        $sourceSha = hash_file('sha256', $sourcePath);
        $sourceManifest = [
            'protocol' => 'foundation_training_archive_v1',
            'source_provider' => 'dukascopy',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'row_count' => 202,
            'sha256' => $sourceSha,
            'promotion_evidence' => false,
        ];
        File::put($sourcePath.'.manifest.json', json_encode($sourceManifest));

        $method = new ReflectionMethod(LabDatasetExportService::class, 'materializeFoundationVolumeDataset');
        $result = $method->invoke(app(LabDatasetExportService::class), [
            'path' => $sourcePath,
            'manifest' => $sourceManifest,
            'sha256' => $sourceSha,
            'protocol' => 'foundation_training_archive_v1',
        ]);

        try {
            $this->assertSame('passed', data_get($result, 'manifest.volume_provenance.status'));
            $this->assertSame(MarketVolumeService::HISTORICAL_SOURCE_CONTRACT, data_get($result, 'manifest.volume_provenance.source_contract'));
            $this->assertSame($sourceSha, data_get($result, 'manifest.volume_provenance.source_snapshot_sha256'));
            $this->assertFalse((bool) data_get($result, 'manifest.volume_provenance.live_coverage_inherited'));
            $this->assertSame(1.0, data_get($result, 'manifest.volume_quality.coverage'));
            $header = str_getcsv((string) strtok((string) File::get((string) $result['path']), "\n"));
            $this->assertContains('volume_available', $header);
        } finally {
            File::delete([
                (string) $result['path'],
                (string) $result['path'].'.manifest.json',
                (string) $result['path'].'.lock',
            ]);
            File::deleteDirectory($directory);
        }
    }

    public function test_legacy_self_sealed_zero_repair_foundation_can_be_re_attested_without_live_coverage(): void
    {
        $directory = storage_path('framework/testing/historical-volume-contract-legacy');
        File::ensureDirectoryExists($directory);
        $sourcePath = $directory.'/legacy-foundation.csv';
        $handle = fopen($sourcePath, 'wb');
        fputcsv($handle, ['time', 'open', 'high', 'low', 'close', 'volume']);
        $start = CarbonImmutable::parse('2025-01-01 00:00:00', 'UTC');
        for ($index = 0; $index < 202; $index++) {
            fputcsv($handle, [$start->addHours($index)->format('Y-m-d H:i:s'), 1, 2, 0.5, 1.5, 10]);
        }
        fclose($handle);
        $sourceSha = hash_file('sha256', $sourcePath);
        $manifest = [
            'protocol' => 'foundation_training_archive_v1',
            'source_provider' => 'historical_generation_snapshot',
            'source_archive_path' => $sourcePath,
            'source_archive_sha256' => $sourceSha,
            'reuse_protocol' => 'immutable_generation_archive_foundation_reuse_v1',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'row_count' => 202,
            'sha256' => $sourceSha,
            'ohlc_quality' => ['source_invalid_rows_repaired' => 0],
            'gap_quality' => ['source_missing_rows' => 0, 'repaired_rows' => 0, 'unresolved_rows' => 0],
        ];
        File::put($sourcePath.'.manifest.json', json_encode($manifest));
        $method = new ReflectionMethod(LabDatasetExportService::class, 'materializeFoundationVolumeDataset');
        $result = $method->invoke(app(LabDatasetExportService::class), [
            'path' => $sourcePath,
            'manifest' => $manifest,
            'sha256' => $sourceSha,
            'protocol' => 'foundation_training_archive_v1',
        ]);

        try {
            $this->assertSame('passed', data_get($result, 'manifest.volume_provenance.status'));
            $this->assertSame(
                'legacy_self_sealed_foundation_receipt_and_frozen_row_audit',
                data_get($result, 'manifest.volume_provenance.attestation_basis'),
            );
            $this->assertTrue((bool) data_get($result, 'manifest.volume_provenance.legacy_source_identity_recovered'));
            $this->assertFalse((bool) data_get($result, 'manifest.volume_provenance.live_coverage_inherited'));
        } finally {
            File::delete([(string) $result['path'], (string) $result['path'].'.manifest.json', (string) $result['path'].'.lock']);
            File::deleteDirectory($directory);
        }
    }

    public function test_numeric_volume_without_canonical_source_manifest_is_rejected(): void
    {
        $directory = storage_path('framework/testing/historical-volume-contract-reject');
        File::ensureDirectoryExists($directory);
        $sourcePath = $directory.'/legacy.csv';
        File::put($sourcePath, "time,open,high,low,close,volume\n2025-01-01 00:00:00,1,2,0.5,1.5,10\n");
        $sourceSha = hash_file('sha256', $sourcePath);
        $method = new ReflectionMethod(LabDatasetExportService::class, 'materializeFoundationVolumeDataset');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('canonical provenance');
            $method->invoke(app(LabDatasetExportService::class), [
                'path' => $sourcePath,
                'manifest' => ['timeframe' => 'H1', 'sha256' => $sourceSha],
                'sha256' => $sourceSha,
                'protocol' => 'foundation_training_archive_v1',
            ]);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
