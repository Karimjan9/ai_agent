<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAdversarialScenario;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\AdversarialCoEvolutionService;
use App\Services\CausalObservationReconciliationService;
use App\Services\EvolutionOperatingSystemService;
use App\Services\EvolutionPortfolioService;
use App\Services\LearningReceiptService;
use App\Services\MutationBrainService;
use App\Services\SkillZooService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityDiversityEvolutionOperatingSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_skill_zoo_and_mutation_brain_keep_research_credit_separate_from_promotion(): void
    {
        [$agent] = $this->agent();
        app(LearningReceiptService::class)->issue($agent);
        $action = app(MutationBrainService::class)->settle($agent->fresh(['modelVersion']), [
            'evidence_run_id' => 'qd-full-proof',
            'mutation_observability' => ['observable_effect' => true, 'control_delta' => .08, 'non_target_regression' => ['safe' => true]],
        ]);
        $response = LabMutationResponseMap::create(['response_key' => 'qd-response', 'stage' => 'screening', 'status' => 'positive',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'target' => 'profit_factor',
            'parameter_key' => 'entry_threshold', 'direction' => 'increase', 'sibling_kind' => 'candidate',
            'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id, 'evidence_run_id' => 'qd-screen',
            'target_delta' => ['delta' => .08], 'metadata' => []]);
        $skill = app(SkillZooService::class)->record($agent->fresh(['modelVersion']), [
            'evidence_run_id' => 'qd-screen', 'mutation_observability' => ['control_delta' => .08, 'non_target_regression' => ['safe' => true]],
        ], ['id' => $response->id, 'parameter_key' => 'entry_threshold', 'target' => 'profit_factor', 'status' => 'positive']);

        $this->assertSame('settled', $action['status']);
        $this->assertSame('entry_trigger', $skill['module']);
        $this->assertFalse($action['promotion_evidence']);
        $this->assertFalse($skill['promotion_evidence']);
    }

    public function test_adversary_is_historically_bounded_and_cannot_complete_without_sealed_replay(): void
    {
        [$agent] = $this->agent();
        $planned = app(AdversarialCoEvolutionService::class)->plan($agent);

        $this->assertCount(11, $planned);
        $this->assertSame('planned', $planned[0]['status']);
        $blocked = app(AdversarialCoEvolutionService::class)->settle(
            LabAdversarialScenario::find($planned[0]['id'])->scenario_key,
            ['damage_score' => .9, 'within_historical_bounds' => true],
        );
        $this->assertSame('blocked', $blocked['status']);
        $this->assertFalse($blocked['promotion_evidence']);
    }

    public function test_pareto_minimal_criterion_multi_fidelity_directors_and_extinction_are_bounded(): void
    {
        [$agent, $lab] = $this->agent();
        $portfolio = app(EvolutionPortfolioService::class);
        $front = $portfolio->paretoFront([
            ['return_quality' => .9, 'temporal_robustness' => .9, 'regime_coverage' => .5, 'stress_survival' => .8, 'novelty' => .2, 'sample_confidence' => .8, 'drawdown' => .1, 'cost_sensitivity' => .1, 'complexity' => .4, 'behavioral_duplication' => .5],
            ['return_quality' => .8, 'temporal_robustness' => .8, 'regime_coverage' => .4, 'stress_survival' => .7, 'novelty' => .1, 'sample_confidence' => .7, 'drawdown' => .2, 'cost_sensitivity' => .2, 'complexity' => .5, 'behavioral_duplication' => .6],
            ['return_quality' => .5, 'temporal_robustness' => .5, 'regime_coverage' => .95, 'stress_survival' => .95, 'novelty' => .95, 'sample_confidence' => .5, 'drawdown' => .25, 'cost_sensitivity' => .25, 'complexity' => .6, 'behavioral_duplication' => .1],
        ]);
        $criterion = $portfolio->minimalCriterion(['data_valid' => true, 'total_trades' => 4, 'max_drawdown_percent' => 20]);
        $allocation = $portfolio->multiFidelityAllocate([['expected_improvement' => .2, 'uncertainty' => .8, 'novelty' => .9, 'compute_cost' => 1]], 1);
        $directors = $portfolio->directorPlan($lab);
        $extinction = $portfolio->extinctionPlan($lab, 'novelty', ['archive_growth' => 0, 'novelty' => 0, 'causal_improvements' => 0, 'duplicate_rate' => .8]);

        $this->assertCount(2, $front);
        $this->assertTrue($criterion['passed']);
        $this->assertCount(1, $allocation['selected']);
        $this->assertCount(5, $directors['directors']);
        $this->assertSame('approval_required', $extinction['status']);
        $this->assertFalse($extinction['promotion_evidence']);
    }

    public function test_operating_system_exposes_full_contract_without_runtime_action(): void
    {
        [, $lab] = $this->agent();
        $blueprint = app(EvolutionOperatingSystemService::class)->blueprint($lab);

        $this->assertSame('quality_diversity_operating_system_v1', $blueprint['protocol']);
        $this->assertCount(5, $blueprint['meta_evolution']['directors']);
        $this->assertTrue($blueprint['adversarial_market']['historical_bounds_required']);
        $this->assertFalse($blueprint['promotion_evidence']);
    }

    public function test_surrogate_ranks_only_after_sealed_observations_and_never_replaces_replay(): void
    {
        [$agent, $lab] = $this->agent();
        foreach (range(1, 10) as $index) {
            LabMutationResponseMap::create(['response_key' => "surrogate-{$index}", 'stage' => 'full_replay', 'status' => 'confirmed',
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'target' => 'profit_factor',
                'parameter_key' => 'entry_threshold', 'direction' => 'increase', 'sibling_kind' => 'candidate',
                'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id, 'evidence_run_id' => "surrogate-proof-{$index}",
                'target_delta' => ['delta' => .01 * $index], 'metadata' => []]);
        }

        $surrogate = app(EvolutionPortfolioService::class)->surrogate($lab, [['gene_key' => 'entry_threshold', 'target' => 'profit_factor']]);

        $this->assertSame('research_ranker_ready', $surrogate['status']);
        $this->assertSame(10, $surrogate['training_rows']);
        $this->assertCount(1, $surrogate['virtual_proposals']);
        $this->assertFalse($surrogate['promotion_evidence']);
    }

    public function test_historical_causal_reconciliation_creates_a_new_projection_without_replaying_or_rewriting_evidence(): void
    {
        [$agent] = $this->agent();
        $run = LabEvaluationRun::create(['run_id' => 'reconcile-screen-run', 'lab_generation_id' => $agent->lab_generation_id,
            'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id, 'phase' => 'screening', 'mode' => 'screen',
            'attempt' => 1, 'status' => 'completed', 'started_at' => now()->subMinute(), 'finished_at' => now(), 'response_hash' => 'source-hash',
            'parameter_hash' => 'parameters', 'trade_ledger_hash' => 'trades',
            'response_meta' => ['event_ledger_hash' => 'events', 'decision_trace_hash' => 'signals', 'trade_ledger_complete' => true]]);
        $payload = ['profit_factor' => 1.05, 'total_trades' => 12, 'trade_ledger_hash' => 'trades', 'event_ledger_hash' => 'events',
            'entry_funnel' => ['accepted_entries' => 4], 'abstention_count' => 0,
            'data_manifest' => ['sha256' => 'snapshot'], 'execution_contract' => ['execution_hash' => 'execution']];
        LabEvidenceArtifact::create(['artifact_id' => 'reconcile-artifact', 'run_id' => $run->run_id, 'lab_generation_id' => $agent->lab_generation_id,
            'lab_agent_id' => $agent->id, 'artifact_type' => 'evaluation_response', 'sha256' => hash('sha256', json_encode($payload)),
            'byte_size' => 1, 'content_encoding' => 'json', 'payload' => $payload, 'metadata' => [], 'recorded_at' => now()]);

        $result = app(CausalObservationReconciliationService::class)->reconcileAgent($agent->fresh(['modelVersion']));

        $this->assertSame('reconciled_append_only', $result['status']);
        $this->assertFalse($result['dispatch']);
        $this->assertFalse($result['retry']);
        $observation = data_get(LabMutationResponseMap::find($result['response_map_id'])->observed_metrics, 'causal_observation');
        $this->assertSame('causal_observation_v1', data_get($observation, 'protocol'));
        $this->assertSame('signals', data_get($observation, 'signal_decision_hash'));
        $this->assertSame('parameters', data_get($observation, 'parameter_hash'));
        $this->assertSame(12, data_get($observation, 'exit_funnel.accepted_exits'));
    }

    /** @return array{0: LabAgent, 1: AiLaboratory} */
    private function agent(): array
    {
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'name' => 'QD Lab', 'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test', 'population_size' => 1, 'status' => 'draft', 'data_fingerprint' => 'qd-snapshot', 'trigger_context' => []]);
        $model = ModelVersion::create(['name' => 'qd-child', 'strategy' => 'qd-child', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => ['entry_threshold' => 2], 'metadata' => ['generation_target' => 'profit_factor', 'execution_contract' => ['protocol' => 'test']]]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'draft', 'parameter_diff' => ['entry_threshold' => ['old' => 1, 'new' => 2]]]);

        return [$agent->fresh(['modelVersion', 'generation']), $lab];
    }
}
