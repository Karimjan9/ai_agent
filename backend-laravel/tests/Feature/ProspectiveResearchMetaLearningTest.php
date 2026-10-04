<?php

namespace Tests\Feature;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AiLaboratory;
use App\Models\CausalFoldReceipt;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\ResearchKnowledgePortfolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProspectiveResearchMetaLearningTest extends TestCase
{
    use RefreshDatabase;

    public function test_preregister_then_actual_fold_calibration_is_idempotent_and_cannot_issue_credit(): void
    {
        [$experiment, $request] = $this->experiment('first');
        $service = app(ResearchKnowledgePortfolioService::class);
        $seal = $service->preregisterExperiment($experiment, $request);
        $this->assertSame('preregistered', $seal['status']);
        $this->assertSame('prior_only', $seal['forecast']['status']);
        $this->assertNull($seal['forecast']['expected_target_delta']);
        $this->assertSame(.5, $seal['forecast']['success_probability']);
        $this->complete($experiment, $request, .25);
        $calibration = $service->settleExperimentPrediction($experiment->fresh());
        $this->assertSame('local_target_calibrated', $calibration['status'], json_encode($calibration));
        $this->assertEquals(.25, $calibration['outcome']['target_delta']);
        $this->assertEquals(.25, $calibration['brier_loss']);
        $this->assertNull($calibration['outcome']['replay_cpu_seconds']);
        $this->assertFalse($calibration['confirmed_skill']);
        $this->assertSame($calibration, $service->settleExperimentPrediction($experiment->fresh()));
        $forecast = $service->predictExperiment($seal['spec']);
        $this->assertSame(1, $forecast['powered_question_count']);
        $this->assertFalse($forecast['calibrated']);
        $this->assertEquals(2 / 3, $forecast['success_probability']);
        $this->assertSame('positive_research_region', $service->applicabilityBoundary($seal['spec'])['status']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_observed_experiment_has_no_retrospective_prediction_and_scope_drift_is_blocked(): void
    {
        [$experiment, $request] = $this->experiment('seen');
        $this->complete($experiment, $request, .2);
        $service = app(ResearchKnowledgePortfolioService::class);
        $this->assertSame('OBSERVED_EXPERIMENT_CANNOT_BE_PREREGISTERED', $service->preregisterExperiment($experiment, $request)['reason']);
        [$fresh, $original] = $this->experiment('fresh');
        $service->preregisterExperiment($fresh, $original);
        $original['replay_dataset_hash'] = str_repeat('d', 64);
        $this->assertSame('PREDICTION_PREREGISTERED_CONTRACT_DRIFT', $service->preregisterExperiment($fresh, $original)['reason']);
        $this->assertSame('PROSPECTIVE_PREDICTION_REQUIRED', $service->settleExperimentPrediction($experiment->fresh())['reason']);
    }

    public function test_underpowered_and_tampered_folds_never_become_negative_or_positive_skill_facts(): void
    {
        [$experiment, $request] = $this->experiment('thin');
        $service = app(ResearchKnowledgePortfolioService::class);
        $seal = $service->preregisterExperiment($experiment, $request);
        $this->complete($experiment, $request, 1, 2);
        $result = $service->settleExperimentPrediction($experiment->fresh());
        $this->assertSame('underpowered', $result['status']);
        $this->assertNull($result['brier_loss']);
        $this->assertSame('unknown', $service->applicabilityBoundary($seal['spec'])['status']);
        $fold = CausalFoldReceipt::where('agent_learning_causal_experiment_id', $experiment->id)->first();
        DB::table('causal_fold_receipts')->where('id', $fold->id)->update(['response_hash' => str_repeat('e', 64)]);
        $this->assertSame('COMPLETE_MATCHING_ORIGINAL_FOLDS_REQUIRED', $service->settleExperimentPrediction($experiment->fresh())['reason']);
        $this->assertSame(0, $service->predictExperiment($seal['spec'])['powered_question_count']);
    }

    public function test_changed_treatment_parameters_invalidate_original_outcome_even_if_fold_hashes_match(): void
    {
        [$experiment, $request] = $this->experiment('mutation');
        $service = app(ResearchKnowledgePortfolioService::class);
        $service->preregisterExperiment($experiment, $request);
        $this->complete($experiment, $request, .1);
        LabAgent::find($experiment->guided_agent_id)->modelVersion->update(['parameters' => ['wait' => 999]]);
        $this->assertSame('COMPLETE_MATCHING_ORIGINAL_FOLDS_REQUIRED', $service->settleExperimentPrediction($experiment->fresh())['reason']);
    }

    public function test_probe_prefers_a_real_distinction_not_more_variants_and_preserves_safety(): void
    {
        $service = app(ResearchKnowledgePortfolioService::class);
        $hypotheses = [['id' => 'context', 'prior' => .5], ['id' => 'adapter', 'prior' => .5]];
        $base = ['legal' => true, 'safety_preserved' => true, 'cost_ceiling_seconds' => 10];
        $result = $service->chooseDiscriminatingProbe($hypotheses, [
            [...$base, 'id' => 'uninformative', 'predictions' => ['context' => ['pass' => .5, 'fail' => .5], 'adapter' => ['pass' => .5, 'fail' => .5]]],
            [...$base, 'id' => 'discriminating', 'predictions' => ['context' => ['pass' => 1.0], 'adapter' => ['fail' => 1.0]]],
            [...$base, 'id' => 'unsafe', 'safety_preserved' => false, 'predictions' => ['context' => ['pass' => 1.0], 'adapter' => ['fail' => 1.0]]],
        ]);
        $this->assertSame('discriminating', $result['selected_probe']);
        $this->assertEquals(1, $result['ranking'][0]['expected_information_bits']);
        $this->assertCount(2, $result['ranking']);
        $this->assertFalse($result['full_market_causality_proven']);
    }

    public function test_hypotheses_and_policy_are_durable_bounded_declarations_not_executable_code(): void
    {
        $service = app(ResearchKnowledgePortfolioService::class);
        $sealed = $service->sealCompetingHypotheses([
            ['id' => 'context', 'prior' => .5, 'predictions' => ['spread' => ['ready' => 1.0]]],
            ['id' => 'adapter', 'prior' => .5, 'predictions' => ['spread' => ['veto' => 1.0]]],
        ], ['question_key' => 'spread-asof', 'data_hash' => 'data', 'baseline_hash' => 'baseline']);
        $this->assertCount(2, $sealed['hypothesis_refs']);
        $this->assertDatabaseHas('research_knowledge_entries', ['status' => 'sealed', 'authority' => 'research_only']);
        $this->assertSame('DECLARATIVE_POLICY_INVALID', $service->registerPolicy(['weights' => ['code' => 'exec()'], 'compute_cap_seconds' => 60])['reason']);
        $policy = $service->registerPolicy(['weights' => ['expected_value' => 1, 'information_gain' => 1, 'cost' => -1], 'compute_cap_seconds' => 180]);
        $evaluation = $service->evaluatePolicy($policy['knowledge_key']);
        $this->assertCount(6, $evaluation['worlds']);
        $this->assertSame('safe', $evaluation['worlds']['poisoned']['selected']);
        $this->assertFalse($evaluation['policy_activated']);
        $this->assertFalse($evaluation['live_market_replay_proven']);
        $this->assertFalse($evaluation['promotion_evidence']);
    }

    public function test_random_control_share_is_bounded_reproducible_and_does_not_select_unready_jobs(): void
    {
        $service = app(ResearchKnowledgePortfolioService::class);
        $candidates = [['question_id' => 'useful', 'ready' => true, 'safety_preserved' => true, 'cost_ceiling_seconds' => 20, 'features' => ['expected_value' => .8]],
            ['question_id' => 'random-control', 'ready' => true, 'safety_preserved' => true, 'cost_ceiling_seconds' => 20, 'features' => []],
            ['question_id' => 'blocked', 'ready' => false, 'safety_preserved' => true, 'cost_ceiling_seconds' => 1]];
        $modes = [];
        for ($i = 0; $i < 40; $i++) {
            $result = $service->rankResearchQuestions($candidates, 'sealed-seed-'.$i);
            $this->assertCount(2, $result['ranking']);
            $this->assertSame($result, $service->rankResearchQuestions($candidates, 'sealed-seed-'.$i));
            $modes[] = $result['selection_mode'];
        }
        $this->assertContains('random_control_share', $modes);
        $this->assertContains('bounded_value_information_cost', $modes);
    }

    public function test_fixed_real_policy_challenge_waits_for_all_original_questions_and_cannot_self_activate(): void
    {
        $service = app(ResearchKnowledgePortfolioService::class);
        $policy = $service->registerPolicy(['weights' => ['expected_value' => 1, 'information_gain' => 1], 'compute_cap_seconds' => 180]);
        $variants = $service->proposePolicyVariants($policy['knowledge_key']);
        $this->assertCount(3, $variants['variants']);
        $keys = [$policy['knowledge_key'], $variants['variants'][0]['policy']['knowledge_key']];
        $experiments = [];
        foreach (range(1, 3) as $index) {
            [$experiment, $request] = $this->experiment('policy-real-'.$index);
            LabAgent::find($experiment->guided_agent_id)->modelVersion->update(['parameters' => ['wait' => 5 + $index]]);
            $request['policy_context']['causal_fold_job']['per_fold_budget_seconds'] = 60;
            $service->preregisterExperiment($experiment, $request);
            $experiments[] = [$experiment, $request];
        }
        $challenge = $service->preregisterPolicyChallenge($keys, array_map(fn ($item) => $item[0]->id, $experiments), 'fixed-seed');
        $this->assertSame('awaiting_fixed_real_question_outcomes', $challenge['status']);
        $this->assertSame('ALL_FIXED_REAL_QUESTION_OUTCOMES_REQUIRED', $service->settlePolicyChallenge($challenge['knowledge_key'])['reason']);
        foreach ($experiments as $index => [$experiment, $request]) $this->complete($experiment, $request, $index === 0 ? .2 : -.1);
        $result = $service->settlePolicyChallenge($challenge['knowledge_key']);
        $this->assertSame('fixed_real_question_comparison', $result['status']);
        $this->assertCount(2, $result['scores']);
        foreach ($result['scores'] as $score) {
            $this->assertSame(3, $score['assessable_local_questions']);
            $this->assertNull($score['actual_replay_cpu_seconds']);
            $this->assertFalse($score['compute_advantage_proven']);
        }
        $this->assertFalse($result['policy_activated']);
        $this->assertFalse($result['economic_authority']);
        $this->assertSame('UNOBSERVED_PREREGISTERED_REAL_QUESTIONS_REQUIRED', $service->preregisterPolicyChallenge($keys, array_map(fn ($item) => $item[0]->id, $experiments), 'late-seed')['reason']);
    }

    public function test_boundary_map_preserves_unknown_context_instead_of_extrapolating_authority(): void
    {
        [$experiment, $request] = $this->experiment('boundary');
        $experiment->update(['evidence' => ['context' => ['session' => 'london']]]);
        $service = app(ResearchKnowledgePortfolioService::class);
        $seal = $service->preregisterExperiment($experiment, $request);
        $this->complete($experiment, $request, .2);
        $service->settleExperimentPrediction($experiment->fresh());
        $map = $service->applicabilityMap($seal['spec'], [['session' => 'london'], ['session' => 'asia']]);
        $this->assertCount(2, $map['cells']);
        $this->assertCount(1, $map['unknown_context_hashes']);
        $this->assertFalse($map['inferred_regions_confirmed']);
        $this->assertFalse($map['promotion_evidence']);
    }

    public function test_new_native_receipts_preserve_whole_valued_floats_under_the_original_hash(): void
    {
        [$experiment, $request] = $this->experiment('float-representation');
        $request['policy_context']['learning_confirmation_contracts']['numeric_threshold'] = 1.0;
        $service = app(ResearchKnowledgePortfolioService::class);
        $service->preregisterExperiment($experiment, $request);
        $this->complete($experiment, $request, .2);
        $fold = CausalFoldReceipt::where('agent_learning_causal_experiment_id', $experiment->id)->first();
        $this->assertSame(1.0, data_get($fold->fresh()->request_payload, 'policy_context.learning_confirmation_contracts.numeric_threshold'));
        $this->assertSame($fold->request_hash, $this->hash($fold->fresh()->request_payload));
        $this->assertSame('local_target_calibrated', $service->settleExperimentPrediction($experiment->fresh())['status']);
        $receipt = \App\Models\ResearchExperimentReceipt::create(['receipt_key' => 'float-receipt', 'source_type' => 'test', 'source_id' => 1,
            'symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5', 'contract_version' => 'test', 'rule_version' => 'test',
            'contract_hash' => str_repeat('a', 64), 'evidence_hash' => str_repeat('b', 64), 'classification' => 'INCONCLUSIVE',
            'subject_revision' => 1, 'evidence_revision' => 1, 'payload' => ['evidence' => ['loss' => 1.0]]]);
        $this->assertSame(1.0, data_get($receipt->fresh()->payload, 'evidence.loss'));
    }

    public function test_new_generation_ids_cannot_mint_new_calibration_questions_for_the_same_consumed_vectors(): void
    {
        $service = app(ResearchKnowledgePortfolioService::class);
        $spec = null;
        foreach (['same-question-1', 'same-question-2'] as $key) {
            [$experiment, $request] = $this->experiment($key);
            $seal = $service->preregisterExperiment($experiment, $request);
            $spec = $seal['spec'];
            $this->complete($experiment, $request, .2);
            $service->settleExperimentPrediction($experiment->fresh());
        }
        $this->assertSame(1, $service->predictExperiment($spec)['powered_question_count']);
        $this->assertFalse($service->predictExperiment($spec)['out_of_sample_accuracy_improvement_proven']);
    }

    private function experiment(string $key): array
    {
        $lab = AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD', 'timeframe' => 'H1'], ['name' => $key, 'strategy_families' => ['hybrid'], 'is_active' => false]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => LabGeneration::count() + 1, 'trigger_type' => 'test', 'status' => 'draft', 'population_size' => 3]);
        $ids = [];
        foreach ([5, 6, 4] as $index => $wait) {
            $model = ModelVersion::create(['name' => $key.'-'.$index, 'strategy' => 'hybrid', 'version' => $key.'-'.$index, 'generation' => 1, 'status' => 'testing', 'parameters' => ['wait' => $wait]]);
            $ids[] = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'draft'])->id;
        }
        $experiment = AgentLearningCausalExperiment::create(['experiment_key' => $key, 'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'gene_key' => 'wait',
            'guided_agent_id' => $ids[0], 'blinded_agent_id' => $ids[1], 'control_agent_id' => $ids[2], 'status' => 'planned']);
        $request = ['replay_dataset_hash' => str_repeat('a', 64), 'execution_contract' => ['execution_hash' => str_repeat('b', 64)],
            'research_release' => ['source_hash' => str_repeat('c', 64)],
            'policy_context' => ['causal_fold_job' => ['fold_count' => 3], 'learning_confirmation_contracts' => ['minimum_trades' => 3]]];
        return [$experiment, $request];
    }

    private function complete(AgentLearningCausalExperiment $experiment, array $request, float $delta, int $trades = 5): void
    {
        $folds = collect();
        foreach (range(1, 3) as $index) {
            $items = [];
            foreach ([$experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id] as $role => $id) {
                $items[] = ['lab_agent_id' => $id, 'result' => ['profit_factor' => $role === 0 ? 1 + $delta : 1, 'total_trades' => $trades]];
            }
            $response = json_decode(json_encode(['leaderboard' => $items]), true);
            $folds->push(CausalFoldReceipt::create(['receipt_key' => $experiment->experiment_key.'-'.$index,
                'agent_learning_causal_experiment_id' => $experiment->id, 'lab_generation_id' => $experiment->lab_generation_id,
                'fold_index' => $index, 'fold_count' => 3, 'status' => 'completed', 'completed_at' => now(), 'observed_at' => now(),
                'request_payload' => $request, 'request_hash' => $this->hash($request), 'response_payload' => $response, 'response_hash' => $this->hash($response),
                'dataset_hash' => $request['replay_dataset_hash'], 'execution_hash' => $request['execution_contract']['execution_hash']]));
        }
        $experiment->update(['evidence' => ['fold_execution' => ['settlement' => ['status' => 'completed', 'atomic' => true,
            'fold_count' => 3, 'receipt_ids' => $folds->pluck('id')->all(), 'receipt_hashes' => $folds->pluck('response_hash')->all(), 'aggregate_hash' => str_repeat('f', 64)]]]]);
    }

    private function hash(array $items): string
    {
        $canonical = function (array $items) use (&$canonical): array { foreach ($items as $key => $value) if (is_array($value)) $items[$key] = $canonical($value); if (! array_is_list($items)) ksort($items); return $items; };
        return hash('sha256', json_encode($canonical($items), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
