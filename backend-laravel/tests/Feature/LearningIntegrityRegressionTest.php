<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\LearningLaneService;
use App\Services\LearningVelocityGateService;
use App\Services\MutationResponseMapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningIntegrityRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_constructor_control_only_always_records_control_response_map(): void
    {
        [$lab, $generation] = $this->scope();
        $model = ModelVersion::create([
            'name' => 'constructor-control', 'strategy' => 'constructor-control', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [],
            'metadata' => ['mutation_constructor_invariant' => ['control_only' => true]],
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => [],
        ]);

        $map = app(MutationResponseMapService::class)->recordScreening($agent, [
            'evidence_run_id' => 'control-evidence-1', 'screen_decision' => 'passed',
            'data_manifest' => ['sha256' => str_repeat('d', 64)],
            'execution_contract' => ['execution_hash' => str_repeat('e', 64)],
            'profit_factor' => 1.0,
        ]);

        $this->assertSame('control', $map['status']);
        $this->assertDatabaseHas('lab_mutation_response_maps', ['id' => $map['id'], 'status' => 'control']);
    }

    public function test_old_pair_without_exact_hash_contract_is_not_learning_paired(): void
    {
        [$lab, $generation] = $this->scope();
        $candidateModel = ModelVersion::create(['name' => 'legacy-candidate', 'strategy' => 'legacy-candidate', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $controlModel = ModelVersion::create(['name' => 'legacy-control', 'strategy' => 'legacy-control', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $candidate = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $candidateModel->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => ['x' => ['old' => 1, 'new' => 2]]]);
        $control = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $controlModel->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => []]);
        $candidateMap = LabMutationResponseMap::create(['response_key' => 'legacy-candidate-map', 'stage' => 'screening', 'status' => 'screen_observed', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'lab_agent_id' => $candidate->id, 'observed_metrics' => ['profit_factor' => 1.2]]);
        $controlMap = LabMutationResponseMap::create(['response_key' => 'legacy-control-map', 'stage' => 'screening', 'status' => 'control', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'lab_agent_id' => $control->id, 'observed_metrics' => ['profit_factor' => 1.0]]);
        LabLearningLanePair::create(['pair_key' => 'legacy-pair', 'lab_generation_id' => $generation->id, 'candidate_agent_id' => $candidate->id, 'control_agent_id' => $control->id, 'candidate_response_map_id' => $candidateMap->id, 'control_response_map_id' => $controlMap->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'status' => 'screen_paired', 'candidate_metrics' => ['profit_factor' => 1.2], 'control_metrics' => ['profit_factor' => 1.0], 'metadata' => ['same_snapshot' => true, 'same_execution_contract' => true]]);

        $status = app(LearningLaneService::class)->status('XAUUSD', 'H1');

        $this->assertSame(0, $status['paired']);
        $this->assertGreaterThan(0, $status['missing_control']);
    }

    public function test_learning_velocity_blocks_recent_generations_without_learning_evidence(): void
    {
        [$lab, $generation] = $this->scope();
        $generation->update(['status' => 'screened']);
        $model = ModelVersion::create(['name' => 'no-evidence-model', 'strategy' => 'no-evidence-model', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => []]);

        $summary = app(LearningVelocityGateService::class)->summary('XAUUSD', 'H1');

        $this->assertFalse($summary['allowed']);
        $this->assertSame('learning_starvation', $summary['status']);
        $this->assertTrue($summary['learning_starvation']['starved']);
    }

    public function test_model_version_status_follows_agent_lifecycle(): void
    {
        [$lab, $generation] = $this->scope();
        $model = ModelVersion::create(['name' => 'lifecycle-model', 'strategy' => 'lifecycle-model', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => []]);
        $agent->update(['lifecycle_status' => 'champion']);

        $this->assertSame('active', $model->fresh()->status);
        $this->assertSame('model_version_lifecycle_sync_v1', data_get($model->fresh()->metadata, 'lifecycle_sync.protocol'));
    }

    /** @return array{0:AiLaboratory,1:LabGeneration} */
    private function scope(): array
    {
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'name' => 'Integrity test lab', 'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test', 'population_size' => 1, 'status' => 'screened', 'trigger_context' => []]);
        return [$lab, $generation];
    }
}
