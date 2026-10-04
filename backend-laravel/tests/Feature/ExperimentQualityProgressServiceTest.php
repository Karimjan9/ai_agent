<?php

namespace Tests\Feature;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AiLaboratory;
use App\Models\CausalFoldReceipt;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\ExperimentQualityProgressService;
use App\Services\CausalBlindedMutationSelectorService;
use App\Services\ExecutionContractService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchReleaseSealService;
use App\Services\TypedInstrumentFoundryService;
use App\Services\StrategyParameterSchemaService;
use App\Services\LabImmutableEvidenceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExperimentQualityProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_atomic_progress_separates_answered_questions_from_low_power_and_incomplete_replay(): void
    {
        $generation = $this->generation();
        $noEffect = $this->experiment($generation, 'no-effect', 'no_effect');
        $lowPower = $this->experiment($generation, 'low-power', 'underpowered');
        $incomplete = $this->experiment($generation, 'incomplete', 'no_effect', false);
        $harmful = $this->experiment($generation, 'harmful', 'harmful');
        $snapshot = app(ExperimentQualityProgressService::class)->snapshot($generation);

        $this->assertSame('canonical_causal_experiments_only', data_get($snapshot, 'progress_outcomes.comparison_scope'));
        $this->assertFalse(data_get($snapshot, 'progress_outcomes.academy_trials_included'));
        $this->assertSame(3, data_get($snapshot, 'progress_outcomes.technically_completed_experiments.count'));
        $this->assertSame(2, data_get($snapshot, 'progress_outcomes.question_answering_comparisons.count'));
        $counts = data_get($snapshot, 'progress_outcomes.question_answering_comparisons.outcome_counts');
        $this->assertSame(['no_effect' => 1, 'underpowered' => 1, 'technical_incomplete' => 1, 'harmful' => 1], $counts);
        $this->assertSame([$noEffect->id, $harmful->id], data_get($snapshot, 'progress_outcomes.question_answering_comparisons.experiment_ids'));
        $this->assertNotContains($lowPower->id, data_get($snapshot, 'progress_outcomes.question_answering_comparisons.experiment_ids'));
        $this->assertNotContains($incomplete->id, data_get($snapshot, 'progress_outcomes.technically_completed_experiments.experiment_ids'));
        foreach (['independent_control_improvement', 'memory_vs_blinded_efficiency', 'retained_benefit', 'knowledge_per_compute_hour'] as $key) {
            $this->assertArrayHasKey($key, $snapshot);
        }
        $this->assertDatabaseCount('agent_learning_lessons', 0);
        $this->assertFalse($snapshot['promotion_evidence']);
    }

    public function test_missing_or_corrupted_original_fold_evidence_cannot_become_technical_progress(): void
    {
        $generation = $this->generation();
        $experiment = $this->experiment($generation, 'tampered-fold', 'no_effect');
        $service = app(ExperimentQualityProgressService::class);
        $this->assertTrue($service->technicallyComplete($experiment));
        // Simulates stored evidence corruption through the raw connection; the model itself is append-only.
        DB::table('causal_fold_receipts')->where('agent_learning_causal_experiment_id', $experiment->id)
            ->where('fold_index', 2)->update(['response_hash' => str_repeat('0', 64)]);
        $this->assertFalse($service->technicallyComplete($experiment));
        $snapshot = $service->snapshot($generation);
        $this->assertSame(0, data_get($snapshot, 'progress_outcomes.technically_completed_experiments.count'));
        $this->assertSame(0, data_get($snapshot, 'progress_outcomes.question_answering_comparisons.count'));
    }

    public function test_a_receipt_label_and_orphaned_closure_do_not_answer_a_question(): void
    {
        $generation = $this->generation();
        $experiment = $this->experiment($generation, 'receipt-tamper', 'no_effect');
        DB::table('research_experiment_receipts')->where('source_id', $experiment->id)->update(['evidence_hash' => str_repeat('f', 64)]);
        $snapshot = app(ExperimentQualityProgressService::class)->snapshot($generation);
        $this->assertSame(1, data_get($snapshot, 'progress_outcomes.technically_completed_experiments.count'));
        $this->assertSame(0, data_get($snapshot, 'progress_outcomes.question_answering_comparisons.count'));
        $this->assertSame(0, data_get($snapshot, 'knowledge_per_compute_hour.unique_closed_questions'));
    }

    public function test_forged_confirmed_labels_do_not_count_as_reliable_improvements(): void
    {
        $generation = $this->generation();
        $experiment = $this->experiment($generation, 'forged-confirmed', 'ready_for_independent_validation');
        $experiment->update(['status' => 'confirmed', 'confirmed_at' => now(), 'guided_beats_control' => true,
            'evidence' => [...$experiment->evidence, 'component_effect' => ['passed' => true]]]);
        $snapshot = app(ExperimentQualityProgressService::class)->snapshot($generation);
        $this->assertSame(1, data_get($snapshot, 'independent_control_improvement.observed_confirmed_labels'));
        $this->assertSame(0, data_get($snapshot, 'independent_control_improvement.confirmed_count'));
        $this->assertSame(0, data_get($snapshot, 'knowledge_per_compute_hour.confirmed_improvements'));
    }

    public function test_original_replay_cannot_be_credited_to_coherently_edited_sibling_models(): void
    {
        $generation = $this->generation();
        $arms = $this->arms($generation, 'runtime-seal');
        $owner = app(LabImmutableEvidenceService::class);
        $service = app(ExperimentQualityProgressService::class);
        $verify = new \ReflectionMethod($service, 'verifiedReplay');
        $runs = [];
        $originalParameters = [];
        foreach (array_slice($arms, 0, 2) as $arm) {
            $originalParameters[$arm->model_version_id] = $arm->modelVersion->parameters;
            $run = $owner->beginRun($arm, 'full_validation', 'full');
            $candles = array_map(fn ($index): array => ['time' => CarbonImmutable::parse('2025-12-01T00:00:00Z')
                ->addMinutes($index * 5)->toIso8601String(), 'close' => 2000], range(0, 202));
            $owner->attachRequest($run, ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'candles' => $candles]);
            $response = ['total_trades' => 0, 'trade_ledger' => [], 'displayed_trade_count' => 0,
                'trade_ledger_hash' => $owner->hash([]), 'data_manifest' => ['sha256' => $run->fresh()->data_hash],
                'decision_trace' => array_map(fn ($index): array => ['candle_index' => $index,
                    'candle_time' => $candles[$index]['time'], 'event_type' => 'signal_evaluation', 'action' => 'WAIT', 'accepted' => false], range(200, 202)),
                'data_quality' => ['decision_trace' => ['protocol' => 'candle_decision_trace_v1',
                    'requested' => true, 'complete' => true, 'event_count' => 3, 'evaluated_candle_count' => 3]]];
            $owner->finishRun($run, 'completed', $response);
            $this->assertNotNull($verify->invoke($service, $run->fresh()), json_encode($owner->learningEligibility($run->fresh())));
            $runs[] = $run;
        }
        // Both edits preserve a sibling difference; neither edit can inherit the old replay organism.
        foreach (array_slice($arms, 0, 2) as $arm) $arm->modelVersion->update(['parameters' => [...$arm->modelVersion->parameters, 'other_gene' => 2]]);
        foreach ($runs as $run) $this->assertNull($verify->invoke($service, $run->fresh()));
        foreach (array_slice($arms, 0, 2) as $arm) $arm->modelVersion->update([
            'parameters' => $originalParameters[$arm->model_version_id],
            'metadata' => ['execution_contract' => ['parameters' => ['spread_points' => 99]]],
        ]);
        foreach ($runs as $run) $this->assertNull($verify->invoke($service, $run->fresh()));
    }

    public function test_equal_caps_and_measured_replay_cpu_do_not_invent_search_efficiency(): void
    {
        $service = app(ExperimentQualityProgressService::class);
        $contract = ['protocol' => 'causal_equal_budget_observation_v1', 'fold_count' => 9,
            'per_arm_fold_seconds_limit' => 180, 'max_rows_per_fold' => 4096,
            'arm_ids' => ['memory_enabled' => 1, 'memory_blinded' => 2, 'frozen_control' => 3]];
        $assessment = ['equal_preregistered_caps' => true, 'actual_per_arm_cpu_attested' => true,
            'resource_scope' => 'economic_replay_only_excludes_shared_features_and_audit',
            'arm_resources' => ['memory_enabled' => ['cpu_seconds' => 10], 'memory_blinded' => ['cpu_seconds' => 20]]];
        $comparison = $service->memoryComparison(['after_cost_expectancy_r' => 2], ['after_cost_expectancy_r' => 1], $assessment, $contract);
        $this->assertSame('measured_diagnostic', $comparison['status']);
        $this->assertTrue($comparison['equal_preregistered_replay_caps']);
        $this->assertFalse($comparison['equal_observed_replay_cpu']);
        $this->assertSame('equal_caps_observed_replay_cpu_differs', $comparison['fairness_status']);
        $this->assertSame(.5, $comparison['guided_to_blinded_cpu_ratio']);
        $this->assertSame('not_measured', $comparison['search_efficiency']['status']);
        $this->assertNull($comparison['search_efficiency']['repeated_error_reduction']);
        $this->assertFalse($comparison['selector_search_computation_measured']);
        $this->assertFalse($comparison['independent_selector_superiority_proven']);
        $this->assertFalse($comparison['promotion_evidence']);

        data_set($assessment, 'arm_resources.memory_blinded.cpu_seconds', 10);
        $equal = $service->memoryComparison([], [], $assessment, $contract);
        $this->assertTrue($equal['equal_observed_replay_cpu']);
        $this->assertFalse($equal['independent_selector_superiority_proven']);
        $unsealed = $service->memoryComparison([], [], $assessment);
        $this->assertFalse($unsealed['equal_preregistered_replay_caps']);
        $assessment['resource_scope'] = 'invented_search_cpu';
        $unmeasured = $service->memoryComparison([], [], $assessment, $contract);
        $this->assertSame('actual_arm_compute_not_measured', $unmeasured['status']);
        $this->assertNull($unmeasured['guided_cpu_seconds']);
    }

    public function test_descendant_flags_without_an_executed_ablation_do_not_count_as_retention(): void
    {
        $generation = $this->generation();
        $agents = $this->arms($generation, 'retained');
        $evidence = ['confirmed_component_only' => true, 'trait_incremental_over_ablation' => true,
            'improved_over_mentor' => true, 'other_gene_mutated' => true, 'forward_gate_passed' => true,
            'inherited_failure' => false, 'constraint_violations' => 0, 'independent_windows' => 3,
            'ablated_child_model_version_id' => $agents[1]->model_version_id,
            'contextual_control_comparison' => ['eligible' => true],
            'contextual_trait_ablation_comparison' => ['eligible' => true], 'context_trust' => ['status' => 'probation'],
            'trait_capsule_hash' => 'capsule', 'activation_context_hash' => 'context', 'instrument_bundle_hash' => 'bundle'];
        DB::table('descendant_value_trials')->insert(['trial_key' => 'flag-only-trial',
            'mentor_model_version_id' => $agents[2]->model_version_id, 'child_model_version_id' => $agents[0]->model_version_id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'window_key' => 'window',
            'status' => 'settled', 'evidence' => json_encode($evidence), 'settled_at' => now()]);
        $service = app(ExperimentQualityProgressService::class);
        $this->assertTrue($service->retainedBenefit($evidence));
        $this->assertFalse($service->retainedTrialVerified(DB::table('descendant_value_trials')->sole()));
        $snapshot = $service->snapshot($generation);
        $this->assertSame(0, data_get($snapshot, 'retained_benefit.distinct_traits'));
        $this->assertSame(0, data_get($snapshot, 'progress_outcomes.retained_beneficial_changes.distinct_traits'));
    }

    public function test_preregistered_native_benchmark_settles_measured_fold_resources_into_generation_report(): void
    {
        $generation = $this->generation();
        $arms = $this->arms($generation, 'native-benchmark');
        $ids = array_map(fn ($arm): int => $arm->id, $arms);
        $experiment = AgentLearningCausalExperiment::create(['experiment_key' => 'native-benchmark',
            'lab_generation_id' => $generation->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'gene_key' => 'gene',
            'guided_agent_id' => $ids[0], 'blinded_agent_id' => $ids[1], 'control_agent_id' => $ids[2],
            'status' => 'ready_for_replay']);
        $selectorReceipt = $this->selectorReceipt($arms);
        $release = app(ResearchReleaseSealService::class)->seal($generation)->trigger_context['research_release'];
        $request = ['research_release' => $release, 'replay_dataset_hash' => str_repeat('a', 64),
            'execution_contract' => ['execution_hash' => str_repeat('b', 64)],
            'policy_context' => ['learning_confirmation_contracts' => array_fill_keys($ids,
                ['fold_universe_count' => 3, 'per_fold_budget_seconds' => 180, 'max_rows_per_fold' => 4096])],
            'strategies' => array_map(fn ($id): array => ['lab_agent_id' => $id], $ids)];
        $benchmark = app(TypedInstrumentFoundryService::class);
        $this->assertSame('planned', $benchmark->registerCausalBenchmark($experiment, $request)['status']);
        $this->assertSame('BENCHMARK_COMPLETE_FOLD_SET_REQUIRED', $benchmark->settleCausalBenchmark($experiment, [])['reason']);
        $workerReceipt = ['protocol' => 'research_worker_release_receipt_v1', 'loaded_code_attested' => true,
            'release_hash' => $release['release_hash'], 'source_hash' => $release['python_source_hash'],
            'boot_source_hash' => $release['python_source_hash']];
        $aggregate = ['leaderboard' => array_map(fn ($id): array => ['lab_agent_id' => $id,
            'result' => ['total_trades' => 8, 'trade_ledger' => array_fill(0, 8, ['profit_r' => $id === $ids[0] ? .5 : .25]),
                'profit_factor' => $id === $ids[0] ? 2 : 1.1,
                'after_cost_expectancy_r' => $id === $ids[0] ? .5 : .25,
                'data_quality' => ['research_release_receipt' => $workerReceipt],
                'benchmark' => ['arm_replay_resources' => ['protocol' => 'arm_replay_resources_v1',
                    'scope' => 'economic_replay_only_excludes_shared_features_and_audit',
                    'cpu_seconds' => $id === $ids[0] ? 2 : 4, 'wall_seconds' => $id === $ids[0] ? 3 : 5]]]], $ids)];
        $folds = collect();
        foreach (range(1, 3) as $index) $folds->push(CausalFoldReceipt::create([
            'receipt_key' => 'native-measured-'.$index, 'agent_learning_causal_experiment_id' => $experiment->id,
            'lab_generation_id' => $generation->id, 'fold_index' => $index, 'fold_count' => 3,
            'status' => 'completed', 'observed_at' => now(), 'completed_at' => now(),
            'request_payload' => $request, 'request_hash' => $this->hash($request),
            'response_payload' => $aggregate, 'response_hash' => $this->hash($aggregate),
            'dataset_hash' => $request['replay_dataset_hash'], 'execution_hash' => str_repeat('b', 64)]));
        $settlement = $benchmark->settleCausalBenchmark($experiment, $aggregate);
        $this->assertSame('executed_diagnostic', $settlement['status']);
        $this->assertTrue($settlement['assessment']['actual_per_arm_cpu_attested']);
        $this->assertSame(6, data_get($settlement, 'assessment.arm_resources.memory_enabled.cpu_seconds'));
        $experiment->update(['evidence' => ['fold_execution' => ['compute_comparison' => $settlement,
            'settlement' => ['status' => 'completed', 'atomic' => true, 'fold_count' => 3,
                'receipt_ids' => $folds->pluck('id')->all(), 'receipt_hashes' => $folds->pluck('response_hash')->all(),
                'aggregate_hash' => $this->hash($aggregate)]]]]);
        $snapshot = app(ExperimentQualityProgressService::class)->snapshot($generation->fresh());
        $comparison = $snapshot['memory_vs_blinded_efficiency']['comparisons'][0];
        $this->assertSame(6.0, $comparison['guided_cpu_seconds']);
        $this->assertSame(12.0, $comparison['blinded_cpu_seconds']);
        $this->assertTrue($comparison['equal_preregistered_replay_caps']);
        $this->assertTrue($comparison['selector_wall_time_measured']);
        $this->assertSame($selectorReceipt['question_key'], data_get($comparison, 'search_efficiency.question_key'));
        $this->assertSame(1, data_get($comparison, 'search_efficiency.arms.memory_enabled.first_local_powered_target_candidate.fold_index'));
        $this->assertEquals(2.0, data_get($comparison, 'search_efficiency.arms.memory_enabled.first_local_powered_target_candidate.cumulative_replay_cpu_seconds'));
        $this->assertNull(data_get($comparison, 'search_efficiency.arms.memory_blinded.first_local_powered_target_candidate'));
        $this->assertFalse(data_get($comparison, 'search_efficiency.selector_cpu_measured'));
        $this->assertSame(1, $snapshot['memory_vs_blinded_efficiency']['measured_selector_question_count']);
        $this->assertSame('equal_caps_observed_replay_cpu_differs', $comparison['fairness_status']);
        $this->assertFalse($snapshot['memory_vs_blinded_efficiency']['memory_superiority_proven']);
        $this->assertSame(1, data_get($snapshot, 'progress_outcomes.technically_completed_experiments.count'));
        $this->assertSame(0, data_get($snapshot, 'progress_outcomes.question_answering_comparisons.count'));
        $this->assertFalse($settlement['assessment']['compounding_proven']);
        $this->assertFalse($snapshot['promotion_evidence']);
    }

    public function test_search_registration_rejects_changed_arm_or_blinded_memory_exposure(): void
    {
        $generation = $this->generation();
        $arms = $this->arms($generation, 'selector-drift');
        $ids = array_map(fn ($arm): int => $arm->id, $arms);
        $experiment = AgentLearningCausalExperiment::create(['experiment_key' => 'selector-drift',
            'lab_generation_id' => $generation->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'gene_key' => 'atr_target_multiplier',
            'guided_agent_id' => $ids[0], 'blinded_agent_id' => $ids[1], 'control_agent_id' => $ids[2], 'status' => 'ready_for_replay']);
        $receipt = $this->selectorReceipt($arms);
        $release = app(ResearchReleaseSealService::class)->seal($generation)->trigger_context['research_release'];
        $request = ['research_release' => $release, 'replay_dataset_hash' => str_repeat('a', 64),
            'execution_contract' => ['execution_hash' => str_repeat('b', 64)],
            'policy_context' => ['learning_confirmation_contracts' => array_fill_keys($ids,
                ['fold_universe_count' => 3, 'per_fold_budget_seconds' => 180, 'max_rows_per_fold' => 4096])]];
        $service = app(TypedInstrumentFoundryService::class);
        $changed = $arms[0]->modelVersion->parameters;
        $changed['atr_target_multiplier'] += .1;
        $arms[0]->modelVersion->update(['parameters' => $changed]);
        $this->assertSame('BENCHMARK_SELECTOR_OBSERVATION_INVALID', $service->registerCausalBenchmark($experiment, $request)['reason']);
        $this->selectorReceipt($arms);
        data_set($receipt, 'arms.memory_blinded.memory_input_ids', ['lesson_id' => 1]);
        $receipt['receipt_hash'] = app(ExecutionContractService::class)->hashParameters(array_diff_key($receipt, ['receipt_hash' => true]));
        foreach ($arms as $arm) $arm->modelVersion->update(['metadata' => ['portfolio_council_lane' => ['causal_learning_cohort' => ['memory_search_receipt' => $receipt]]]]);
        $this->assertSame('BENCHMARK_SELECTOR_OBSERVATION_INVALID', $service->registerCausalBenchmark($experiment, $request)['reason']);
        $this->assertDatabaseCount('research_compounding_benchmarks', 0);
    }

    public function test_confirmation_routes_do_not_treat_a_future_plan_as_completed_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04', 'UTC'));
        config()->set('services.instrument_policy.authorized_research_windows', []);
        $readiness = app(ExperimentQualityProgressService::class)->confirmationRouteReadiness();
        $this->assertContains('causal_confirmation', $readiness['historical_causal_research']['allowed_uses']);
        $this->assertSame('existing_frozen_causal_contract', $readiness['historical_causal_research']['policy']);
        $this->assertSame('awaiting_authorized_research_data', data_get($readiness, 'post_paper_independent_validation.status'));
        $this->assertSame(0, data_get($readiness, 'post_paper_independent_validation.completed_authorized_window_count'));
        $this->assertFalse(data_get($readiness, 'post_paper_independent_validation.future_plans_are_data'));
        $this->assertFalse(data_get($readiness, 'post_paper_independent_validation.independence_or_execution_proven_by_registry_count'));
    }

    private function generation(): LabGeneration
    {
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'name' => 'Progress fixture',
            'strategy_families' => ['hybrid'], 'is_active' => false]);
        return LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'test', 'status' => 'completed', 'population_size' => 3]);
    }

    private function arms(LabGeneration $generation, string $key): array
    {
        return array_map(function ($index) use ($generation, $key): LabAgent {
            $model = ModelVersion::create(['name' => $key.'-'.$index, 'strategy' => 'hybrid', 'version' => $key.'-'.$index,
                'generation' => 1, 'status' => 'testing', 'parameters' => ['gene' => $index]]);
            return LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'completed']);
        }, [1, 2, 3]);
    }

    private function experiment(LabGeneration $generation, string $key, string $outcome, bool $complete = true): AgentLearningCausalExperiment
    {
        $arms = $this->arms($generation, $key);
        $experiment = AgentLearningCausalExperiment::create(['experiment_key' => $key, 'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'gene_key' => 'gene',
            'guided_agent_id' => $arms[0]->id, 'blinded_agent_id' => $arms[1]->id, 'control_agent_id' => $arms[2]->id, 'status' => 'provisional']);
        $folds = collect();
        foreach (range(1, $complete ? 3 : 2) as $index) {
            $request = ['fold_index' => $index, 'replay_dataset_hash' => str_repeat('a', 64),
                'execution_contract' => ['execution_hash' => str_repeat('b', 64)]];
            $response = ['leaderboard' => array_map(fn ($arm): array => ['lab_agent_id' => $arm->id,
                'result' => ['total_trades' => 0, 'trade_ledger' => []]], $arms)];
            $folds->push(CausalFoldReceipt::create(['receipt_key' => $key.'-'.$index,
                'agent_learning_causal_experiment_id' => $experiment->id, 'lab_generation_id' => $generation->id,
                'fold_index' => $index, 'fold_count' => 3, 'status' => 'completed', 'completed_at' => now(), 'observed_at' => now(),
                'request_payload' => $request, 'request_hash' => $this->hash($request),
                'response_payload' => $response, 'response_hash' => $this->hash($response),
                'dataset_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64)]));
        }
        $experiment->update(['evidence' => ['fold_execution' => ['settlement' => ['status' => 'completed', 'atomic' => true,
            'fold_count' => 3, 'receipt_ids' => $folds->pluck('id')->all(), 'receipt_hashes' => $folds->pluck('response_hash')->all(),
            'aggregate_hash' => str_repeat('c', 64)]]]]);
        $quality = ['status' => $outcome, 'observations' => array_fill_keys(['guided', 'blinded', 'control'],
            ['complete' => true, 'data_present' => true, 'paired_probe_window_valid' => true])];
        if ($outcome === 'harmful') $experiment->update(['evidence' => [...$experiment->evidence,
            'causal_arm_parity' => ['passed' => true], 'causal_power' => ['status' => 'powered'],
            'component_effect' => ['protocol' => 'paired_disjoint_window_delta_v1', 'protocol_verified' => true,
                'power_contract_applied' => true, 'common_window_count' => 3, 'required_common_windows' => 3,
                'mean_delta' => -.2, 'window_deltas' => array_map(fn ($index): array =>
                    ['window_id' => 'w'.$index, 'treatment' => 1.0, 'baseline' => 1.2, 'delta' => -.2], range(1, 3)),
                'target_effect' => ['status' => 'not_improved'], 'passed' => false]]]);
        app(ResearchExperimentConversionKernelService::class)->record([
            'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => AgentLearningCausalExperiment::class, 'id' => $experiment->id],
            'scope' => ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5'],
            'identity' => ['baseline_epoch_hash' => 'baseline-'.$key, 'data_and_mtf_hash' => 'data',
                'runtime_and_contract_hash' => 'runtime', 'intervention_hash' => $key, 'window_plan_hash' => 'window', 'evaluator_version' => 'test'],
            'arms' => array_map(fn ($arm): array => ['role' => 'fixture', 'agent_id' => $arm->id], $arms),
        ], $quality, $outcome === 'underpowered' ? 'UNDERPOWERED' : ($outcome === 'harmful' ? 'HARMFUL' : 'UNREACHABLE'),
            [], ['code' => 'FIXTURE_TERMINAL']);
        return $experiment->fresh();
    }

    private function hash(array $value): string
    {
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) ksort($value);
            foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $canonical($item);
            return $value;
        };
        return hash('sha256', json_encode($canonical($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function selectorReceipt(array $arms): array
    {
        $schema = app(StrategyParameterSchemaService::class);
        $baseline = $schema->defaults('hybrid');
        $guided = app(CausalBlindedMutationSelectorService::class)->select('hybrid', 'profit_factor', $baseline, 'guided-fixture');
        $blinded = app(CausalBlindedMutationSelectorService::class)->select('hybrid', 'profit_factor', $baseline, 'blind-fixture');
        $this->assertNotNull($guided);
        $this->assertNotNull($blinded);
        $hashes = app(ExecutionContractService::class);
        $receipt = ['protocol' => 'causal_selector_observation_v1', 'question_key' => $hashes->hashParameters(['native-fixture', $baseline]),
            'baseline_parameter_hash' => $hashes->hashParameters($baseline),
            'legal_mutation_space_hash' => $hashes->hashParameters(['schema' => $schema->schema('hybrid'), 'baseline' => $baseline, 'target' => 'profit_factor']),
            'seed' => 'fixture', 'minimum_distinct_questions' => 5, 'per_selector_admission_seconds_limit' => 30,
            'timing_scope' => 'eligible_lesson_and_cartridge_lookup_vs_cold_start_mutation_selection',
            'shared_source_and_cohort_preparation_excluded' => true, 'blinded_guided_treatment_exclusion' => false,
            'arms' => ['memory_enabled' => ['wall_seconds' => .01, 'within_admission_budget' => true,
                'selected_gene' => $guided['gene'], 'old_value' => $guided['old_value'], 'value' => $guided['value'],
                'memory_input_ids' => ['lesson_id' => 1]],
                'memory_blinded' => ['wall_seconds' => .02, 'within_admission_budget' => true,
                    'selected_gene' => $blinded['gene'], 'old_value' => $blinded['old_value'], 'value' => $blinded['value'], 'memory_input_ids' => []]],
            'independent_validation_proven' => false, 'memory_superiority_proven' => false, 'promotion_evidence' => false];
        $receipt['receipt_hash'] = $hashes->hashParameters($receipt);
        foreach ($arms as $index => $arm) {
            $parameters = $baseline;
            if ($index < 2) {
                $selection = $index === 0 ? $guided : $blinded;
                $parameters[$selection['gene']] = $selection['value'];
            }
            $arm->modelVersion->update(['parameters' => $parameters,
                'metadata' => ['portfolio_council_lane' => ['causal_learning_cohort' => ['memory_search_receipt' => $receipt]]]]);
        }
        return $receipt;
    }
}
