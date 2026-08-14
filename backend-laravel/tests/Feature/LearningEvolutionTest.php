<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabLearningMemory;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\LearningLaneService;
use App\Services\LearningMemoryService;
use App\Services\MicroReplayService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningEvolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_drawdown_learning_memory_uses_negative_delta_as_positive_utility(): void
    {
        [$agent, $map] = $this->agentAndMap(['minimum_confidence' => ['old' => .4, 'new' => .5]]);
        $pair = LabLearningLanePair::create([
            'pair_key' => str_repeat('a', 64), 'lab_generation_id' => $agent->lab_generation_id,
            'candidate_agent_id' => $agent->id, 'candidate_response_map_id' => $map->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'target' => 'drawdown_risk', 'specialist_role' => 'risk_specialist', 'status' => 'screen_paired',
            'candidate_metrics' => ['max_drawdown_percent' => 4], 'control_metrics' => ['max_drawdown_percent' => 10],
            'target_delta' => ['baseline' => 10, 'observed' => 4, 'delta' => -6, 'improved' => true],
            'failure_signature' => ['signature' => 'risk_failure'], 'metadata' => ['same_execution_contract' => true],
        ]);
        app(LearningLaneService::class)->status('XAUUSD', 'H1');
        $memory = LabLearningMemory::first();
        $this->assertNotNull($memory);
        $this->assertSame('positive', $memory->memory_type);
        $this->assertGreaterThan(0, $memory->score);
    }

    public function test_micro_replay_requires_three_windows_and_blocks_hard_failure(): void
    {
        [$agent, $map] = $this->agentAndMap();
        $pair = LabLearningLanePair::create([
            'pair_key' => str_repeat('b', 64), 'lab_generation_id' => $agent->lab_generation_id,
            'candidate_agent_id' => $agent->id, 'candidate_response_map_id' => $map->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'target' => 'profit_factor', 'status' => 'screen_paired',
            'candidate_metrics' => ['screening_survival' => ['temporal_chunk_survival' => [
                'window_profit_factors' => [1.2, 1.1, .7], 'window_scores' => [1, 1, 0],
            ]]], 'control_metrics' => ['profit_factor' => 1],
            'target_delta' => ['delta' => .1, 'improved' => true], 'failure_signature' => ['signature' => 'pf'],
        ]);
        $result = app(MicroReplayService::class)->assessPair($pair);
        $this->assertSame('failed', $result['status']);
        $this->assertSame(2, $result['positive_windows']);
        $this->assertSame(1, $result['hard_failures']);
    }

    public function test_micro_failure_is_recorded_as_negative_learning_evidence(): void
    {
        [$agent, $map] = $this->agentAndMap();
        $pair = LabLearningLanePair::create([
            'pair_key' => str_repeat('d', 64), 'lab_generation_id' => $agent->lab_generation_id,
            'candidate_agent_id' => $agent->id, 'candidate_response_map_id' => $map->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'target' => 'profit_factor', 'status' => 'micro_failed',
            'candidate_metrics' => [], 'control_metrics' => ['profit_factor' => 1],
            'target_delta' => ['delta' => .2, 'improved' => true],
            'failure_signature' => ['signature' => 'micro_pf_failure'],
            'metadata' => ['micro_replay' => ['status' => 'failed', 'score' => .33]],
        ]);

        app(LearningLaneService::class)->status('XAUUSD', 'H1');
        $memory = LabLearningMemory::first();

        $this->assertNotNull($memory);
        $this->assertSame('negative', $memory->memory_type);
        $this->assertSame(1, $memory->failure_count);
        $this->assertLessThan(0, $memory->score);
    }

    /** @return array{0:LabAgent,1:LabMutationResponseMap} */
    private function agentAndMap(array $diff = ['minimum_confidence' => ['old' => .4, 'new' => .5]]): array
    {
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'name' => 'Evolution test', 'timeframe' => 'H1', 'strategy_families' => ['differential_router'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test', 'population_size' => 1, 'status' => 'screened', 'trigger_context' => []]);
        $model = ModelVersion::create(['name' => 'evolution-test', 'strategy' => 'xauusd_test', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => app(StrategyParameterSchemaService::class)->defaults('differential_router'), 'metadata' => ['generation_target' => 'profit_factor'], 'evidence_status' => 'valid']);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router', 'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => $diff])->fresh(['modelVersion', 'generation']);
        $map = LabMutationResponseMap::create(['response_key' => str_repeat('c', 64).$agent->id, 'stage' => 'screening', 'status' => 'screen_observed', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router', 'target' => 'profit_factor', 'parameter_key' => array_key_first($diff), 'lab_agent_id' => $agent->id, 'evidence_run_id' => 'evolution-'.$agent->id, 'metadata' => ['single_gene' => true, 'causal_credit_eligible' => true]]);
        return [$agent, $map];
    }
}
