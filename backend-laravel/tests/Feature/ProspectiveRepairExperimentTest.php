<?php

namespace Tests\Feature;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\ActivationValidationPlanService;
use App\Services\CausalLearningCohortPlannerService;
use App\Services\CausalScreeningBehaviorPreflightService;
use App\Services\ExperimentQualityProgressService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Services\StrategyTacticRiskCompositionPlannerService;
use App\Services\CompositionAuthorityKernelService;
use App\Services\ProspectiveRepairExperimentService;
use App\Services\StrategyParameterSchemaService;
use App\Services\StrategySemanticGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProspectiveRepairExperimentTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_context_quotes_require_the_consumed_window_and_original_scope_not_global_coverage(): void
    {
        $service = app(\App\Services\CausalScreeningBehaviorPreflightService::class);
        $window = ['evaluated_rows' => 200, 'evaluated_start' => '2025-06-02T08:00:00Z',
            'evaluated_end' => '2025-06-02T09:00:00Z'];
        $receipt = [...$window, 'protocol' => 'exact_context_quote_quality_v1',
            'source_context_hash' => 'scope-hash', 'source_provenance_valid' => true,
            'before_liquidity_veto' => true, 'modeled_spread_is_not_observed' => true,
            'promotion_evidence' => false, 'context_rows' => 20, 'observed_rows' => 20, 'coverage' => 1];
        $this->assertTrue($service->contextQuotesReady($receipt, $window, 'scope-hash', .95));
        $this->assertFalse($service->contextQuotesReady([...$receipt, 'observed_rows' => 0, 'coverage' => 0,
            'global_coverage' => .99], $window, 'scope-hash', .95));
        foreach (['evaluated_rows' => 201, 'evaluated_start' => '2025-01-01T00:00:00Z',
            'source_context_hash' => 'other-scope', 'source_provenance_valid' => false,
            'before_liquidity_veto' => false, 'coverage' => .98, 'observed_rows' => 21,
            'context_rows' => -1, 'promotion_evidence' => true] as $key => $value) {
            $this->assertFalse($service->contextQuotesReady([...$receipt, $key => $value], $window, 'scope-hash', .95), $key);
        }
        // No matching context is low power, not an invented 100% quote sample.
        $this->assertTrue($service->contextQuotesReady([...$receipt, 'context_rows' => 0,
            'observed_rows' => 0, 'coverage' => null], $window, 'scope-hash', .95));
        $this->assertFalse($service->contextQuotesReady([...$receipt, 'context_rows' => 0,
            'observed_rows' => 0, 'coverage' => 1], $window, 'scope-hash', .95));
    }

    private function observations(): array
    {
        $base = ['complete' => true, 'data_present' => true, 'context_opportunities' => 30,
            'accepted_entries' => 10, 'signal_decision_hash' => 'signal', 'event_ledger_hash' => 'event',
            'trade_ledger_hash' => 'control'];
        return ['control' => $base, 'guided' => [...$base, 'trade_ledger_hash' => 'guided'],
            'blinded' => [...$base, 'trade_ledger_hash' => 'blinded']];
    }

    public function test_missing_data_and_rare_context_never_become_negative_skill_or_no_effect(): void
    {
        $service = app(CausalScreeningBehaviorPreflightService::class);
        $rows = $this->observations(); $rows['guided']['data_present'] = false;
        $this->assertSame('data_missing', $service->learnability($rows)['status']);
        $this->assertFalse($service->learnability($rows)['negative_skill_allowed']);
        $this->assertSame('data_missing', $service->learnability([])['status']);
        $rows = $this->observations();
        foreach ($rows as &$row) { $row['context_opportunities'] = 0; $row['trade_ledger_hash'] = 'same'; }
        $this->assertSame('underpowered', $service->learnability($rows)['status']);
        $rows = $this->observations();
        $rows['guided']['data_present'] = false;
        $rows['guided']['scope_receipt_valid'] = false;
        $missingScope = $service->learnability($rows);
        $this->assertSame('data_missing', $missingScope['status']);
        $this->assertContains('GUIDED_EXACT_CONTEXT_RECEIPT_MISSING_OR_MISMATCHED', $missingScope['reason_codes']);
    }

    public function test_behavior_power_and_economic_confirmation_are_different_gates(): void
    {
        $service = app(CausalScreeningBehaviorPreflightService::class);
        $rows = $this->observations();
        $this->assertSame('ready_for_independent_validation', $service->learnability($rows)['status']);
        $rows['guided']['trade_ledger_hash'] = 'control';
        $rows['guided']['signal_decision_hash'] = 'only-signal-changed';
        $this->assertSame('no_effect', $service->learnability($rows)['status']);
        $rows = $this->observations(); $rows['blinded']['accepted_entries'] = 3;
        $this->assertSame('underpowered', $service->learnability($rows)['status']);
        $this->assertFalse($service->learnability($rows)['economic_selection']);
    }

    private function sourcePair(): LabLearningLanePair
    {
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'name' => 'Prospective repair',
            'strategy_families' => ['hybrid'], 'is_active' => true]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'population_size' => 2, 'status' => 'screened', 'trigger_type' => 'test',
            'trigger_context' => ['mtf_bundle_hash' => str_repeat('a', 64)]]);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('hybrid');
        $parameters['transition_wait_candles'] = 4;
        $arms = [];
        foreach (['candidate', 'control'] as $role) {
            $values = $parameters;
            if ($role === 'candidate') $values['transition_wait_candles'] = 5;
            $model = ModelVersion::create(['name' => 'repair-'.$role, 'strategy' => 'hybrid', 'version' => 'v1',
                'generation' => 1, 'status' => 'testing', 'parameters' => $values,
                'metadata' => ['last_screen_result' => ['total_trades' => 3, 'profit_factor' => 2.31],
                    'semantic_group' => app(StrategySemanticGroupService::class)->descriptor('XAUUSD', 'H1', 'hybrid', [
                        'regime' => 'trend_up', 'volatility' => 'normal_volatility',
                    ]),
                    'instrument_research_assignment' => ['selected' => [['activation_contract' => ['context' => [
                        'declared_context' => ['regime' => 'trend_up', 'volatility' => 'normal',
                            'session' => 'london', 'venue_phase' => 'london_pre_am_fix']]]]]]]]);
            $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test',
                'lifecycle_status' => 'screened', 'parameter_diff' => $role === 'candidate'
                    ? ['transition_wait_candles' => ['old' => 4, 'new' => 5]] : []]);
            $evidence = app(LabImmutableEvidenceService::class);
            $run = $evidence->beginRun($agent, 'screening', 'screen', ['source' => 'prospective-test']);
            $evidence->attachRequest($run, ['symbol' => 'XAUUSD', 'timeframe' => 'M5',
                'candles' => [['time' => '2025-12-01T00:00:00Z', 'close' => 2000]]], ['request_id' => 'repair-'.$role]);
            $ledger = $role === 'candidate' ? array_fill(0, 3, ['profit_percent' => 1,
                'entry_time' => '2025-12-01T00:00:00Z', 'exit_time' => '2025-12-01T00:05:00Z']) : [];
            $evidence->finishRun($run, 'completed', ['total_trades' => count($ledger), 'profit_factor' => 2.31,
                'trade_ledger' => $ledger, 'trades' => $ledger,
                'displayed_trade_count' => count($ledger), 'trade_ledger_hash' => hash('sha256', json_encode($ledger)),
                'decision_trace' => [['candle_time' => '2025-12-01T00:00:00Z', 'action' => 'WAIT', 'accepted' => false]],
                'data_quality' => ['decision_trace' => ['requested' => true, 'complete' => true, 'evaluated_candle_count' => 1]]]);
            $this->assertTrue($evidence->learningEligibility($run->fresh())['complete']);
            $arms[$role] = $agent;
        }
        $controlMap = LabMutationResponseMap::create(['response_key' => hash('sha256', 'source-control-map'),
            'lab_agent_id' => $arms['control']->id, 'stage' => 'screening', 'status' => 'control',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'target' => 'temporal_stability',
            'metadata' => ['control_contract' => ['protocol' => 'frozen_control_v2', 'control_only' => true,
                'role' => 'control', 'generation_id' => $generation->id, 'data_hash' => str_repeat('a', 64),
                'execution_hash' => str_repeat('b', 64)]]]);
        return LabLearningLanePair::create(['pair_key' => hash('sha256', 'prospective-test'),
            'lab_generation_id' => $generation->id, 'candidate_agent_id' => $arms['candidate']->id,
            'control_agent_id' => $arms['control']->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'control_response_map_id' => $controlMap->id,
            'strategy_family' => 'hybrid', 'target' => 'temporal_stability', 'baseline_source' => 'control',
            'status' => 'paired', 'pair_integrity_status' => 'verified', 'same_generation' => true,
            'candidate_data_hash' => str_repeat('a', 64), 'control_data_hash' => str_repeat('a', 64),
            'candidate_execution_hash' => str_repeat('b', 64), 'control_execution_hash' => str_repeat('b', 64)]);
    }

    public function test_promising_screen_freezes_one_new_three_arm_program_without_relabeling_source(): void
    {
        $pair = $this->sourcePair(); $service = app(ProspectiveRepairExperimentService::class);
        $source = $service->eligible('XAUUSD', 'H1', $pair->id);
        $this->assertNotNull($source);
        $this->assertSame(str_repeat('a', 64), $source['source_mtf_bundle_hash']);
        $this->assertTrue($source['old_control_is_not_treatment_proof']);
        $planned = app(CausalLearningCohortPlannerService::class)->materialize($service->seedPlan($source),
            'XAUUSD', 'H1', 2);
        $this->assertSame('materialized', $planned['contract']['status']);
        $this->assertCount(3, $planned['plan']);
        $this->assertCount(1, collect($planned['plan'])->pluck('niche.composition_passport.passport_hash')->unique());
        foreach ($planned['plan'] as $slot) {
            $contract = $slot['niche']['causal_learning_cohort'];
            $this->assertSame('screening_hypothesis_only', $contract['source_authority']);
            $this->assertNull($contract['source_lesson_id']);
            $this->assertFalse($contract['validation_plan']['executable']);
            $this->assertSame($source['source_mtf_bundle_hash'],
                $contract['validation_plan']['source_mtf_bundle_hash']);
            $this->assertSame(15000, $contract['probe_policy']['training_tail_rows']);
            $this->assertSame('london_pre_am_fix', $slot['niche']['venue_phase']);
        }
        $this->assertSame(0, data_get($planned['plan'][1], 'niche.causal_learning_cohort.blinded_selector.memory_inputs'));
        $this->assertDatabaseCount('agent_learning_causal_experiments', 0);
        $pair->candidateAgent->modelVersion->update(['parameters' => ['transition_wait_candles' => 6]]);
        $this->assertNull($service->eligible('XAUUSD', 'H1', $pair->id));
    }

    public function test_source_refuses_a_mtf_bundle_hash_that_disagrees_with_the_exact_pair(): void
    {
        $pair = $this->sourcePair();
        $service = app(ProspectiveRepairExperimentService::class);
        $this->assertNotNull($service->eligible('XAUUSD', 'H1', $pair->id));
        $pair->generation->update(['trigger_context' => ['mtf_bundle_hash' => str_repeat('c', 64)]]);
        $this->assertNull($service->eligible('XAUUSD', 'H1', $pair->id));
    }

    public function test_constructor_refuses_a_different_prospective_source_than_the_arbiter_selected(): void
    {
        $pair = $this->sourcePair();
        $source = app(ProspectiveRepairExperimentService::class)->eligible('XAUUSD', 'H1', $pair->id);
        $this->assertNotNull($source);

        $population = app(LabPopulationService::class);
        $this->assertNull($population->build('XAUUSD', 'learning_confirmation', false, 'H1',
            prospectiveExpectation: ['source_pair_id' => $pair->id, 'source_hash' => str_repeat('0', 64)]));
        $this->assertSame('PROSPECTIVE_REPAIR_SELECTED_SOURCE_CHANGED',
            $population->lastBuildOutcome()['reason_code']);
        $this->assertDatabaseCount('lab_generations', 1);
    }

    public function test_prospective_constructor_reserves_only_the_exact_three_arms(): void
    {
        $pair = $this->sourcePair();
        $source = app(ProspectiveRepairExperimentService::class)->eligible('XAUUSD', 'H1', $pair->id);
        $this->assertNotNull($source);
        $population = app(LabPopulationService::class);
        $generation = $population->build('XAUUSD', 'learning_confirmation', false, 'H1',
            prospectiveExpectation: ['source_pair_id' => $pair->id, 'source_hash' => $source['source_hash']]);
        $this->assertNotNull($generation, json_encode($population->lastBuildOutcome()));
        $this->assertSame(3, (int) $generation->population_size);
        $this->assertSame(3, $generation->agents()->count());
        $this->assertSame(3, $generation->agents()->whereHas('modelVersion', fn ($query) => $query
            ->where('metadata->causal_learning_cohort->experiment_kind', ProspectiveRepairExperimentService::KIND))->count());
        $this->assertTrue((bool) data_get(app(\App\Services\GenerationConstructionAdmissionService::class)
            ->inspect($generation), 'allowed'));
        $agent = $generation->agents()->with('modelVersion')->firstOrFail();
        $job = new \App\Jobs\EvaluateLabAgentJob($agent->id, 'XAUUSD', 'screen');
        $this->assertTrue($job->prospectiveProbe);
        $this->assertSame(2100, $job->timeout);
        $mutex = collect($job->middleware())->first(fn ($middleware): bool =>
            $middleware instanceof \Illuminate\Queue\Middleware\WithoutOverlapping);
        $this->assertSame(2700, $mutex->expiresAfter);
        $method = new \ReflectionMethod(\App\Services\LabAgentEvaluationService::class,
            'replaySpecialistContextContract');
        $method->setAccessible(true);
        $scope = $method->invoke(app(\App\Services\LabAgentEvaluationService::class), $agent->modelVersion);
        $this->assertSame('prospective_repair_exact_context_v1', $scope['protocol']);
        $this->assertSame('london_pre_am_fix', $scope['venue_phase']);
        $this->assertSame(data_get($agent->modelVersion->metadata, 'causal_learning_cohort.source_context_hash'),
            $scope['source_context_hash']);
    }

    public function test_two_distinct_pre_replay_failures_allow_only_one_final_sealed_attempt(): void
    {
        $pair = $this->sourcePair();
        $service = app(ProspectiveRepairExperimentService::class);
        $source = $service->source($pair);
        $this->assertNotNull($source);
        foreach ([
            'Technical quarantine: strict lab preflight failed (NON_EXACT_SEMANTIC_PARENT).',
            'Generation construction incomplete; candidate quarantined before replay and strategy verdict withheld.',
        ] as $index => $reason) {
            $generation = LabGeneration::create(['ai_laboratory_id' => $pair->generation->ai_laboratory_id,
                'generation' => $index + 2, 'population_size' => 3, 'status' => 'technical_quarantine',
                'trigger_type' => 'learning_confirmation']);
            $arms = [];
            foreach (range(1, 3) as $number) {
                $model = $pair->controlAgent->modelVersion->replicate();
                $model->name = 'unobserved-'.$index.'-'.$number;
                $model->save();
                $arm = $pair->controlAgent->replicate();
                $arm->lab_generation_id = $generation->id;
                $arm->model_version_id = $model->id;
                $arm->lifecycle_status = 'technical_quarantine';
                $arm->decision_reason = $reason;
                $arm->save();
                $arms[] = $arm->id;
            }
            AgentLearningCausalExperiment::create(['experiment_key' => hash('sha512', 'unobserved-'.$index),
                'lab_generation_id' => $generation->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'strategy_family' => 'hybrid', 'gene_key' => 'transition_wait_candles',
                'target' => 'temporal_stability', 'guided_agent_id' => $arms[0],
                'blinded_agent_id' => $arms[1], 'control_agent_id' => $arms[2],
                'status' => 'invalid_counterfactual_contract',
                'evidence' => ['experiment_kind' => ProspectiveRepairExperimentService::KIND,
                    'source_pair_id' => $pair->id, 'prospective_source_hash' => str_repeat((string) ($index + 1), 64)]]);
        }
        $retry = $service->eligible('XAUUSD', 'H1', $pair->id);
        $this->assertNotNull($retry);
        $this->assertSame((int) AgentLearningCausalExperiment::latest('id')->value('id'),
            (int) $retry['technical_retry_of_experiment_id']);
        $this->assertNotSame($source['source_hash'], $retry['source_hash']);

        // Once that final attempt exists, the same source can never open an
        // unbounded fourth generation, even if it fails before any replay.
        $third = AgentLearningCausalExperiment::where('evidence->source_pair_id', $pair->id)->latest('id')->firstOrFail();
        $copy = $third->replicate();
        $copy->experiment_key = hash('sha512', 'unobserved-third');
        $copy->save();
        $this->assertNull($service->eligible('XAUUSD', 'H1', $pair->id));
    }

    public function test_unobserved_780_second_control_timeout_allows_one_versioned_transport_repair_only(): void
    {
        $pair = $this->sourcePair();
        $service = app(ProspectiveRepairExperimentService::class);
        foreach ([
            'Technical quarantine: strict lab preflight failed (NON_EXACT_SEMANTIC_PARENT).',
            'Generation construction incomplete; candidate quarantined before replay and strategy verdict withheld.',
            'Frozen control admission failed before screening; strategy verdict withheld: FROZEN_CONTROL_REPLAY_INCOMPLETE.',
        ] as $index => $reason) {
            $generation = LabGeneration::create(['ai_laboratory_id' => $pair->generation->ai_laboratory_id,
                'generation' => $index + 2, 'population_size' => 3, 'status' => 'technical_quarantine',
                'trigger_type' => 'learning_confirmation']);
            $arms = [];
            foreach (range(1, 3) as $number) {
                $model = $pair->controlAgent->modelVersion->replicate();
                $model->name = 'timeout-repair-'.$index.'-'.$number;
                $model->save();
                $arm = $pair->controlAgent->replicate();
                $arm->lab_generation_id = $generation->id;
                $arm->model_version_id = $model->id;
                $arm->lifecycle_status = 'technical_quarantine';
                $arm->decision_reason = $index === 2 && $number === 3
                    ? 'Frozen same-generation recovery contract is unavailable; replay is impossible without changing data. Strategy verdict withheld; terminal technical history.'
                    : $reason;
                $arm->save();
                $arms[] = $arm;
            }
            AgentLearningCausalExperiment::create(['experiment_key' => hash('sha512', 'timeout-repair-'.$index),
                'lab_generation_id' => $generation->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'strategy_family' => 'hybrid', 'gene_key' => 'transition_wait_candles',
                'target' => 'temporal_stability', 'guided_agent_id' => $arms[0]->id,
                'blinded_agent_id' => $arms[1]->id, 'control_agent_id' => $arms[2]->id,
                'status' => 'invalid_counterfactual_contract',
                'evidence' => ['experiment_kind' => ProspectiveRepairExperimentService::KIND,
                    'source_pair_id' => $pair->id, 'prospective_source_hash' => str_repeat((string) ($index + 1), 64)]]);
            if ($index === 2) {
                $run = LabEvaluationRun::create(['run_id' => (string) \Illuminate\Support\Str::uuid(),
                    'lab_generation_id' => $generation->id, 'lab_agent_id' => $arms[2]->id,
                    'model_version_id' => $arms[2]->model_version_id, 'phase' => 'screening',
                    'mode' => 'incremental', 'status' => 'technical_error', 'duration_ms' => 802569,
                    'error_message' => '{"detail":"Bounded AI replay exceeded 780s; strategy verdict withheld."}',
                    'request_meta' => ['payload' => ['policy_context' => ['prospective_probe_window' => [
                        'evaluator_version' => 'incremental_probe_window_v1', 'evaluated_rows' => 15000]]]],
                    'metrics' => ['total_trades' => null],
                    'response_meta' => ['decision_trace_present' => false, 'trade_ledger_complete' => false,
                        'displayed_trade_count' => 0]]);
            }
        }
        $retry = $service->eligible('XAUUSD', 'H1', $pair->id);
        $this->assertNotNull($retry);
        $this->assertSame((int) AgentLearningCausalExperiment::latest('id')->value('id'),
            (int) $retry['technical_retry_of_experiment_id']);

        $run->update(['metrics' => ['total_trades' => 1]]);
        $this->assertNull($service->eligible('XAUUSD', 'H1', $pair->id));
        $run->update(['metrics' => ['total_trades' => null]]);
        $fourth = AgentLearningCausalExperiment::latest('id')->firstOrFail()->replicate();
        $fourth->experiment_key = hash('sha512', 'timeout-repair-fourth');
        $fourth->save();
        $this->assertNull($service->eligible('XAUUSD', 'H1', $pair->id));
    }

    public function test_discovery_closure_is_idempotent_and_cannot_authorize_reused_or_paper_data(): void
    {
        $pair = $this->sourcePair(); $service = app(ProspectiveRepairExperimentService::class);
        $source = $service->eligible('XAUUSD', 'H1', $pair->id);
        $plan = app(ActivationValidationPlanService::class)->reserve(['hypothesis_key' => $source['source_hash'],
            'source_data_hash' => $source['source_data_hash'], 'source_response_hash' => str_repeat('c', 64),
            'source_execution_hash' => $source['source_execution_hash']]);
        $blindModel = $pair->candidateAgent->modelVersion->replicate(); $blindModel->name = 'repair-blinded'; $blindModel->save();
        $blinded = $pair->candidateAgent->replicate(); $blinded->model_version_id = $blindModel->id; $blinded->save();
        $experiment = AgentLearningCausalExperiment::create(['experiment_key' => hash('sha512', 'new-repair'),
            'lab_generation_id' => $pair->lab_generation_id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'gene_key' => 'transition_wait_candles', 'target' => 'temporal_stability',
            'guided_agent_id' => $pair->candidate_agent_id, 'blinded_agent_id' => $blinded->id,
            'control_agent_id' => $pair->control_agent_id, 'status' => 'ready_for_replay',
            'evidence' => ['experiment_kind' => ProspectiveRepairExperimentService::KIND,
                'source_pair_id' => $pair->id, 'prospective_source_hash' => $source['source_hash'],
                'prospective_source_data_hash' => $source['source_data_hash'], 'prospective_validation_plan' => $plan]]);
        $projected = (array) $pair->candidateAgent->modelVersion->metadata;
        data_set($projected, 'last_screen_result.data_quality', ['mtf_stack' => ['status' => 'ready'],
            'spread_quality' => ['protocol' => 'historical_quote_spread_quality_v1',
                'provider_observed' => true, 'coverage' => 1.0]]);
        $pair->candidateAgent->modelVersion->update(['metadata' => $projected]);
        $first = $service->closeDiscovery($experiment); $second = $service->closeDiscovery($experiment->fresh());
        $this->assertSame($first, $second);
        $this->assertSame('data_missing', $first['status']);
        $this->assertFalse(data_get($first, 'quality.observations.guided.data_present'));
        $this->assertSame('provisional', $experiment->fresh()->status);
        $this->assertNull($experiment->fresh()->confirmed_at);
        $this->assertDatabaseCount('research_experiment_receipts', 1);
        $this->assertDatabaseCount('research_experiment_work_items', 1);
        $this->assertDatabaseHas('research_experiment_work_items', ['status' => 'blocked']);
        $this->assertDatabaseHas('research_experiment_work_items', ['work_type' => 'activation_new_opportunity_window']);
        $this->assertDatabaseHas('research_knowledge_entries', ['knowledge_type' => 'EPISODIC']);
        $this->assertFalse($first['causal_credit']);
        $this->assertNull($service->eligible('XAUUSD', 'H1', $pair->id));
        $callback = app(\App\Services\CausalLearningConfirmationService::class)->recordOutcome(
            $pair->candidateAgent, $pair, ['total_trades' => 100], ['profit_factor' => 100]);
        $this->assertSame('independent_validation_required', $callback['status']);
        $this->assertFalse($callback['confirmed']);
        $this->assertSame('provisional', $experiment->fresh()->status);
        $this->assertSame('SCREENING_DISCOVERY_IS_NOT_INDEPENDENT_VALIDATION',
            app(\App\Services\CausalSkillCreditBridgeService::class)->settle($experiment)['reason_code']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_real_constructor_enrolls_the_exact_triplet_without_fabricating_a_lesson(): void
    {
        $pair = $this->sourcePair(); $service = app(ProspectiveRepairExperimentService::class);
        $source = $service->eligible('XAUUSD', 'H1', $pair->id);
        $generation = LabGeneration::create(['ai_laboratory_id' => $pair->generation->ai_laboratory_id,
            'generation' => 2, 'population_size' => 3, 'status' => 'draft', 'trigger_type' => 'learning_confirmation',
            'data_fingerprint' => str_repeat('a', 64)]);
        $materialized = $service->materialize($service->seedPlan($source), 'XAUUSD', 'H1', $generation->id);
        $plan = collect($materialized['plan'])->map(function ($slot) {
            $slot['niche']['composition_passport'] = app(CompositionAuthorityKernelService::class)->bindLearningExperiment(
                $slot['niche']['composition_passport'], $slot['niche']['causal_learning_cohort']);
            return $slot;
        })->all();
        $plan = app(StrategyTacticRiskCompositionPlannerService::class)->bindRuntimeOwnership($plan)['plan'];
        $generation->update(['trigger_context' => ['adaptive_evolution_policy' => [
            'causal_learning_counterfactual_cohort' => $materialized['contract']]]]);
        $population = app(LabPopulationService::class);
        $constructor = new \ReflectionMethod($population, 'createAgent');
        foreach ($plan as $i => $slot) {
            $failure = null;
            $args = [$generation->fresh('laboratory'), $slot['family'], $slot['origin'], $i + 1,
                $slot['target'], $slot['niche'], null, null, 0, &$failure];
            $this->assertTrue($constructor->invokeArgs($population, $args), (string) $failure);
        }
        $experiment = AgentLearningCausalExperiment::where('lab_generation_id', $generation->id)->firstOrFail();
        $this->assertSame('ready_for_replay', $experiment->status,
            json_encode(data_get($experiment->evidence, 'construction_validation')));
        $this->assertNull($experiment->source_lesson_id);
        $this->assertSame(3, $generation->agents()->count());
        foreach ($generation->agents()->with('modelVersion')->get() as $arm) {
            $this->assertSame(ProspectiveRepairExperimentService::KIND,
                data_get($arm->modelVersion->metadata, 'causal_learning_cohort.experiment_kind'));
            $this->assertSame(15000, data_get($arm->modelVersion->metadata, 'causal_learning_cohort.probe_policy.training_tail_rows'));
        }
        $this->assertDatabaseCount('agent_learning_lessons', 0);
        $parity = app(\App\Services\CausalArmParityService::class)->assess($experiment->fresh());
        $this->assertTrue($parity['passed'], json_encode($parity['reason_codes']));
        foreach ($parity['arms'] as $arm) {
            $this->assertSame('transition_wait_candles', $arm['sealed_treatment_gene']);
        }
    }

    public function test_progress_does_not_invent_memory_superiority_or_retained_traits(): void
    {
        $service = app(ExperimentQualityProgressService::class);
        $this->assertSame('actual_arm_compute_not_measured', $service->memoryComparison(
            ['after_cost_expectancy_r' => 1], ['after_cost_expectancy_r' => .5], [])['status']);
        $this->assertFalse($service->retainedBenefit(['confirmed_component_only' => true,
            'improved_over_mentor' => true, 'trait_capsule_hash' => 'hash', 'independent_windows' => 3]));
        $candidate = ['confirmed_component_only' => true,
            'trait_incremental_over_ablation' => true, 'improved_over_mentor' => true,
            'trait_capsule_hash' => 'hash', 'independent_windows' => 3,
            'other_gene_mutated' => true, 'forward_gate_passed' => true,
            'activation_context_hash' => 'context', 'instrument_bundle_hash' => 'bundle',
            'inherited_failure' => false, 'constraint_violations' => 0];
        $this->assertFalse($service->retainedBenefit($candidate));
        $this->assertTrue($service->retainedBenefit([...$candidate,
            'ablated_child_model_version_id' => 42,
            'contextual_control_comparison' => ['eligible' => true],
            'contextual_trait_ablation_comparison' => ['eligible' => true],
            'context_trust' => ['status' => 'probation']]));
        $this->assertFalse($service->retainedBenefit([...$candidate,
            'ablated_child_model_version_id' => 42,
            'contextual_control_comparison' => ['eligible' => true],
            'contextual_trait_ablation_comparison' => ['eligible' => false],
            'context_trust' => ['status' => 'probation']]));
    }
}
