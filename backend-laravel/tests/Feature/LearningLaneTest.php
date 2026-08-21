<?php

namespace Tests\Feature;

use App\Models\AgentLearningLesson;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\LearningLaneService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningLaneTest extends TestCase
{
    use RefreshDatabase;

    public function test_screen_observation_is_paired_with_same_generation_control_without_promotion_credit(): void
    {
        [$candidate, $control] = $this->agents();
        $controlMap = LabMutationResponseMap::create([
            'response_key' => str_repeat('a', 64),
            'stage' => 'screening',
            'status' => 'control',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'differential_router',
            'target' => 'profit_factor',
            'lab_agent_id' => $control->id,
            'evidence_run_id' => 'control-run-1',
            'observed_metrics' => ['profit_factor' => 1.0, 'total_trades' => 40],
            'metadata' => [
                'screening_decision' => 'passed',
                'execution_hash' => str_repeat('e', 64),
                'data_manifest_hash' => str_repeat('d', 64),
                'control_contract' => ['protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control', 'generation_id' => $control->lab_generation_id, 'data_hash' => str_repeat('d', 64), 'execution_hash' => str_repeat('e', 64)],
            ],
        ]);
        $candidateMap = LabMutationResponseMap::create([
            'response_key' => str_repeat('b', 64),
            'stage' => 'screening',
            'status' => 'screen_observed',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'differential_router',
            'target' => 'profit_factor',
            'parameter_key' => 'minimum_confidence',
            'direction' => 'increase',
            'lab_agent_id' => $candidate->id,
            'evidence_run_id' => 'candidate-run-1',
            'old_value' => ['value' => .9],
            'new_value' => ['value' => 1.0],
            'observed_metrics' => ['profit_factor' => 1.25, 'total_trades' => 40],
            'metadata' => [
                'screening_decision' => 'failed',
                'execution_hash' => str_repeat('e', 64),
                'data_manifest_hash' => str_repeat('d', 64),
            ],
        ]);

        $pair = app(LearningLaneService::class)->pairScreeningObservation(
            $candidate,
            ['evidence_run_id' => 'candidate-run-1'],
            $candidateMap->toArray(),
        );

        $this->assertNotNull($pair);
        $this->assertSame('control', $pair['baseline_source']);
        $this->assertSame('screen_paired', $pair['status']);
        $this->assertTrue((bool) data_get($pair, 'target_delta.improved'));
        $this->assertSame($controlMap->id, $pair['control_response_map_id']);
        $this->assertSame(0, AgentLearningLesson::count(), 'A non-complete test run cannot create learning credit.');
        $this->assertFalse((bool) data_get($candidate->fresh('modelVersion')->modelVersion->metadata, 'learning_lane.promotion_evidence', false));
    }

    public function test_provisional_skill_is_role_scoped_and_can_be_used_only_as_one_research_probe(): void
    {
        [$candidate, $control] = $this->agents();
        $executionHash = str_repeat('e', 64);
        $dataHash = str_repeat('d', 64);
        $controlMap = LabMutationResponseMap::create([
            'response_key' => str_repeat('1', 64), 'stage' => 'screening', 'status' => 'control',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'target' => 'profit_factor', 'lab_agent_id' => $control->id,
            'evidence_run_id' => 'provisional-control-run',
            'observed_metrics' => ['profit_factor' => 1.0],
            'metadata' => ['execution_hash' => $executionHash, 'data_manifest_hash' => $dataHash, 'control_contract' => ['protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control', 'generation_id' => $control->lab_generation_id, 'data_hash' => $dataHash, 'execution_hash' => $executionHash]],
        ]);
        $candidateMap = LabMutationResponseMap::create([
            'response_key' => str_repeat('2', 64), 'stage' => 'screening', 'status' => 'screen_observed',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'target' => 'profit_factor', 'lab_agent_id' => $candidate->id,
            'evidence_run_id' => 'provisional-candidate-run',
            'parameter_key' => 'minimum_confidence', 'direction' => 'increase',
            'observed_metrics' => ['profit_factor' => 1.2],
            'metadata' => [
                'execution_hash' => $executionHash, 'data_manifest_hash' => $dataHash,
                'causal_credit_eligible' => true,
            ],
        ]);
        $pair = LabLearningLanePair::create([
            'pair_key' => str_repeat('3', 64), 'lab_generation_id' => $candidate->lab_generation_id,
            'candidate_agent_id' => $candidate->id, 'control_agent_id' => $control->id,
            'candidate_response_map_id' => $candidateMap->id, 'control_response_map_id' => $controlMap->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'target' => 'profit_factor', 'specialist_role' => 'edge_quality_specialist',
            'baseline_source' => 'control', 'status' => 'provisional',
            'candidate_data_hash' => $dataHash, 'control_data_hash' => $dataHash,
            'candidate_execution_hash' => $executionHash, 'control_execution_hash' => $executionHash,
            'pair_integrity_status' => 'verified', 'same_generation' => true,
            'candidate_metrics' => ['profit_factor' => 1.2], 'control_metrics' => ['profit_factor' => 1.0],
            'target_delta' => ['delta' => .2, 'improved' => true],
            'metadata' => ['same_snapshot' => true, 'same_execution_contract' => true],
        ]);
        AgentLearningLesson::create([
            'lesson_id' => '00000000-0000-0000-0000-000000000001',
            'lesson_hash' => str_repeat('c', 128),
            'lab_agent_id' => $candidate->id,
            'model_version_id' => $candidate->model_version_id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'differential_router',
            'lesson_type' => 'skill_lesson',
            'status' => 'provisional',
            'failure_class' => 'profit_factor',
            'parameter_key' => 'minimum_confidence',
            'outcome' => 'beneficial',
            'evidence' => [
                'specialist_role' => 'edge_quality_specialist',
                'pair_id' => $pair->id,
                'direction' => 'increase',
                'target_delta' => ['delta' => .25, 'improved' => true],
                'promotion_evidence' => false,
            ],
            'source_run_ids' => ['run-provisional-1'],
            'observed_at' => now(),
        ]);

        $skill = app(LearningLaneService::class)->bestProvisionalFor(
            'XAUUSD', 'H1', 'differential_router', 'profit_factor', 'edge_quality_specialist',
        );
        $otherRole = app(LearningLaneService::class)->bestProvisionalFor(
            'XAUUSD', 'H1', 'differential_router', 'profit_factor', 'stress_specialist',
        );

        $this->assertSame('minimum_confidence', $skill['parameter_key']);
        $this->assertTrue($skill['research_only']);
        $this->assertFalse($skill['promotion_evidence']);
        $this->assertNull($otherRole);
    }

    public function test_baseline_without_contract_matched_control_stays_missing_control(): void
    {
        [$candidate] = $this->agents();
        $candidateMap = LabMutationResponseMap::create([
            'response_key' => str_repeat('f', 64),
            'stage' => 'screening',
            'status' => 'screen_observed',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'differential_router',
            'target' => 'profit_factor',
            'parameter_key' => 'minimum_confidence',
            'lab_agent_id' => $candidate->id,
            'evidence_run_id' => 'candidate-without-control',
            'baseline_metrics' => ['profit_factor' => 1.0],
            'observed_metrics' => ['profit_factor' => 1.25],
            'metadata' => ['screening_decision' => 'failed'],
        ]);

        $pair = app(LearningLaneService::class)->pairScreeningObservation(
            $candidate,
            ['evidence_run_id' => 'candidate-without-control'],
            $candidateMap->toArray(),
        );

        $this->assertNotNull($pair);
        $this->assertSame('missing_control', $pair['status']);
        $this->assertNull($pair['control_response_map_id']);
        $this->assertFalse((bool) data_get($pair, 'target_delta.improved'));
        $this->assertTrue((bool) data_get($pair, 'metadata.baseline_is_diagnostic_only'));
    }

    /** @return array{0:LabAgent,1:LabAgent} */
    private function agents(): array
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Learning lane test lab', 'timeframe' => 'H1',
            'strategy_families' => ['differential_router'], 'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 2, 'status' => 'screened', 'trigger_context' => [],
        ]);
        $schema = app(StrategyParameterSchemaService::class);
        $parameters = $schema->defaults('differential_router');
        $make = function (string $name, array $metadata) use ($generation, $parameters): LabAgent {
            $model = ModelVersion::create([
                'name' => $name, 'strategy' => 'xauusd_'.$name, 'version' => 'v1',
                'generation' => 1, 'status' => 'testing', 'parameters' => $parameters,
                'metadata' => $metadata, 'evidence_status' => 'valid',
            ]);

            return LabAgent::create([
                'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
                'origin' => 'test', 'lifecycle_status' => 'screened',
                'parameter_diff' => ['minimum_confidence' => ['old' => .9, 'new' => 1.0]],
            ])->fresh(['modelVersion', 'generation']);
        };
        $candidate = $make('learning-candidate', [
            'generation_target' => 'profit_factor',
            'council_specialist_contract' => ['role' => 'edge_quality_specialist'],
        ]);
        $control = $make('learning-control', [
            'generation_target' => 'profit_factor',
            'causal_experiment_lane' => ['control_only' => true],
        ]);

        return [$candidate, $control];
    }
}
