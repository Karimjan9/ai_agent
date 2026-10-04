<?php

namespace Tests\Feature;

use App\Services\ExecutionContractService;
use App\Services\MarketData\MarketTrainingDataService;
use App\Services\MultiTimeframeSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Synthetic native-provider rows exercise the actual calendar/SQL/freeze owners, not market edge. */
class ProspectiveCleanDiscoverySnapshotTest extends TestCase
{
    use RefreshDatabase;

    private array $directories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->directories) as $directory) if (is_dir($directory)) File::deleteDirectory($directory);
        parent::tearDown();
    }

    public function test_clean_scope_freezes_real_selected_rows_but_full_validation_stays_blocked(): void
    {
        [$dataset, $price, $source] = $this->nativeFixture();
        $owner = app(MultiTimeframeSnapshotService::class);
        config()->set('services.xauusd_organism.research_m5_dataset', $dataset);
        $this->assertSame('HISTORICAL_M5_CONTINUITY_SCOPE_UNRESOLVED', $owner->agentValidationReadiness('XAUUSD')['reason']);
        $before = hash_file('sha256', $source);
        $ready = $owner->prospectiveCleanDiscoveryReadiness('XAUUSD', $dataset);
        $this->assertTrue($ready['ready'], json_encode($ready));
        $this->assertSame(1, data_get($ready, 'discovery_scope.calendar.full_source_unexpected_gaps'));
        $this->assertSame(0, data_get($ready, 'discovery_scope.calendar.selected_unexpected_gaps'));
        $this->assertSame(15512, data_get($ready, 'discovery_scope.calendar.loaded_rows'));
        $bundle = $owner->forProspectiveCleanDiscovery('XAUUSD', $dataset);
        $this->directories[] = dirname($bundle['manifest_path']);
        $manifest = $bundle['manifest'];
        $this->assertSame(MultiTimeframeSnapshotService::DISCOVERY_BUNDLE_PROTOCOL, $manifest['validation_bundle_protocol']);
        $this->assertSame('pre_2026_discovery_only', $manifest['data_role']);
        $this->assertSame(15512, $manifest['streams']['M5']['row_count']);
        $this->assertFalse($manifest['independent_evidence']);
        $this->assertFalse($manifest['full_validation_eligible']);
        $this->assertFalse($manifest['paper_eligible']);
        $this->assertFalse($manifest['promotion_evidence']);
        $this->assertSame($ready['discovery_scope'], $manifest['discovery_scope']);
        $this->assertSame($before, hash_file('sha256', $source));
        $this->assertSame(hash_file('sha256', $price), $manifest['discovery_scope']['parent_fork_price_sha256']);
        // Actual quote owner preserves missing observations; discovery is not permission to call them liquid.
        $this->assertSame(0, (int) data_get($manifest, 'quote_spread_provenance.available_rows', 0));
        $this->assertTrue($owner->discoveryBundleReadiness($manifest)['allowed']);
        $this->assertRealPythonLoader($bundle);
        $restored = $owner->restoreAgentOwnedConfirmationValidationBundle($manifest, true);
        $this->assertSame($bundle['bundle_hash'], $restored['bundle_hash']);
        try { $owner->restoreAgentOwnedConfirmationValidationBundle($manifest); $this->fail('Discovery satisfied full validation.'); }
        catch (\RuntimeException $error) { $this->assertSame('DISCOVERY_BUNDLE_CANNOT_SATISFY_FULL_VALIDATION', $error->getMessage()); }
        // JSON representation and object order are not changed data or a new allowance.
        $hydrated = json_decode(json_encode($manifest), true);
        $hydrated['streams'] = array_reverse($hydrated['streams'], true);
        $this->assertTrue($owner->discoveryBundleReadiness($hydrated)['allowed']);
        // The actual quote owner enriches a NEW stream. Numeric quote fields
        // survive CSV rehydration and do not fabricate observations elsewhere.
        $this->quoteArtifact($manifest);
        $quoted = $owner->forProspectiveCleanDiscovery('XAUUSD', $dataset);
        $this->directories[] = dirname($quoted['manifest_path']);
        $this->assertNotSame($bundle['bundle_hash'], $quoted['bundle_hash']);
        $this->assertSame(1, data_get($quoted, 'manifest.quote_spread_provenance.available_rows'));
        $this->assertTrue($owner->discoveryBundleReadiness($quoted['manifest'])['allowed']);
        $this->assertRealPythonLoader($quoted);
        $this->assertSame('HISTORICAL_M5_CONTINUITY_SCOPE_UNRESOLVED', $owner->agentValidationReadiness('XAUUSD')['reason']);
        $this->assertDatabaseCount('edge_academy_trials', 0);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    /** Shared actual-owner fixture; caller owns cleanup of the returned directories. */
    public function cleanBundleFixture(): array
    {
        [$dataset] = $this->nativeFixture();
        $bundle = app(MultiTimeframeSnapshotService::class)->forProspectiveCleanDiscovery('XAUUSD', $dataset);
        $this->directories[] = dirname($bundle['manifest_path']);
        return ['dataset' => $dataset, 'bundle' => $bundle, 'directories' => $this->directories];
    }

    private function assertRealPythonLoader(array $bundle): void
    {
        // Real bytes -> actual immutable row-index owner -> consumed attestations
        // on ALL four streams. This deliberately does not run a strategy/replay.
        $script = <<<'PY'
import json, sys
from app.schemas import SimpleBacktestRequest
from app.services.backtester import _load_verified_dataset_csv, _load_mtf_streams, _consumed_dataset_attestation, _context_source_attestation
m = json.load(open(sys.argv[1], encoding='utf-8'))
p = SimpleBacktestRequest(timeframe='M5', dataset_path=m['streams']['M5']['path'], replay_dataset_hash=m['bundle_hash'], mtf_snapshot_manifest=m, mtf_dataset_paths={k: v['path'] for k,v in m['streams'].items() if k != 'M5'})
f = _load_verified_dataset_csv(p, p.dataset_path, 'M5')
a = {'M5': _consumed_dataset_attestation(p,f)}
for k,v in _load_mtf_streams(p).items(): a[k] = _context_source_attestation(p,k,v)
print(json.dumps({'streams': a, 'observed_quote_rows': int(f['spread_available'].sum()) if 'spread_available' in f else 0}))
PY;
        $process = new Process(['python', '-B', '-c', $script, $bundle['manifest_path']], dirname(base_path()).'/ai-service-python');
        $process->setTimeout(60); $process->mustRun();
        $actual = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame((int) data_get($bundle, 'manifest.quote_spread_provenance.available_rows', 0), $actual['observed_quote_rows']);
        foreach ($bundle['manifest']['streams'] as $timeframe => $stream) {
            $this->assertSame('verified', $actual['streams'][$timeframe]['status']);
            $this->assertSame($stream['sha256'], $actual['streams'][$timeframe]['actual_source_sha256']);
            $this->assertSame($stream['row_count'], $actual['streams'][$timeframe]['consumed_rows']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $actual['streams'][$timeframe]['consumed_data_hash']);
        }
    }

    private function quoteArtifact(array $manifest): void
    {
        $open = CarbonImmutable::parse($manifest['entry_first_candle_at']); $close = $open->addMinutes(5);
        $quote = $close->subSecond()->format('Y-m-d\TH:i:s.000\Z');
        $after = $close->format('Y-m-d\TH:i:s.000\Z');
        $handle = fopen($manifest['streams']['M5']['path'], 'rb');
        try { $header = fgetcsv($handle); $first = array_combine($header, fgetcsv($handle)); }
        finally { fclose($handle); }
        $bid = number_format((float) $first['close'], 6, '.', '');
        $ask = number_format((float) $first['close'] + 0.5, 6, '.', '');
        $csv = "time,spread,spread_available,bid_close,ask_close,quote_time_utc,available_after_utc,quote_age_ms,observation_status\n"
            .$open->format('Y-m-d H:i:s').",0.5,1,{$bid},{$ask},{$quote},{$after},1000,paired\n";
        $identity = [
            'protocol' => 'research_quote_spread_ticks_v1', 'symbol' => 'XAUUSD', 'timeframe' => 'M5',
            'm5_sha256' => $manifest['quote_spread_provenance']['source_m5_csv_sha256'],
            'provider' => 'dukascopy_historical_synchronized_tick_v1',
            'observation' => 'last_synchronized_bid_ask_tick_strictly_before_m5_close', 'maximum_quote_age_ms' => 60000,
            'source_hour_content_sha256' => [$open->startOfHour()->toISOString() => str_repeat('c', 64)],
            'from_utc' => $open->toDateString(), 'to_utc_exclusive' => $open->addDay()->toDateString(),
            'freezer_sha256' => str_repeat('d', 64), 'decoder_sha256' => str_repeat('e', 64),
            'dependency_lock_sha256' => str_repeat('f', 64), 'sidecar_sha256' => hash('sha256', $csv),
            'paper_2026_included' => false, 'promotion_evidence' => false, 'automatic_replay_authority' => false,
        ];
        $hash = hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $directory = storage_path('app/lab-datasets/quote-spread/'.$hash);
        File::ensureDirectoryExists($directory); $this->directories[] = $directory;
        File::put($directory.'/m5-spread.csv', $csv);
        File::put($directory.'/manifest.json', json_encode(['identity_hash' => $hash, 'identity' => $identity]));
    }

    public function test_parent_bytes_missing_provenance_and_smaller_budgets_cannot_be_relabelled(): void
    {
        [$dataset, $price] = $this->nativeFixture(); $owner = app(MultiTimeframeSnapshotService::class);
        $this->assertFalse($owner->prospectiveCleanDiscoveryReadiness('XAUUSD', $dataset, 14999)['ready']);
        $this->assertFalse($owner->prospectiveCleanDiscoveryReadiness('XAUUSD', 'foundation_intraday_10y')['ready']);
        $bundle = $owner->forProspectiveCleanDiscovery('XAUUSD', $dataset);
        $this->directories[] = dirname($bundle['manifest_path']);
        $changed = $bundle['manifest']; $changed['independent_evidence'] = true;
        $this->assertSame('DISCOVERY_EVIDENCE_BOUNDARY_INVALID', $owner->discoveryBundleReadiness($changed)['reason']);
        foreach (['runtime_trade_authority', 'parent_authority'] as $flag) {
            $changed = $bundle['manifest']; $changed[$flag] = true;
            $this->assertSame('DISCOVERY_EVIDENCE_BOUNDARY_INVALID', $owner->discoveryBundleReadiness($changed)['reason']);
        }
        File::append($price, "\n");
        $this->assertSame('PROSPECTIVE_M5_REPAIR_PROVENANCE_INVALID', $owner->prospectiveCleanDiscoveryReadiness('XAUUSD', $dataset)['reason']);
        $this->assertSame('DISCOVERY_PARENT_OR_SELECTED_SCOPE_CHANGED', $owner->discoveryBundleReadiness($bundle['manifest'])['reason']);
    }

    private function nativeFixture(): array
    {
        if (! class_exists(\FrozenM5GapRecoveryOperation::class, false)) require_once base_path('scripts/recover-frozen-m5-gap.php');
        [$m1, $m5, $ticks] = (new FrozenM5GapRecoveryTest('fixture'))->batchProofFixture();
        $proof = \FrozenM5GapRecoveryOperation::batchProof('2025-12-17 23:00:00', $m1, $m5, $ticks);
        $all = $this->rows('2025-09-20 00:00:00', 5, 26001);
        $source = array_values(array_filter($all, static fn ($row) => ! in_array($row['time'], ['2025-12-17 23:00:00', '2025-10-01 10:00:00'], true)));
        $directory = storage_path('app/lab-datasets/mtf/test-clean-discovery-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($directory); $this->directories[] = $directory;
        $sourcePath = $directory.'/m5.csv'; File::put($sourcePath, \FrozenM5GapRecoveryOperation::csvBytes($source));
        $missing = ['2025-10-01T10:00:00+00:00', '2025-12-17T23:00:00+00:00'];
        $rows = \FrozenM5GapRecoveryOperation::forkMany($source, [$proof], $missing);
        $csv = \FrozenM5GapRecoveryOperation::csvBytes($rows); $sourceHash = hash_file('sha256', $sourcePath);
        $identity = ['protocol' => 'frozen_m5_gap_recovery_v2', 'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'provider' => 'dukascopy',
            'source_csv_path' => realpath($sourcePath), 'source_csv_sha256' => $sourceHash,
            'source_rows' => count($source), 'new_rows' => count($rows), 'source_first_at' => $source[0]['time'],
            'source_last_at' => $source[array_key_last($source)]['time'], 'new_price_csv_sha256' => hash('sha256', $csv),
            'economic_row_hash_protocol' => 'training_decimal_6_rows_v1', 'new_economic_rows_sha256' => \FrozenM5GapRecoveryOperation::economicRowsHash($rows),
            'target_proofs' => [$proof], 'canonical_missing_utc' => $missing,
            'unresolved_targets' => [['target_utc' => $missing[0], 'reason' => 'RECOVERY_BATCH_NO_OBSERVED_TICKS']],
            'provider_days_requested' => 1, 'tick_hours_requested' => 1, 'collection_ceiling_seconds' => 1800,
            'calendar_scope' => ['protocol' => 'frozen_source_calendar_scope_audit_v2', 'source_csv_sha256' => $sourceHash,
                'source_rows' => count($source), 'new_rows' => count($rows), 'canonical_missing_utc' => $missing,
                'recovered_targets_utc' => [$missing[1]], 'remaining_missing_utc' => [$missing[0]],
                'full_source_unexpected_before' => 2, 'full_source_unexpected_after' => 1,
                'whole_archive_continuity_proven' => false, 'screening_unexpected_after' => 0],
            'independent_evidence' => false, 'runtime_trade_authority' => false, 'promotion_evidence' => false, 'quote_liquidity_inherited' => false];
        $hash = app(ExecutionContractService::class)->hashParameters($identity); $dataset = 'foundation_intraday_gapfix_'.substr($hash, 0, 16);
        $priceDirectory = storage_path('app/lab-datasets/training/recovery/'.$hash);
        File::ensureDirectoryExists($priceDirectory); $this->directories[] = $priceDirectory;
        $price = $priceDirectory.'/m5.csv'; File::put($price, $csv);
        $training = app(MarketTrainingDataService::class);
        $archive = $this->archive($training, $dataset, 'M5', $rows);
        $archive->update(['metrics' => ['frozen_m5_gap_recovery_receipt' => [...$identity, 'repair_hash' => $hash, 'dataset_key' => $dataset],
            'frozen_m5_gap_recovery_price_path' => $price]]);
        $this->archive($training, 'foundation_10y', 'H1', $this->rows('2025-04-01 00:00:00', 60, 6400));
        $this->archive($training, 'foundation_10y', 'M15', $this->rows('2025-07-01 00:00:00', 15, 17000));
        return [$dataset, $price, $sourcePath];
    }

    private function archive(MarketTrainingDataService $training, string $dataset, string $timeframe, array $rows)
    {
        $archive = $training->ensureArchive($dataset, 'dukascopy', 'XAUUSD', $timeframe,
            CarbonImmutable::parse($rows[0]['time']), CarbonImmutable::parse($rows[array_key_last($rows)]['time'])->addMinutes(5));
        $training->upsertCandles($dataset, 'dukascopy', 'XAUUSD', $timeframe, $rows); $training->refreshCoverage($archive);
        $archive->update(['status' => 'complete']); return $archive;
    }

    private function rows(string $start, int $minutes, int $count): array
    {
        $at = CarbonImmutable::parse($start, 'UTC'); $rows = [];
        for ($index = 0; $index < $count; $index++) {
            // Nonconstant actual input lets the real feature owners calculate
            // directional indicators, without inventing market evidence.
            $price = round(1000.0 + ($index % 47) * 0.02 + $index * 0.0001, 6);
            $rows[] = ['time' => $at->addMinutes($index * $minutes)->format('Y-m-d H:i:s'),
                'open' => $price, 'high' => $price + 1, 'low' => $price - 1, 'close' => $price + 0.5, 'volume' => 1.0];
        }
        return $rows;
    }
}
