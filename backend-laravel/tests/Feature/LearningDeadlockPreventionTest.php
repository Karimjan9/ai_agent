<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabFailureDojoRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\FailureDojoService;
use App\Services\FrozenControlScreeningAdmissionService;
use App\Services\GenerationSnapshotAdmissionService;
use App\Services\MultiTimeframeSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LearningDeadlockPreventionTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_pair_creates_diagnostic_dojo_not_actionable_backlog(): void
    {
        [, $candidate] = $this->agents();
        $pair = LabLearningLanePair::create([
            'pair_key' => 'invalid-dojo-pair', 'lab_generation_id' => $candidate->lab_generation_id,
            'candidate_agent_id' => $candidate->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'status' => 'missing_control',
            'failure_signature' => ['signature' => 'invalid-control'], 'candidate_metrics' => ['profit_factor' => .9],
        ]);

        app(FailureDojoService::class)->recordPair($pair);

        $this->assertDatabaseHas('lab_failure_dojo_runs', ['pair_id' => $pair->id, 'status' => 'diagnostic_only']);
        $summary = app(FailureDojoService::class)->summary('XAUUSD', 'H1');
        $this->assertSame(0, $summary['actionable_pending']);
        $this->assertSame(0, LabFailureDojoRun::query()->where('status', 'pending')->count());
    }

    public function test_candidate_waits_for_completed_frozen_control_then_becomes_admissible(): void
    {
        [$control, $candidate] = $this->agents();
        $admission = app(FrozenControlScreeningAdmissionService::class);

        $this->assertSame('waiting', $admission->admission($candidate)['status']);

        $dataHash = str_repeat('a', 64);
        $executionHash = str_repeat('b', 64);
        LabEvaluationRun::create([
            'run_id' => 'control-screen-run', 'lab_generation_id' => $control->lab_generation_id,
            'lab_agent_id' => $control->id, 'model_version_id' => $control->model_version_id,
            'phase' => 'screening', 'mode' => 'screen', 'status' => 'completed',
        ]);
        LabMutationResponseMap::create([
            'response_key' => 'control-screen-map', 'stage' => 'screening', 'status' => 'control',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'lab_agent_id' => $control->id, 'observed_metrics' => ['profit_factor' => 1],
            'metadata' => ['control_contract' => [
                'protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control',
                'generation_id' => $control->lab_generation_id, 'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
            ]],
        ]);

        $this->assertSame('ready', $admission->admission($candidate)['status']);
    }

    public function test_candidate_uses_its_sealed_control_id_when_family_has_multiple_controls(): void
    {
        [$control, $candidate] = $this->agents();
        $metadata = (array) $candidate->modelVersion->metadata;
        $metadata['control_pair_contract'] = [
            'protocol' => 'exact_frozen_control_pair_v2',
            'control_agent_id' => $control->id,
        ];
        $candidate->modelVersion->update(['metadata' => $metadata]);

        $decoyModel = ModelVersion::create([
            'name' => 'decoy-control', 'strategy' => 'hybrid', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => [], 'metadata' => [
                'control_contract' => [
                    'protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control',
                    'generation_id' => $control->lab_generation_id,
                ],
            ],
        ]);
        LabAgent::create([
            'lab_generation_id' => $control->lab_generation_id, 'model_version_id' => $decoyModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'queued', 'parameter_diff' => [],
        ]);
        LabEvaluationRun::create([
            'run_id' => 'exact-control-screen-run', 'lab_generation_id' => $control->lab_generation_id,
            'lab_agent_id' => $control->id, 'model_version_id' => $control->model_version_id,
            'phase' => 'screening', 'mode' => 'screen', 'status' => 'completed',
        ]);
        LabMutationResponseMap::create([
            'response_key' => 'exact-control-screen-map', 'stage' => 'screening', 'status' => 'control',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'lab_agent_id' => $control->id, 'observed_metrics' => ['profit_factor' => 1],
            'metadata' => ['control_contract' => [
                'protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control',
                'generation_id' => $control->lab_generation_id,
                'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
            ]],
        ]);

        $result = app(FrozenControlScreeningAdmissionService::class)
            ->admission($candidate->fresh('modelVersion'));

        $this->assertSame('ready', $result['status']);
        $this->assertSame('FROZEN_CONTROL_REPLAY_COMPLETED', $result['reason']);
    }

    public function test_generation_without_price_snapshot_is_fail_closed_at_admission(): void
    {
        [$control] = $this->agents();

        $result = app(GenerationSnapshotAdmissionService::class)->inspect($control->generation);

        $this->assertFalse($result['allowed']);
        $this->assertContains('GENERATION_PRICE_SNAPSHOT_PATH_MISSING', $result['reasons']);
        $this->assertContains('GENERATION_PRICE_SNAPSHOT_HASH_MISSING', $result['reasons']);
    }

    public function test_volume_specialist_cannot_enter_queue_without_frozen_volume_snapshot(): void
    {
        [$control, $candidate] = $this->agents();
        $candidate->modelVersion->update([
            'parameters' => ['volume_lane' => 'breakout_volume_confirmation'],
        ]);
        $path = storage_path('app/snapshot-admission-'.uniqid('', true).'.csv');
        File::put($path, "time,open,high,low,close,volume\n2025-01-01T00:00:00Z,1,1,1,1,0\n");
        $hash = hash_file('sha256', $path);
        $generation = $control->generation;
        $generation->update(['trigger_context' => [
            'canonical_dataset_snapshots' => [
                'price' => [
                    'path' => $path,
                    'sha256' => $hash,
                    'manifest' => ['snapshot_sha256' => $hash],
                ],
            ],
        ]]);

        try {
            $result = app(GenerationSnapshotAdmissionService::class)
                ->inspect($generation->fresh(['agents.modelVersion']));

            $this->assertFalse($result['allowed']);
            $this->assertContains('GENERATION_VOLUME_SNAPSHOT_PATH_MISSING', $result['reasons']);
            $this->assertContains('GENERATION_VOLUME_SNAPSHOT_HASH_MISSING', $result['reasons']);
        } finally {
            File::delete($path);
        }
    }

    public function test_xauusd_generation_is_admitted_only_with_the_complete_frozen_mtf_bundle(): void
    {
        [$control] = $this->agents();
        $paths = [];
        foreach (['PRICE', 'M5', 'H4', 'H1', 'M15'] as $timeframe) {
            $path = storage_path('app/mtf-admission-'.strtolower($timeframe).'-'.uniqid('', true).'.csv');
            File::put($path, "time,open,high,low,close,volume\n2025-01-01T00:00:00Z,1,2,0,1,10\n");
            $paths[$timeframe] = $path;
        }
        $priceHash = hash_file('sha256', $paths['PRICE']);
        $bundleHash = str_repeat('c', 64);
        $streams = [];
        foreach (['M5', 'H4', 'H1', 'M15'] as $timeframe) {
            $streams[$timeframe] = [
                'path' => $paths[$timeframe],
                'sha256' => hash_file('sha256', $paths[$timeframe]),
            ];
        }
        $generation = $control->generation;
        $generation->update(['trigger_context' => [
            'canonical_dataset_snapshots' => [
                'price' => [
                    'path' => $paths['PRICE'], 'sha256' => $priceHash,
                    'manifest' => ['snapshot_sha256' => $priceHash],
                ],
            ],
            'mtf_bundle_hash' => $bundleHash,
            'mtf_bundle_manifest' => [
                'protocol' => MultiTimeframeSnapshotService::PROTOCOL,
                'validation_bundle_protocol' => 'agent_owned_mtf_foundation_bundle_v1',
                'bundle_hash' => $bundleHash,
                'streams' => $streams,
            ],
        ]]);

        try {
            $result = app(GenerationSnapshotAdmissionService::class)
                ->inspect($generation->fresh(['agents.modelVersion', 'laboratory']));

            $this->assertTrue($result['allowed'], implode(',', $result['reasons']));
        } finally {
            File::delete(array_values($paths));
        }
    }

    /** @return array{0:LabAgent,1:LabAgent} */
    private function agents(): array
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Deadlock prevention', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 2, 'status' => 'draft', 'trigger_context' => [],
        ]);
        $execution = ['protocol' => 'canonical_market_execution_v1'];
        $controlModel = ModelVersion::create([
            'name' => 'control', 'strategy' => 'hybrid', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => [], 'metadata' => [
                'execution_contract' => $execution,
                'control_contract' => ['protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control', 'generation_id' => $generation->id],
            ],
        ]);
        $candidateModel = ModelVersion::create([
            'name' => 'candidate', 'strategy' => 'hybrid', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => [], 'metadata' => ['execution_contract' => $execution],
        ]);
        $control = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $controlModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'queued', 'parameter_diff' => [],
        ]);
        $candidate = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $candidateModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'queued', 'parameter_diff' => ['entry' => ['old' => 1, 'new' => 2]],
        ]);

        return [$control->fresh(['modelVersion', 'generation']), $candidate->fresh(['modelVersion', 'generation'])];
    }
}
