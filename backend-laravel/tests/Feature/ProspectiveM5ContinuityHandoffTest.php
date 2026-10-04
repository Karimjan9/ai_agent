<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\AcademyExperimentMaterializerService;
use App\Services\AutonomousLearningProgressDirectorService;
use App\Services\AutonomousModeService;
use App\Services\ExecutionContractService;
use App\Services\GenerationSnapshotAdmissionService;
use App\Services\LabDatasetExportService;
use App\Services\LabImmutableEvidenceService;
use App\Services\MarketData\MarketTrainingDataService;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\ResearchLoopArbiterService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Synthetic provider fixtures prove owner wiring, not market edge or new authority. */
class ProspectiveM5ContinuityHandoffTest extends TestCase
{
    use RefreshDatabase;

    public static function recoveryProtocols(): array
    {
        return ['single_native_v1' => [false, false], 'bounded_native_batch_v2' => [true, false],
            'native_batch_with_unresolved_outside_screening_tail' => [true, true]];
    }

    #[DataProvider('recoveryProtocols')]
    public function test_native_verified_fork_releases_ordinary_freeze_but_never_relabels_old_academy_bundle(bool $batch, bool $residual): void
    {
        if (! class_exists(\FrozenM5GapRecoveryOperation::class, false)) require_once base_path('scripts/recover-frozen-m5-gap.php');
        Queue::fake();
        config()->set('services.xauusd_organism.historical_research_until_champion', true);
        $disk = 'continuity-handoff-'.bin2hex(random_bytes(8));
        config()->set('services.lab_evidence.disk', $disk);
        Storage::fake($disk);
        $sourceDirectory = storage_path('app/lab-datasets/mtf/test-continuity-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($sourceDirectory);
        $sourcePath = $sourceDirectory.'/m5.csv';
        $last = CarbonImmutable::parse('2025-12-17 23:05:00', 'UTC');
        $residualTime = $last->subMinutes(9000 * 5)->format('Y-m-d H:i:s');
        $source = [];
        for ($offset = 10000; $offset >= 0; $offset--) {
            $time = $last->subMinutes($offset * 5)->format('Y-m-d H:i:s');
            if ($time !== '2025-12-17 23:00:00' && (! $residual || $time !== $residualTime)) $source[] = $this->row($time);
        }
        File::put($sourcePath, \FrozenM5GapRecoveryOperation::csvBytes($source));
        $sourceHash = hash_file('sha256', $sourcePath);
        $manifest = ['protocol' => MultiTimeframeSnapshotService::PROTOCOL, 'bundle_hash' => str_repeat('b', 64),
            'datasets' => ['M5' => 'foundation_intraday_10y'], 'entry_last_candle_at' => $last->toIso8601String(),
            'streams' => array_fill_keys(['M5', 'M15', 'H1', 'H4'], ['path' => $sourcePath, 'sha256' => $sourceHash])];
        $lab = AiLaboratory::create(['name' => 'Native prospective continuity handoff', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['confirmation_entry_mtf'], 'is_active' => true,
            'lifecycle_mode' => 'lighthouse']);
        $old = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'status' => 'technical_quarantine', 'trigger_type' => 'academy_experiment',
            'trigger_context' => ['mtf_bundle_manifest' => $manifest]]);
        $model = ModelVersion::create(['name' => 'Native continuity original control', 'version' => 'continuity-v1',
            'strategy' => 'confirmation_entry_mtf_v1', 'parameters' => ['risk_per_trade' => .01],
            'metadata' => ['base_strategy' => 'confirmation_entry_mtf_v1']]);
        $agent = LabAgent::create(['lab_generation_id' => $old->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf',
            'origin' => 'academy_experiment', 'lifecycle_status' => 'technical_quarantine', 'parameter_diff' => []]);
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($agent, 'screening', 'incremental', ['data_hash' => $manifest['bundle_hash']]);
        $evidence->attachRequest($run, ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluation_mode' => 'incremental',
            'replay_dataset_hash' => $manifest['bundle_hash'], 'mtf_snapshot_manifest' => $manifest,
            'strategies' => [['strategy' => $model->strategy, 'version' => $model->version, 'parameters' => $model->parameters]]]);
        $evidence->finishRun($run, 'technical_error', null, [], ['strategy_verdict' => 'withheld'],
            new \RuntimeException('Historical data hard-gate failed: '.($residual ? '2' : '1').' unexpected candle gaps.'));
        $runBefore = $run->fresh()->toArray();
        $oldBefore = $old->fresh()->toArray();
        $this->assertFalse(app(GenerationSnapshotAdmissionService::class)->historicalDatasetReadiness($manifest)['allowed']);
        $recovered = ['2025-12-17T23:00:00+00:00'];
        $remaining = $residual ? [str_replace(' ', 'T', $residualTime).'+00:00'] : [];
        $missing = [...$remaining, ...$recovered];
        if ($batch) {
            if (! class_exists(FrozenM5GapRecoveryTest::class, false)) require_once base_path('tests/Feature/FrozenM5GapRecoveryTest.php');
            [$m1, $m5, $ticks] = (new FrozenM5GapRecoveryTest('fixture'))->batchProofFixture();
            $proof = \FrozenM5GapRecoveryOperation::batchProof('2025-12-17 23:00:00', $m1, $m5, $ticks);
            $rows = \FrozenM5GapRecoveryOperation::forkMany($source, [$proof], $missing);
        } else {
            [$m1, $m5, $ticks] = $this->nativeProviderFixture();
            $proof = \FrozenM5GapRecoveryOperation::proof($m1, $m5, $ticks);
            $rows = \FrozenM5GapRecoveryOperation::fork($source, $proof);
        }
        $priceCsv = \FrozenM5GapRecoveryOperation::csvBytes($rows);
        $identity = ['protocol' => $batch ? 'frozen_m5_gap_recovery_v2' : 'frozen_m5_gap_recovery_v1',
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'provider' => 'dukascopy',
            'source_csv_path' => realpath($sourcePath), 'source_csv_sha256' => $sourceHash,
            'source_rows' => count($source), 'new_rows' => count($rows),
            'source_first_at' => $source[0]['time'], 'source_last_at' => $source[array_key_last($source)]['time'],
            'new_price_csv_sha256' => hash('sha256', $priceCsv), 'economic_row_hash_protocol' => 'training_decimal_6_rows_v1',
            'new_economic_rows_sha256' => \FrozenM5GapRecoveryOperation::economicRowsHash($rows),
            ...($batch ? ['target_proofs' => [$proof], 'canonical_missing_utc' => $missing,
                'unresolved_targets' => array_map(fn ($time) => ['target_utc' => $time, 'reason' => 'RECOVERY_BATCH_NO_OBSERVED_TICKS'], $remaining),
                'provider_days_requested' => 1, 'tick_hours_requested' => 1,
                'collection_ceiling_seconds' => 1800,
                'calendar_scope' => ['protocol' => 'frozen_source_calendar_scope_audit_v2', 'source_csv_sha256' => $sourceHash,
                    'source_rows' => count($source), 'new_rows' => count($rows),
                    'canonical_missing_utc' => $missing, 'recovered_targets_utc' => $recovered, 'remaining_missing_utc' => $remaining,
                    'full_source_unexpected_before' => count($missing), 'full_source_unexpected_after' => count($remaining),
                    'whole_archive_continuity_proven' => ! $residual, 'screening_unexpected_after' => 0]]
                : ['proof' => $proof, 'calendar_scope' => ['protocol' => 'frozen_source_calendar_scope_audit_v1',
                    'source_csv_sha256' => $sourceHash, 'full_source_unexpected_after' => 0, 'screening_unexpected_after' => 0]]),
            'independent_evidence' => false, 'runtime_trade_authority' => false,
            'promotion_evidence' => false, 'quote_liquidity_inherited' => false];
        $repairHash = app(ExecutionContractService::class)->hashParameters($identity);
        $dataset = 'foundation_intraday_gapfix_'.substr($repairHash, 0, 16);
        $priceDirectory = storage_path('app/lab-datasets/training/recovery/'.$repairHash);
        File::ensureDirectoryExists($priceDirectory);
        $pricePath = $priceDirectory.'/m5.csv';
        File::put($pricePath, $priceCsv);
        $bundleDirectory = null;
        try {
            $training = app(MarketTrainingDataService::class);
            $archive = $this->archive($training, $dataset, 'M5', $rows);
            $archive->update(['metrics' => ['frozen_m5_gap_recovery_receipt' => [...$identity, 'repair_hash' => $repairHash,
                'dataset_key' => $dataset], 'frozen_m5_gap_recovery_price_path' => $pricePath]]);
            $this->archive($training, 'foundation_10y', 'M15', $this->rows('2025-08-15 00:00:00', 15, 13000));
            $this->archive($training, 'foundation_10y', 'H1', $this->rows('2025-08-15 00:00:00', 60, 3250));
            config()->set('services.xauusd_organism.research_m5_dataset', $dataset);
            // Only the foundation exporter and search director are outside
            // this boundary. Native repair verifier, SQL, snapshot and
            // continuity owners are real; no verified flag is mocked.
            $this->mock(LabDatasetExportService::class, fn ($mock) => $mock->shouldReceive('foundationDependencyWatermark')
                ->andReturn(['archive_present' => true, 'manifest_hash' => str_repeat('f', 64), 'path' => $sourcePath]));
            $this->mock(AutonomousLearningProgressDirectorService::class,
                fn ($mock) => $mock->shouldReceive('advance')->andReturn(['action' => 'WAIT']));
            $snapshot = app(MultiTimeframeSnapshotService::class);
            $ready = $snapshot->agentValidationReadiness('XAUUSD');
            if ($residual) {
                $this->assertFalse($ready['ready'], json_encode($ready));
                $this->assertSame('HISTORICAL_M5_CONTINUITY_SCOPE_UNRESOLVED', $ready['reason']);
                $this->assertTrue($ready['prospective_m5_repair']['verified']);
                $this->assertSame(0, $ready['selected_screening_unexpected_gaps']);
                $this->assertSame(1, $ready['full_source_unexpected_gaps']);
                $this->assertFalse($ready['prospective_m5_repair']['calendar_scope']['whole_archive_continuity_proven']);
                $this->assertSame('ACADEMY_COLD_START_MTF_NOT_READY', app(AcademyExperimentMaterializerService::class)->coldStartProposal()['reason']);
                app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'real unresolved data dependency');
                $choice = app(ResearchLoopArbiterService::class)->tick('XAUUSD', 'H1', true);
                $this->assertSame('WAIT_DATASET_CONTINUITY', $choice['action'], json_encode($choice));
                $this->assertNull($choice['command']);
                try {
                    $snapshot->forAgentOwnedConfirmationValidation('XAUUSD');
                    $this->fail('A clean screening tail must not enable unresolved full/fold inputs.');
                } catch (\RuntimeException $exception) {
                    $this->assertStringContainsString('HISTORICAL_M5_CONTINUITY_SCOPE_UNRESOLVED', $exception->getMessage());
                }
                $this->assertSame($runBefore, $run->fresh()->toArray());
                $this->assertSame($oldBefore, $old->fresh()->toArray());
                $this->assertSame($sourceHash, hash_file('sha256', $sourcePath));
                $this->assertDatabaseCount('edge_academy_trials', 0);
                $this->assertDatabaseCount('research_loop_decisions', 0);
                Queue::assertNothingPushed();
                return;
            }
            $this->assertTrue($ready['ready'], json_encode($ready));
            $this->assertTrue($ready['prospective_m5_repair']['verified']);
            $this->assertSame($identity['protocol'], $ready['prospective_m5_repair']['protocol']);
            $this->assertSame($sourceHash, $ready['prospective_m5_repair']['original_bad_m5_sha256']);
            $this->assertSame($dataset, $ready['prospective_m5_repair']['dataset_key']);
            $materializer = app(AcademyExperimentMaterializerService::class);
            $beforeFreeze = $materializer->coldStartProposal();
            $this->assertSame('ACADEMY_COLD_START_CURRENT_SEALED_MTF_BYTES_UNAVAILABLE', $beforeFreeze['reason']);
            app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'real prospective data owner');
            $choice = app(ResearchLoopArbiterService::class)->tick('XAUUSD', 'H1', true);
            $this->assertSame('OPEN_HISTORICAL_RESEARCH_GENERATION', $choice['action'], json_encode($choice));
            $frozen = $snapshot->forAgentOwnedConfirmationValidation('XAUUSD');
            $bundleDirectory = dirname($frozen['manifest_path']);
            $this->assertSame($dataset, data_get($frozen, 'manifest.datasets.M5'));
            $this->assertSame($repairHash, data_get($frozen, 'manifest.prospective_m5_repair.repair_hash'));
            $this->assertNotSame($sourceHash, data_get($frozen, 'manifest.streams.M5.sha256'));
            $this->assertFalse(data_get($frozen, 'manifest.prospective_m5_repair.quote_liquidity_inherited'));
            $this->assertFalse(data_get($frozen, 'manifest.promotion_evidence'));
            $this->assertFalse(app(GenerationSnapshotAdmissionService::class)->historicalDatasetReadiness($manifest)['allowed']);
            $this->assertTrue(app(GenerationSnapshotAdmissionService::class)->historicalDatasetReadiness($frozen['manifest'])['allowed']);
            $prospective = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 2,
                'status' => 'completed', 'trigger_type' => 'historical_research',
                'trigger_context' => ['mtf_bundle_manifest' => $frozen['manifest']]]);
            $deps = (new \ReflectionMethod($materializer, 'coldStartDependencies'))->invoke($materializer, 'XAUUSD', 'H1');
            $this->assertTrue($deps['ready'], json_encode($deps));
            $this->assertSame($frozen['bundle_hash'], $deps['mtf_bundle_hash']);
            $this->assertSame($frozen['manifest']['streams']['M5']['sha256'], $deps['mtf_source_sha256']['M5']);
            $this->assertSame($runBefore, $run->fresh()->toArray());
            $this->assertSame($oldBefore, $old->fresh()->toArray());
            $this->assertSame($sourceHash, hash_file('sha256', $sourcePath));
            $this->assertDatabaseCount('edge_academy_trials', 0);
            $this->assertDatabaseCount('research_loop_decisions', 0);
            Queue::assertNothingPushed();
        } finally {
            // Exact fixture-owned paths only; no production dataset is touched.
            if ($bundleDirectory !== null) File::deleteDirectory($bundleDirectory);
            File::deleteDirectory($priceDirectory);
            File::deleteDirectory($sourceDirectory);
        }
    }

    private function archive(MarketTrainingDataService $training, string $dataset, string $timeframe, array $rows): \App\Models\MarketTrainingArchive
    {
        $archive = $training->ensureArchive($dataset, 'dukascopy', 'XAUUSD', $timeframe,
            CarbonImmutable::parse($rows[0]['time'], 'UTC'), CarbonImmutable::parse($rows[array_key_last($rows)]['time'], 'UTC')->addHour());
        $training->upsertCandles($dataset, 'dukascopy', 'XAUUSD', $timeframe, $rows);
        $training->refreshCoverage($archive);
        $archive->update(['status' => 'complete']);
        return $archive->fresh();
    }

    private function rows(string $start, int $minutes, int $count): array
    {
        $from = CarbonImmutable::parse($start, 'UTC');
        return array_map(fn ($index) => $this->row($from->addMinutes($index * $minutes)->format('Y-m-d H:i:s')), range(0, $count - 1));
    }

    private function row(string $time): array
    {
        return ['time' => $time, 'open' => '4338.748000', 'high' => '4339.148000', 'low' => '4336.898000',
            'close' => '4337.198000', 'volume' => '0.034080'];
    }

    private function nativeProviderFixture(): array
    {
        return [[
            ['time'=>'2025-12-17 23:01:00','open'=>4339.53,'high'=>4339.953,'low'=>4336.795,'close'=>4339.953,'volume'=>0.00106],
            ['time'=>'2025-12-17 23:02:00','open'=>4336.725,'high'=>4340.248,'low'=>4336.725,'close'=>4339.498,'volume'=>0.01042],
            ['time'=>'2025-12-17 23:03:00','open'=>4339.498,'high'=>4339.898,'low'=>4337.385,'close'=>4339.398,'volume'=>0.0061],
            ['time'=>'2025-12-17 23:04:00','open'=>4339.298,'high'=>4339.298,'low'=>4338.548,'close'=>4338.648,'volume'=>0.00271],
        ], [['time'=>'2025-12-17 23:00:00','open'=>4339.53,'high'=>4340.248,'low'=>4336.725,'close'=>4338.648,'volume'=>0.02029]],
        ['source_hour_sha256'=>\FrozenM5GapRecoveryOperation::EXPECTED_TICK_HOUR_SHA256,'first_bucket_count'=>137,'whole_hour_count'=>4572,
            'minute_counts'=>['2025-12-17T23:01'=>6,'2025-12-17T23:02'=>64,'2025-12-17T23:03'=>45,'2025-12-17T23:04'=>22],
            'first_bucket_ohlc'=>['open'=>4339.53,'high'=>4340.248,'low'=>4336.725,'close'=>4338.648],
            'first_tick_utc'=>'2025-12-17T23:01:27.345Z','last_tick_utc'=>'2025-12-17T23:04:59.303Z']];
    }
}
