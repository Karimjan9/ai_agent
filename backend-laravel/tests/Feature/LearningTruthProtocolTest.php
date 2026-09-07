<?php

namespace Tests\Feature;

use App\Models\AgentLearningLesson;
use App\Models\AiLaboratory;
use App\Models\CanonicalLearningOutbox;
use App\Models\CapabilityCausalAttribution;
use App\Models\EvolutionLearningReceipt;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLaneDispatch;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\CanonicalLearningOutboxService;
use App\Services\CanonicalSkillCartridgeService;
use App\Services\CausalEdgeAccountingService;
use App\Services\FailureSignatureCompilerService;
use App\Services\LearningCompilerService;
use App\Services\LearningRewardService;
use App\Services\TradingOperatingSystemScorecardService;
use App\Services\EvolvingTraderFitnessService;
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

    public function test_positive_canonical_settlement_preserves_pair_and_executed_gene_in_learning_projections(): void
    {
        [$agent, $pair] = $this->pair(true);
        $result = app(CanonicalLearningOutboxService::class)->record($agent, $pair, [
            'evidence_run_id' => 'truth-valid', 'total_trades' => 24,
            'profit_factor' => 1.25, 'mutation_observability' => ['observable_effect' => true],
        ], true, ['improved' => true]);

        $this->assertSame('completed', $result['status']);
        $cartridge = app(CanonicalSkillCartridgeService::class)->retrieve(
            'XAUUSD', 'H1', 'hybrid', ['regime' => 'unknown', 'volatility' => 'unknown', 'session' => 'unknown'], ['minimum_confidence'],
        );
        $this->assertSame('compatible_cartridge_found', $cartridge['status']);
        $this->assertSame('minimum_confidence', $cartridge['gene']);
        $this->assertEquals(1.0, $cartridge['old_value']);
        $this->assertEquals(1.1, $cartridge['proposed_value']);
        $this->assertDatabaseCount('skill_cartridge_revisions', 1);
        $this->assertDatabaseHas('research_experiment_receipts', [
            'canonical_learning_outbox_id' => CanonicalLearningOutbox::firstOrFail()->id,
            'classification' => 'POSITIVE_CANDIDATE',
        ]);
        $this->assertSame('memory_abstained', app(CanonicalSkillCartridgeService::class)->retrieve(
            'XAUUSD', 'H1', 'hybrid', ['regime' => 'trend', 'volatility' => 'unknown', 'session' => 'unknown'], ['minimum_confidence'],
        )['status']);
        $lesson = AgentLearningLesson::query()->where('lab_agent_id', $agent->id)->latest('id')->firstOrFail();
        $this->assertSame($pair->id, data_get($lesson->evidence, 'pair_id'));
        $this->assertSame(['value' => 1.1], data_get($lesson->evidence, 'new_value'));
        $receipt = EvolutionLearningReceipt::query()->where('lab_agent_id', $agent->id)->firstOrFail();
        $this->assertSame('provisional', $receipt->status);
        $this->assertSame('minimum_confidence', data_get($receipt->evidence, 'input.parameter_key'));
        $this->assertSame(0, data_get($receipt->evidence, 'input.independent_windows'));

        $duplicate = app(CanonicalLearningOutboxService::class)->record($agent, $pair->fresh(), [
            'evidence_run_id' => 'truth-valid', 'total_trades' => 24,
            'profit_factor' => 1.25,
        ], true, ['improved' => true]);
        $this->assertSame('completed', $duplicate['status']);
        $this->assertSame('canonical_episode_settled', $pair->fresh()->status);

        $service = app(CanonicalLearningOutboxService::class);
        $outbox = CanonicalLearningOutbox::firstOrFail();
        $attribution = CapabilityCausalAttribution::firstOrFail();
        $this->assertSame(
            CanonicalLearningOutboxService::CAPABILITY_PROJECTION_PROTOCOL,
            data_get($attribution->evidence, 'projection_protocol'),
        );
        $this->assertFalse($service->requiresReprojection($outbox));
        $attribution->update([
            'primary_cause' => 'strategy',
            'contributions' => ['strategy' => .25, 'tactic' => .20],
            'evidence' => ['legacy_projection' => true],
        ]);
        $this->assertTrue($service->requiresReprojection($outbox));

        $receipt->delete();
        $pair->fresh()->update(['status' => 'canonical_episode_settled']);
        $reprojected = $service->reproject($outbox);
        $this->assertSame('reprojected', $reprojected['status']);
        $this->assertDatabaseCount('evolution_learning_receipts', 1);
        $this->assertSame('canonical_episode_settled', $pair->fresh()->status);
        $this->assertFalse($service->requiresReprojection($outbox));
        $this->assertSame(
            TradingOperatingSystemScorecardService::PROTOCOL,
            data_get($attribution->fresh()->evidence, 'trading_operating_system_scorecard.protocol'),
        );
    }

    public function test_relative_uplift_with_absolute_safety_failure_is_observation_not_preference(): void
    {
        [$agent, $pair] = $this->pair(true);
        $result = app(CanonicalLearningOutboxService::class)->record($agent, $pair, [
            'evidence_run_id' => 'truth-valid',
            'total_trades' => 24,
            'profit_factor' => 1.1,
            'max_drawdown_percent' => 25,
        ], true, ['delta' => .12, 'improved' => true]);

        $this->assertSame('completed', $result['status']);
        $this->assertDatabaseHas('agent_learning_settlements', [
            'source_id' => $pair->id,
            'evidence_state' => 'negative',
            'hard_failure' => true,
        ]);
        $receipt = EvolutionLearningReceipt::query()->where('lab_agent_id', $agent->id)->firstOrFail();
        $this->assertSame('observe', $receipt->action);
        $this->assertSame('provisional', $receipt->status);
        $this->assertTrue((bool) data_get($receipt->evidence, 'input.absolute_viability_failed'));
        $this->assertContains('DRAWDOWN_LIMIT', (array) data_get($receipt->evidence, 'input.settlement_vetoes', []));
        $this->assertSame(0.0, (float) data_get($receipt->evidence, 'input.component_credit.drawdown_safety'));
        $this->assertSame(CausalEdgeAccountingService::PROTOCOL, data_get($receipt->evidence, 'input.causal_edge_accounting.protocol'));
    }

    public function test_unexecuted_response_map_cannot_enter_canonical_or_cartridge_paths(): void
    {
        [$agent, $pair] = $this->pair(true);
        $pair->candidateResponseMap->update(['parameter_key' => 'not_the_executed_gene']);
        $result = app(CanonicalLearningOutboxService::class)->record($agent, $pair->fresh(), [
            'evidence_run_id' => 'unexecuted-map', 'total_trades' => 24, 'profit_factor' => 1.25,
        ], true, ['improved' => true]);

        $this->assertSame('diagnostic_only', $result['status']);
        $this->assertDatabaseCount('canonical_learning_outbox', 0);
        $this->assertSame('memory_abstained', app(CanonicalSkillCartridgeService::class)->retrieve(
            'XAUUSD', 'H1', 'hybrid', ['regime' => 'unknown'], ['minimum_confidence'],
        )['status']);
    }

    public function test_distinct_observed_genes_cannot_collapse_into_the_same_claim(): void
    {
        $compiler = app(LearningCompilerService::class);
        $base = [
            'source_type' => 'claim-key-regression',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'component' => 'strategy_parameter',
            'action' => 'observe',
            'scope' => ['regime' => 'trend'],
        ];

        $first = $compiler->compile([
            ...$base,
            'source_key' => 'trend-down-threshold',
            'parameter_key' => 'trend_down_roc_threshold',
        ]);
        $second = $compiler->compile([
            ...$base,
            'source_key' => 'trend-up-strength',
            'parameter_key' => 'trend_up_strength_min',
        ]);

        $this->assertNotSame($first['claim_key'], $second['claim_key']);
        $this->assertDatabaseCount('evolution_learning_receipts', 2);
    }

    public function test_typed_mutation_scope_cannot_leak_into_the_wrong_context_axis(): void
    {
        [$agent, $pair] = $this->pair(true);
        $agent->modelVersion->update(['metadata' => ['mutation_scope' => 'volatility:high_volatility']]);
        $signature = app(FailureSignatureCompilerService::class)->compile($agent->fresh('modelVersion'));

        // The raw typed value remains in context_contract.raw_v1_axes, while
        // retrieval receives the bounded canonical volatility axis.
        $this->assertSame('high', data_get($signature, 'state.volatility'));
        $this->assertNull(data_get($signature, 'state.session'));

        // Legacy rows remain immutable, so projection also repairs the old
        // misplaced typed value at its trust boundary.
        $pair->update(['failure_signature' => [
            'state' => ['regime' => '-', 'volatility' => '-', 'session' => 'volatility:high_volatility'],
        ]]);
        app(CanonicalLearningOutboxService::class)->record($agent, $pair->fresh(), [
            'evidence_run_id' => 'truth-valid',
            'total_trades' => 24,
            'profit_factor' => 1.25,
        ], true, ['improved' => true]);

        $scope = (array) EvolutionLearningReceipt::query()->where('lab_agent_id', $agent->id)->firstOrFail()->scope;
        // The evolution receipt keeps its historical scoped label; the
        // ContextContract projection above supplies canonical retrieval axes.
        $this->assertSame('high_volatility', $scope['volatility']);
        $this->assertArrayNotHasKey('session', $scope);
        $this->assertArrayNotHasKey('regime', $scope);
    }

    public function test_rich_replay_is_projected_into_measured_causal_edge_components(): void
    {
        $projection = app(CausalEdgeAccountingService::class)->project([
            'total_trades' => 120,
            'profit_factor' => 1.15,
            'net_profit_percent' => 8,
            'max_drawdown_percent' => 7.5,
            'monte_carlo' => ['risk_of_ruin_percent' => 5],
            'statistical_evidence' => [
                'deflated_sharpe' => ['deflated_sharpe_probability' => .8],
                'edge_quality' => ['confidence_calibration' => ['calibration_score' => 80]],
            ],
            'fitness_breakdown' => ['components' => ['regime_coverage' => .75]],
            'opportunity_recall' => ['abstention_precision' => .6],
            'walk_forward' => ['forward_window_protocol' => ['powered_windows' => 9, 'positive_windows' => 6]],
        ]);

        $this->assertSame(CausalEdgeAccountingService::PROTOCOL, data_get($projection, 'causal_edge_accounting.protocol'));
        $this->assertSame(.8, $projection['edge_quality']);
        $this->assertSame(.5, $projection['cost_adjusted_return']);
        $this->assertSame(.5, $projection['drawdown_safety']);
        $this->assertSame(.5, $projection['risk_of_ruin']);
        $this->assertSame(.666667, $projection['temporal_stability']);
        $this->assertSame(.75, $projection['regime_coverage']);
        $this->assertSame(.8, $projection['calibration']);
        $this->assertSame(.6, $projection['abstention_quality']);
        $this->assertSame(
            TradingOperatingSystemScorecardService::PROTOCOL,
            data_get($projection, 'trading_operating_system_scorecard.protocol'),
        );
        $this->assertSame(
            EvolvingTraderFitnessService::PROTOCOL,
            data_get($projection, 'evolving_trader_fitness.protocol'),
        );
        $this->assertSame(15, data_get($projection, 'evolving_trader_fitness.evidence_coverage.required_dimensions'));
        $this->assertTrue((bool) data_get($projection, 'evolving_trader_fitness.learning_cadence_contract.lesson_is_not_rule_change'));
        $this->assertFalse((bool) data_get($projection, 'evolving_trader_fitness.risk_asymmetry.may_raise_live_risk'));
        $this->assertContains(
            'management',
            data_get($projection, 'trading_operating_system_scorecard.evidence_coverage.missing_blocks'),
        );

        $unitCalibration = app(CausalEdgeAccountingService::class)->project([
            'statistical_evidence' => ['edge_quality' => ['confidence_calibration' => ['calibration_score' => .5601]]],
        ]);
        $this->assertSame(.5601, $unitCalibration['calibration']);
    }

    /** @return array{LabAgent,LabLearningLanePair} */
    private function pair(bool $valid): array
    {
        $lab = AiLaboratory::create(['name' => 'Truth protocol XAUUSD H1', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test', 'status' => 'screened']);
        $candidateModel = ModelVersion::create(['name' => 'truth-candidate-'.$valid, 'strategy' => 'hybrid', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => ['minimum_confidence' => 1.1], 'metadata' => []]);
        $controlModel = ModelVersion::create(['name' => 'truth-control-'.$valid, 'strategy' => 'hybrid', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $candidate = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $candidateModel->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => ['minimum_confidence' => ['old' => 1.0, 'new' => 1.1]]]);
        $control = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $controlModel->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => []]);
        $data = str_repeat('a', 64);
        $execution = str_repeat('b', 64);
        $candidateMap = LabMutationResponseMap::create(['response_key' => 'truth-candidate-'.$valid, 'stage' => 'screening', 'status' => 'screen_observed', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'lab_agent_id' => $candidate->id, 'parameter_key' => 'minimum_confidence', 'direction' => 'increase', 'old_value' => ['value' => 1.0], 'new_value' => ['value' => 1.1], 'observed_metrics' => [], 'metadata' => ['data_manifest_hash' => $data, 'execution_hash' => $execution]]);
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
