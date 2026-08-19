<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabCouncilAdjudication;
use App\Models\LabCouncilDisagreement;
use App\Models\LabGeneration;
use App\Models\LabEvaluationRun;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\CausalControlCohortPlanner;
use App\Services\CouncilAdjudicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BottleneckControlAndCouncilTest extends TestCase
{
    use RefreshDatabase;

    public function test_control_planner_records_exact_contract_without_pairing(): void
    {
        [$agent, $generation] = $this->agent();
        $map = LabMutationResponseMap::create([
            'response_key' => str_repeat('a', 64), 'stage' => 'screening', 'status' => 'screen_observed',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'target' => 'profit_factor', 'parameter_key' => 'minimum_confidence', 'lab_agent_id' => $agent->id,
            'evidence_run_id' => 'candidate-run', 'temporal_window_key' => 'window-1',
            'metadata' => [
                'data_manifest' => ['sha256' => str_repeat('d', 64)],
                'execution_contract_hash' => str_repeat('e', 64),
            ],
        ]);
        $pair = LabLearningLanePair::create([
            'pair_key' => str_repeat('b', 64), 'lab_generation_id' => $generation->id,
            'candidate_agent_id' => $agent->id, 'candidate_response_map_id' => $map->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'target' => 'profit_factor', 'specialist_role' => 'edge_quality_specialist',
            'status' => 'missing_control', 'independent_window_key' => 'window-1',
        ]);

        $result = app(CausalControlCohortPlanner::class)->plan('XAUUSD', 'H1', null, 50, true);

        $this->assertSame(1, $result['planned']);
        $this->assertSame(0, $result['blocked']);
        $this->assertSame('missing_control', $pair->fresh()->status);
        $this->assertDatabaseHas('lab_causal_control_plans', [
            'pair_id' => $pair->id,
            'dataset_hash' => str_repeat('d', 64),
            'execution_hash' => str_repeat('e', 64),
            'temporal_window_key' => 'window-1',
            'status' => 'planned',
        ]);
    }

    public function test_council_adjudication_resolves_disputed_row_only_to_wait(): void
    {
        $row = LabCouncilDisagreement::create([
            'event_key' => 'council-event-1', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'family' => 'hybrid', 'specialist_votes' => ['specialist' => ['m15_specialist' => 'BUY']],
            'risk_decision' => 'VETO', 'council_decision' => 'BUY', 'outcome_status' => 'unresolved',
            'evidence' => ['promotion_evidence' => false], 'promotion_evidence' => false,
        ]);
        LabEvaluationRun::create([
            'run_id' => 'sealed-run-1', 'phase' => 'council_adjudication', 'status' => 'completed',
            'response_hash' => str_repeat('f', 64), 'metadata' => ['promotion_evidence' => false],
        ]);

        $result = app(CouncilAdjudicationService::class)->adjudicate(
            $row,
            'BUY',
            'sealed-run-1',
            str_repeat('f', 64),
            ['window-a', 'window-b'],
            'operator',
            'sealed counterfactual reviewed',
        );

        $this->assertSame('WAIT', $result['decision']);
        $this->assertSame('resolved_wait', $row->fresh()->outcome_status);
        $this->assertSame(1, LabCouncilAdjudication::count());
        $this->assertFalse((bool) $row->fresh()->promotion_evidence);

        $this->expectException(RuntimeException::class);
        app(CouncilAdjudicationService::class)->adjudicate(
            $row->fresh(), 'WAIT', 'sealed-run-2', str_repeat('a', 64), ['window-c'], 'operator', 'duplicate',
        );
    }

    /** @return array{0:LabAgent,1:LabGeneration} */
    private function agent(): array
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'XAUUSD Lab', 'timeframe' => 'H1',
            'strategy_families' => ['differential_router'], 'is_active' => true,
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 999, 'trigger_type' => 'test',
            'data_fingerprint' => str_repeat('d', 64), 'population_size' => 1, 'status' => 'screened',
        ]);
        $model = ModelVersion::create([
            'name' => 'test-control-model', 'strategy' => 'test-control-model', 'version' => 'v1',
            'generation' => 999, 'status' => 'testing', 'parameters' => ['minimum_confidence' => .9],
            'metadata' => ['specialist_council_membership' => ['member_role' => 'edge_quality_specialist']],
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'differential_router',
            'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => ['minimum_confidence' => ['old' => .8, 'new' => .9]],
        ]);

        return [$agent, $generation];
    }
}
