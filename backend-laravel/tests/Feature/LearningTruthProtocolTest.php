<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\CanonicalLearningOutbox;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLaneDispatch;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\LabEvaluationRun;
use App\Models\ModelVersion;
use App\Services\CanonicalLearningOutboxService;
use App\Services\LearningRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningTruthProtocolTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_pair_is_diagnostic_only_and_cannot_create_a_canonical_outbox_item(): void
    {
        [$agent, $pair] = $this->pair(false);
        $result = app(CanonicalLearningOutboxService::class)->record($agent, $pair, ['evidence_run_id' => 'truth-invalid', 'total_trades' => 1], true, ['improved' => true]);

        $this->assertSame('pair_unverified', $result['status']);
        $this->assertDatabaseHas('lab_learning_lane_pairs', ['id' => $pair->id, 'status' => 'diagnostic_only']);
        $this->assertDatabaseCount('canonical_learning_outbox', 0);
    }

    public function test_canonical_settlement_completes_dispatch_and_zero_trades_remain_insufficient_evidence(): void
    {
        [$agent, $pair] = $this->pair(true);
        LabLearningLaneDispatch::create(['dispatch_key' => 'truth-dispatch', 'pair_id' => $pair->id, 'lab_generation_id' => $pair->lab_generation_id, 'lab_agent_id' => $agent->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'status' => 'running', 'stage' => 'full_replay']);
        $result = app(CanonicalLearningOutboxService::class)->record($agent, $pair, ['evidence_run_id' => 'truth-valid', 'total_trades' => 0, 'opportunity_recall_failure' => true], false, ['improved' => false]);

        $this->assertSame('completed', $result['status']);
        $this->assertDatabaseHas('canonical_learning_outbox', ['status' => 'completed']);
        $this->assertDatabaseHas('lab_learning_lane_dispatches', ['status' => 'canonical_settled']);
        $this->assertDatabaseHas('agent_learning_settlements', ['evidence_state' => 'insufficient_evidence']);
        $this->assertSame('execution_admission_starvation', data_get($pair->fresh()->metadata, 'targeted_repair_lane.classification'));
    }

    public function test_missing_metrics_are_insufficient_evidence_not_zero_loss(): void
    {
        $reward = app(LearningRewardService::class)->score(['total_trades' => 0]);
        $this->assertSame('insufficient_evidence', $reward['evidence_state']);
        $this->assertContains('INSUFFICIENT_ACTIVITY', $reward['insufficient_reasons']);
    }

    /** @return array{LabAgent,LabLearningLanePair} */
    private function pair(bool $valid): array
    {
        $lab = AiLaboratory::create(['name' => 'Truth protocol XAUUSD H1', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test', 'status' => 'screened']);
        $candidateModel = ModelVersion::create(['name' => 'truth-candidate-'.$valid, 'strategy' => 'hybrid', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $controlModel = ModelVersion::create(['name' => 'truth-control-'.$valid, 'strategy' => 'hybrid', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $candidate = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $candidateModel->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => ['entry' => ['old' => 1, 'new' => 2]]]);
        $control = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $controlModel->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => []]);
        $data = str_repeat('a', 64); $execution = str_repeat('b', 64);
        $candidateMap = LabMutationResponseMap::create(['response_key' => 'truth-candidate-'.$valid, 'stage' => 'screening', 'status' => 'screen_observed', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'lab_agent_id' => $candidate->id, 'observed_metrics' => [], 'metadata' => ['data_manifest_hash' => $data, 'execution_hash' => $execution]]);
        $controlMap = LabMutationResponseMap::create(['response_key' => 'truth-control-'.$valid, 'stage' => 'screening', 'status' => 'control', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'lab_agent_id' => $control->id, 'observed_metrics' => ['profit_factor' => 1], 'metadata' => ['control_contract' => ['protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control', 'generation_id' => $generation->id, 'data_hash' => $data, 'execution_hash' => $execution]]]);
        $pair = LabLearningLanePair::create(['pair_key' => 'truth-pair-'.$valid, 'lab_generation_id' => $generation->id, 'candidate_agent_id' => $candidate->id, 'control_agent_id' => $control->id, 'candidate_response_map_id' => $candidateMap->id, 'control_response_map_id' => $controlMap->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'status' => 'learning_observed', 'pair_integrity_status' => $valid ? 'verified' : 'diagnostic_only', 'same_generation' => $valid, 'candidate_data_hash' => $data, 'control_data_hash' => $data, 'candidate_execution_hash' => $execution, 'control_execution_hash' => $execution, 'candidate_metrics' => ['profit_factor' => 1], 'control_metrics' => ['profit_factor' => 1], 'metadata' => ['promotion_evidence' => false]]);
        if ($valid) {
            LabEvaluationRun::create(['run_id' => 'truth-valid', 'lab_generation_id' => $generation->id, 'lab_agent_id' => $candidate->id, 'model_version_id' => $candidate->model_version_id, 'phase' => 'full_validation', 'status' => 'completed', 'metrics' => ['total_trades' => 0]]);
            LabEvaluationRun::create(['run_id' => 'truth-control', 'lab_generation_id' => $generation->id, 'lab_agent_id' => $control->id, 'model_version_id' => $control->model_version_id, 'phase' => 'screening', 'status' => 'completed', 'metrics' => ['total_trades' => 1]]);
            $pair->update(['candidate_evidence_run_id' => 'truth-valid', 'control_evidence_run_id' => 'truth-control']);
        }

        return [$candidate, $pair];
    }
}
