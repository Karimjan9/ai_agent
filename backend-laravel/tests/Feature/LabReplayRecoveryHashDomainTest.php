<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\LabDatasetExportService;
use App\Services\LabReplayRecoveryService;
use App\Services\MultiTimeframeSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class LabReplayRecoveryHashDomainTest extends TestCase
{
    use RefreshDatabase;

    private array $testDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->testDirectories as $directory) File::deleteDirectory($directory);
        parent::tearDown();
    }

    public function test_mtf_screen_recovery_preserves_all_hash_domains_and_does_not_rewrite_old_attempt(): void
    {
        [$agent, $run, $bundle, $snapshots] = $this->fixture('screen');
        $before = $run->fresh()->getAttributes();
        $contract = app(LabReplayRecoveryService::class)->prepare($agent, 'screen');
        $this->assertSame($bundle['bundle_hash'], $contract['dataset_hashes']['mtf_bundle']);
        $this->assertSame($snapshots['foundation']['sha256'], $contract['dataset_hashes']['foundation']);
        $this->assertNotSame($contract['dataset_hashes']['foundation'], $contract['dataset_hashes']['mtf_bundle']);
        $this->assertArrayNotHasKey('prior_run_dataset_contract_repair', $contract);
        app(LabReplayRecoveryService::class)->assertContract($agent->fresh(), $contract);
        $this->assertSame($before, $run->fresh()->getAttributes());
        $this->assertDatabaseCount('lab_evaluation_runs', 1);
    }

    public function test_mtf_full_recovery_compares_paper_foundation_and_bundle_separately(): void
    {
        [$agent, , $bundle, $snapshots] = $this->fixture('full');
        $contract = app(LabReplayRecoveryService::class)->prepare($agent, 'full');
        $this->assertSame($snapshots['price']['sha256'], $contract['dataset_hashes']['price']);
        $this->assertSame($snapshots['foundation']['sha256'], $contract['dataset_hashes']['foundation']);
        $this->assertSame($bundle['bundle_hash'], $contract['dataset_hashes']['mtf_bundle']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('datasetRepairFlagCases')]
    public function test_a_prior_actual_bundle_change_is_still_rejected(bool $allowRepair): void
    {
        [$agent, $run] = $this->fixture('screen');
        $meta = $run->request_meta;
        data_set($meta, 'dataset_manifest.mtf_bundle_hash', str_repeat('e', 64));
        data_set($meta, 'dataset_manifest.snapshot_sha256', str_repeat('e', 64));
        data_set($meta, 'payload.replay_dataset_hash', str_repeat('e', 64));
        data_set($meta, 'payload.policy_context.snapshot_transport.mtf_bundle_hash', str_repeat('e', 64));
        $run->update(['request_meta' => $meta, 'data_hash' => str_repeat('e', 64)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RECOVERY_PRIOR_DATASET_HASH_MISMATCH:mtf_bundle');
        app(LabReplayRecoveryService::class)->prepare($agent, 'screen', $allowRepair);
    }

    public static function datasetRepairFlagCases(): array
    {
        return ['ordinary recovery' => [false], 'explicit legacy repair cannot move MTF' => [true]];
    }

    public function test_copied_bundle_labels_cannot_hide_a_changed_m5_transport_hash(): void
    {
        [$agent, $run] = $this->fixture('screen');
        $meta = $run->request_meta;
        data_set($meta, 'payload.policy_context.snapshot_transport.training_dataset_sha256', str_repeat('e', 64));
        $run->update(['request_meta' => $meta]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RECOVERY_PRIOR_DATASET_IDENTITY_AMBIGUOUS:mtf_transport');
        app(LabReplayRecoveryService::class)->prepare($agent, 'screen', true);
    }

    public function test_same_aggregate_with_different_prior_streams_is_rejected(): void
    {
        [$agent, $run] = $this->fixture('screen');
        $meta = $run->request_meta;
        data_set($meta, 'dataset_manifest.mtf_bundle_manifest.streams.H1.sha256', str_repeat('e', 64));
        $run->update(['request_meta' => $meta]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RECOVERY_PRIOR_DATASET_HASH_MISMATCH:mtf_H1');
        app(LabReplayRecoveryService::class)->prepare($agent, 'screen');
    }

    public function test_tampered_actual_stream_cannot_pass_a_queued_recovery_contract(): void
    {
        [$agent, , $bundle] = $this->fixture('screen');
        $contract = app(LabReplayRecoveryService::class)->prepare($agent, 'screen');
        File::append($bundle['streams']['M15']['path'], "changed\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MTF agent validation resume integrity mismatch');
        app(LabReplayRecoveryService::class)->assertContract($agent->fresh(), $contract);
    }

    public function test_inconsistent_prior_primary_identity_is_not_repaired_as_a_hash_domain_alias(): void
    {
        [$agent, $run] = $this->fixture('screen');
        $run->update(['data_hash' => str_repeat('e', 64)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RECOVERY_PRIOR_DATASET_IDENTITY_AMBIGUOUS:mtf_bundle');
        app(LabReplayRecoveryService::class)->prepare($agent, 'screen', true);
    }

    public function test_missing_mtf_identity_in_a_queued_contract_is_rejected(): void
    {
        [$agent] = $this->fixture('screen');
        $contract = app(LabReplayRecoveryService::class)->prepare($agent, 'screen');
        unset($contract['dataset_hashes']['mtf_bundle']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RECOVERY_DATASET_SNAPSHOT_HASH_MISMATCH:mtf_bundle');
        app(LabReplayRecoveryService::class)->assertContract($agent->fresh(), $contract);
    }

    public function test_missing_reopened_stream_cannot_pass_even_with_the_same_bundle_label(): void
    {
        [$agent, , $bundle] = $this->fixture('screen');
        $contract = app(LabReplayRecoveryService::class)->prepare($agent, 'screen');
        $disk = $bundle;
        unset($disk['streams']['M15']);
        File::put(dirname($bundle['streams']['M5']['path']).'/manifest.json', json_encode($disk, JSON_THROW_ON_ERROR));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RECOVERY_DATASET_SNAPSHOT_HASH_MISMATCH:mtf_M15');
        app(LabReplayRecoveryService::class)->assertContract($agent->fresh(), $contract);
    }

    public function test_full_replay_foundation_drift_is_not_hidden_by_a_matching_mtf_bundle(): void
    {
        [$agent, $run] = $this->fixture('full');
        $meta = $run->request_meta;
        data_set($meta, 'dataset_manifest.sha256', str_repeat('e', 64));
        $run->update(['request_meta' => $meta]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RECOVERY_PRIOR_DATASET_HASH_MISMATCH:foundation');
        app(LabReplayRecoveryService::class)->prepare($agent, 'full');
    }

    private function fixture(string $mode): array
    {
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'name' => 'Recovery hash domain', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'test', 'population_size' => 1, 'status' => 'screening', 'trigger_context' => []]);
        $model = ModelVersion::create(['name' => 'domain control', 'strategy' => 'trend', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend', 'origin' => 'test',
            'lifecycle_status' => 'technical_quarantine', 'parameter_diff' => []]);
        $bundleHash = hash('sha256', 'test-only-recovery-'.bin2hex(random_bytes(16)));
        $directory = storage_path('app/lab-datasets/mtf/'.$bundleHash);
        $this->assertDirectoryDoesNotExist($directory);
        File::ensureDirectoryExists($directory);
        $this->testDirectories[] = $directory;
        $bundle = ['protocol' => MultiTimeframeSnapshotService::PROTOCOL, 'bundle_hash' => $bundleHash,
            'validation_bundle_protocol' => 'agent_owned_mtf_foundation_bundle_v1',
            'data_role' => 'pre_2026_foundation_training_only', 'promotion_evidence' => false,
            'bounded_cost_contract' => ['requested_m5_rows' => 200000], 'streams' => []];
        foreach (['M5', 'H4', 'H1', 'M15'] as $timeframe) {
            $path = $directory.'/'.strtolower($timeframe).'.csv';
            File::put($path, "time,open,high,low,close,volume\n2025-12-01T00:00:00Z,1,2,0,1,{$timeframe}\n");
            $bundle['streams'][$timeframe] = ['path' => $path, 'sha256' => hash_file('sha256', $path)];
        }
        File::put($directory.'/manifest.json', json_encode($bundle, JSON_THROW_ON_ERROR));
        $snapshots = [];
        foreach (['price', 'foundation'] as $name) {
            $path = $directory.'/'.$name.'.csv';
            File::put($path, "time,open,high,low,close,volume\n2025-12-01T00:00:00Z,1,2,0,1,{$name}\n");
            $sha = hash_file('sha256', $path);
            $snapshots[$name] = ['path' => $path, 'sha256' => $sha,
                'protocol' => 'test_frozen_csv_v1', 'manifest' => ['sha256' => $sha, 'snapshot_sha256' => $sha]];
        }
        $generation->update(['trigger_context' => ['canonical_dataset_snapshots' => $snapshots,
            'mtf_bundle_hash' => $bundleHash, 'mtf_bundle_manifest' => $bundle]]);
        // Export continuity mechanics are tested separately. These frozen
        // files are returned unchanged; the recovery owner and real MTF
        // restorer validate actual bytes here, with no dataset replacement.
        $this->mock(LabDatasetExportService::class, function ($mock) use ($snapshots) {
            $mock->shouldReceive('ensureGenerationSnapshot')->andReturn($snapshots['price']);
            $mock->shouldReceive('ensureGenerationFoundationSnapshot')->andReturn($snapshots['foundation']);
        });
        $manifest = ['snapshot_protocol' => MultiTimeframeSnapshotService::PROTOCOL,
            'snapshot_sha256' => $bundleHash, 'data_hash' => $bundleHash, 'mtf_bundle_hash' => $bundleHash];
        if ($mode === 'screen') {
            $manifest['data_partition'] = ['paper_snapshot_sha256' => $snapshots['price']['sha256']];
            $manifest['mtf_bundle_manifest'] = $bundle;
        } else {
            $manifest['sha256'] = $snapshots['foundation']['sha256'];
            $manifest['paper'] = $snapshots['price']['manifest'];
            $manifest['mtf_foundation_bundle'] = $bundle;
        }
        $payload = ['dataset_path' => $bundle['streams']['M5']['path'], 'replay_dataset_hash' => $bundleHash,
            'mtf_snapshot_manifest' => $bundle, 'policy_context' => ['snapshot_transport' => [
                'mtf_bundle_hash' => $bundleHash, 'training_dataset_sha256' => $bundle['streams']['M5']['sha256']]]];
        $run = LabEvaluationRun::create(['run_id' => 'test-domain-'.$mode.'-'.$agent->id,
            'lab_generation_id' => $generation->id, 'lab_agent_id' => $agent->id, 'model_version_id' => $model->id,
            'phase' => $mode === 'screen' ? 'screening' : 'full_validation', 'mode' => $mode, 'status' => 'technical_error',
            'data_hash' => $bundleHash, 'request_meta' => ['dataset_manifest' => $manifest, 'payload' => $payload],
            'metadata' => ['timeout_attempt_immutable' => true], 'error_message' => 'original timeout',
            'started_at' => now()->subMinute(), 'finished_at' => now()]);
        return [$agent->fresh(['generation', 'modelVersion']), $run, $bundle, $snapshots];
    }
}
