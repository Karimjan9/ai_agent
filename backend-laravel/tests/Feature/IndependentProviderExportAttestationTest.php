<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class IndependentProviderExportAttestationTest extends TestCase
{
    public function test_it_attests_an_external_pre_2026_export_without_touching_training_or_canonical_tables(): void
    {
        $source = storage_path('app/testing-independent-xauusd-h1.csv');
        File::put($source, implode(PHP_EOL, [
            'time,open,high,low,close,volume',
            '2025-12-30 22:00:00,2000,2002,1998,2001,1',
            '2025-12-30 23:00:00,2001,2003,1999,2002,1',
        ]).PHP_EOL);
        $hash = hash_file('sha256', $source);
        $target = storage_path('app/lab-datasets/independent-exports/XAUUSD_H1_independent-feed_'.substr((string) $hash, 0, 16).'.csv');

        $this->artisan('market-data:attest-independent-export', [
            'source' => $source,
            '--provider' => 'independent-feed',
            '--symbol' => 'XAUUSD',
            '--timeframe' => 'H1',
        ])->assertSuccessful();

        $this->assertFileExists($target);
        $this->assertFileExists($target.'.manifest.json');
        $manifest = json_decode((string) File::get($target.'.manifest.json'), true);
        $this->assertSame('independent_provider_export_attestation_v1', $manifest['protocol']);
        $this->assertSame('independent-feed', $manifest['provider']);
        $this->assertSame('2025-12-30T23:00:00+00:00', $manifest['last_candle_at']);
        $this->assertFalse($manifest['promotion_evidence']);

        File::delete([$source, $target, $target.'.manifest.json']);
    }

    public function test_it_rejects_a_2026_candle_from_the_independent_export(): void
    {
        $source = storage_path('app/testing-independent-paper-leak.csv');
        File::put($source, implode(PHP_EOL, [
            'time,open,high,low,close',
            '2025-12-31 23:00:00,2000,2002,1998,2001',
            '2026-01-01 00:00:00,2001,2003,1999,2002',
        ]).PHP_EOL);

        $this->artisan('market-data:attest-independent-export', [
            'source' => $source,
            '--provider' => 'independent-feed',
        ])->expectsOutputToContain('2026-01-01')->assertFailed();

        File::delete($source);
    }
}
