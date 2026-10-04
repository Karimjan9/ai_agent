<?php

namespace Tests\Feature;

use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningCausalExperiment;
use App\Models\CausalFoldReceipt;
use App\Models\AgentLearningLesson;
use App\Models\AgentLearningSettlement;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabEvaluationRun;
use App\Models\LabLearningLanePair;
use App\Models\LabLearningLaneDispatch;
use App\Jobs\EvaluateLabAgentJob;
use App\Jobs\Middleware\LabQueueAttemptEvidenceMiddleware;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\AgentKnowledgeService;
use App\Services\EvolutionGovernorService;
use App\Services\EvolutionVelocityService;
use App\Services\LabImmutableEvidenceService;
use App\Services\InstrumentResearchWindowService;
use App\Services\LearningConsolidationService;
use App\Services\LearningRetrievalService;
use App\Services\ResearchReleaseSealService;
use App\Services\TypedInstrumentFoundryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvolutionAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_stagnation_comparisons_load_four_generations(): void
    {
        config()->set('services.lab_selection.governor_lookback_generations', 3);
        config()->set('services.lab_selection.governor_stagnation_generations', 3);
        foreach (range(1, 4) as $number) {
            $row = $this->settlement($number);
            $row->episode->labAgent->update(['forward_score' => 10]);
        }
        $snapshot = app(EvolutionGovernorService::class)->generationSnapshot(AiLaboratory::firstOrFail());
        $this->assertSame(4, $snapshot['lookback_generations']);
        $this->assertSame(3, $snapshot['stagnation_generations']);
        $this->assertTrue($snapshot['stagnation_triggered']);
        $method = new \ReflectionMethod(EvolutionGovernorService::class, 'stagnationGenerations');
        $this->assertSame(0, $method->invoke(app(EvolutionGovernorService::class), collect([10, null, 10, 10])));
        $this->assertSame(0, $method->invoke(app(EvolutionGovernorService::class), collect([12, 10, 10, 10])));
    }

    public function test_repeated_same_window_and_opposite_delta_cannot_confirm_harm(): void
    {
        $service = app(LearningConsolidationService::class);
        foreach (range(1, 3) as $number) $row = $this->settlement($number, 1);
        $result = $service->consolidate($row);
        $this->assertSame('provisional', $result['status']);
        $this->assertSame(1, $result['lessons'][0]->independent_window_count);
        $opposite = $this->settlement(4, 2, 1.75);
        $result = $service->consolidate($opposite);
        $this->assertSame('provisional', $result['status']);
        $this->assertCount(1, $result['lessons'][0]->evidence['settlement_ids']);
        $this->assertCount(2, AgentLearningLesson::all());
    }

    public function test_three_independent_negative_windows_forbid_only_the_exact_delta_and_context(): void
    {
        foreach (range(1, 3) as $number) $row = $this->settlement($number);
        $service = app(LearningConsolidationService::class);
        $result = $service->consolidate($row);
        $this->assertSame('confirmed', $result['status']);
        $lesson = $result['lessons'][0];
        $this->assertSame('harmful_lesson', $lesson->lesson_type);
        $context = $row->episode->decision_context;
        $constraint = $service->harmfulConstraint($lesson, $context);
        $this->assertSame(1.5, $constraint['old_value']);
        $this->assertSame(1.25, $constraint['new_value']);
        $this->assertNull($service->harmfulConstraint($lesson, [...$context, 'venue_phase' => 'london_am_fix']));
        $packet = app(LearningRetrievalService::class)->retrieve('XAUUSD', 'H1', 'hybrid', $context);
        $this->assertSame([], $packet['blocked_mutations']);
        $this->assertCount(1, $packet['blocked_mutation_directions']);
        $this->assertSame([], app(AgentKnowledgeService::class)->blockedMutationKeys('XAUUSD', 'H1', 'hybrid', 'trend_up'));
        $lesson->update(['evidence' => ['protocol' => 'learning_kernel_v1']]);
        $this->assertNull($service->harmfulConstraint($lesson->fresh(), $context));
    }

    public function test_prospective_release_is_idempotent_and_cost_drift_is_rejected(): void
    {
        $row = $this->settlement(1);
        $generation = $row->episode->labAgent->generation;
        $service = app(ResearchReleaseSealService::class);
        $sealed = $service->seal($generation);
        $this->assertSame($sealed->trigger_context['research_release'], $service->seal($sealed)->trigger_context['research_release']);
        $this->assertSame(64, strlen($sealed->trigger_context['research_release']['python_source_hash']));
        $agent = $row->episode->labAgent;
        $agent->modelVersion->update(['metadata' => ['execution_contract' => ['spread_points' => 999]]]);
        $this->expectExceptionMessage('RESEARCH_RELEASE_EXECUTION_DRIFT');
        $service->assertCurrent($sealed->fresh());
    }

    public function test_sealed_source_drift_terminates_queue_job_once_without_replay_or_scientific_credit(): void
    {
        $row = $this->settlement(1);
        $agent = $row->episode->labAgent;
        $generation = app(ResearchReleaseSealService::class)->seal($agent->generation);
        $context = $generation->trigger_context;
        $seal = $context['research_release'];
        $seal['source_hash'] = str_repeat('0', 64);
        $identity = $seal;
        unset($identity['release_hash'], $identity['sealed_at'], $identity['promotion_evidence']);
        $seal['release_hash'] = app(\App\Services\ExecutionContractService::class)->hashParameters($identity);
        $context['research_release'] = $seal;
        $generation->update(['trigger_context' => $context]);
        $agent->update(['lifecycle_status' => 'full_queued']);
        $pair = LabLearningLanePair::where('candidate_agent_id', $agent->id)->firstOrFail();
        $pair->update(['status' => 'screen_paired']);
        $dispatch = LabLearningLaneDispatch::create([
            'dispatch_key' => hash('sha256', 'release-refusal-dispatch'),
            'pair_id' => $pair->id,
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'status' => 'queued',
        ]);
        $job = new EvaluateLabAgentJob($agent->id, 'XAUUSD', 'full');
        $middleware = new LabQueueAttemptEvidenceMiddleware;
        $called = false;
        $next = function () use (&$called): void { $called = true; };

        $middleware->handle($job, $next);
        $middleware->handle($job, $next);

        $this->assertFalse($called);
        $this->assertSame('technical_quarantine', $agent->fresh()->lifecycle_status);
        $this->assertSame('technical_quarantine', $generation->fresh()->status);
        $this->assertSame('technical_quarantine', $pair->fresh()->status);
        $this->assertSame('technical_quarantine', $dispatch->fresh()->status);
        $this->assertDatabaseCount('lab_evaluation_runs', 1);
        $run = LabEvaluationRun::firstOrFail();
        $this->assertSame('technical_error', $run->status);
        $this->assertSame('RESEARCH_RELEASE_SOURCE_DRIFT', data_get($run->metadata, 'reason_code'));
        $this->assertFalse((bool) data_get($run->metadata, 'replay_started'));
        $this->assertNull($run->request_hash);
        $this->assertNull($run->trade_ledger_hash);
        $this->assertFalse(app(LabImmutableEvidenceService::class)->learningEligibility($run)['complete']);
    }

    public function test_missing_or_stale_python_receipt_is_not_accepted_as_release_proof(): void
    {
        $row = $this->settlement(1);
        // This exercises release identity, not the separate physical authorized-window transport contract.
        config()->set('services.instrument_policy.authorized_research_windows', []);
        $agent = $row->episode->labAgent;
        $service = app(ResearchReleaseSealService::class);
        $generation = $service->seal($agent->generation);
        $seal = $generation->trigger_context['research_release'];
        $receipt = ['protocol' => 'research_worker_release_receipt_v1', 'loaded_code_attested' => true,
            'release_hash' => $seal['release_hash'], 'source_hash' => $seal['python_source_hash'],
            'boot_source_hash' => $seal['python_source_hash']];
        $this->assertTrue($service->responseValid($seal, $receipt));
        $this->assertFalse($service->responseValid($seal, []));
        $this->assertFalse($service->responseValid($seal, [...$receipt, 'boot_source_hash' => str_repeat('a', 64)]));
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($agent->fresh(), 'screening', 'incremental');
        $evidence->attachRequest($run, $service->bindRequest($run, ['evaluation_mode' => 'full', 'replay_dataset_hash' => $seal['dataset_hash'],
            'execution_contract' => (array) data_get($agent->modelVersion->metadata, 'execution_contract')]));
        $this->assertContains('RESEARCH_WORKER_RELEASE_RECEIPT_INVALID',
            $evidence->replayEvidenceCompleteness($run->fresh(), [])['reason_codes']);
        $this->assertNotContains('RESEARCH_WORKER_RELEASE_RECEIPT_INVALID',
            $evidence->replayEvidenceCompleteness($run->fresh(), ['data_quality' => ['research_release_receipt' => $receipt]])['reason_codes']);
    }

    public function test_release_cost_identity_survives_json_key_order_and_integer_projection(): void
    {
        $agent = $this->settlement(1)->episode->labAgent;
        config()->set('services.instrument_policy.authorized_research_windows', []);
        $agent->modelVersion->update(['metadata' => ['execution_contract' => [
            'timeframe' => 'H1', 'parameters' => ['spread_points' => 20.0, 'max_leverage' => 5.0]]]]);
        $service = app(ResearchReleaseSealService::class);
        $generation = $service->seal($agent->generation);
        $request = $service->bindGenerationRequest($generation, ['evaluation_mode' => 'full', 'replay_dataset_hash' => $generation->trigger_context['research_release']['dataset_hash'], 'execution_contract' => [
            'timeframe' => 'M5', 'parameters' => ['max_leverage' => 5, 'spread_points' => 20]]], [$agent->id]);
        $this->assertSame($generation->trigger_context['research_release']['release_hash'], $request['research_release']['release_hash']);
        try {
            $service->bindGenerationRequest($generation, ['evaluation_mode' => 'full', 'replay_dataset_hash' => str_repeat('f', 64),
                'execution_contract' => ['parameters' => ['max_leverage' => 5, 'spread_points' => 20]]], [$agent->id]);
            $this->fail('Drifted request dataset was accepted.');
        } catch (\RuntimeException $error) {
            $this->assertSame('RESEARCH_RELEASE_REQUEST_DATASET_DRIFT', $error->getMessage());
        }
        $this->expectExceptionMessage('RESEARCH_RELEASE_REQUEST_EXECUTION_DRIFT');
        $service->bindGenerationRequest($generation, ['evaluation_mode' => 'full', 'replay_dataset_hash' => $generation->trigger_context['research_release']['dataset_hash'], 'execution_contract' => [
            'parameters' => ['max_leverage' => 6, 'spread_points' => 20]]], [$agent->id]);
    }

    public function test_existing_run_is_not_backfilled_with_a_new_release_and_worker_drift_fails_closed(): void
    {
        $row = $this->settlement(1);
        $agent = $row->episode->labAgent;
        app(LabImmutableEvidenceService::class)->beginRun($agent, 'screening', 'incremental');
        $service = app(ResearchReleaseSealService::class);
        $this->assertNull(data_get($service->seal($agent->generation)->trigger_context, 'research_release'));
        $other = $this->settlement(2);
        $sealed = $service->seal($other->episode->labAgent->generation);
        app()->instance('research.worker_boot_source_hash', str_repeat('0', 64));
        $this->expectExceptionMessage('RESEARCH_WORKER_LOADED_RELEASE_MISMATCH');
        $service->assertCurrent($sealed);
    }

    public function test_scorecard_uses_one_generation_scope_and_counts_actual_repeat_failures(): void
    {
        $this->settlement(1);
        $current = $this->settlement(2);
        $candidate = $current->episode->labAgent;
        $map = LabMutationResponseMap::where('lab_agent_id', $candidate->id)->firstOrFail();
        $duplicate = $map->replicate();
        $duplicate->response_key = hash('sha256', 'duplicate-current');
        $duplicate->save();
        $score = app(EvolutionVelocityService::class)->snapshot(AiLaboratory::firstOrFail(), 1);
        $this->assertSame('available', $score['status']);
        $this->assertSame(1, $score['lookback_generations']);
        $this->assertSame(2, $score['population_observed']);
        $this->assertSame(2, $score['repeat_failure_observations']);
        $this->assertSame(0.5, $score['repeat_failure_rate']);
        $this->assertSame(0.0, $score['rejected_experiment_rate']);
        $this->assertSame([$candidate->lab_generation_id], $score['observed_generation_ids']);
    }

    public function test_data_readiness_does_not_turn_future_or_paper_windows_into_available_data(): void
    {
        config()->set('services.instrument_policy.authorized_research_windows', []);
        $this->assertSame('awaiting_authorized_research_data', app(InstrumentResearchWindowService::class)->readiness()['status']);
        $this->settlement(1);
        $this->travelTo(CarbonImmutable::parse('2026-09-28', 'UTC'));
        $readiness = app(InstrumentResearchWindowService::class)->readiness();
        $this->assertSame([], $readiness['eligible_windows']);
        $this->assertCount(4, $readiness['future_windows']);
        $this->assertFalse($readiness['paper_2026_eligible']);
    }

    public function test_prospective_equal_budget_runner_settles_once_without_minting_compounding_authority(): void
    {
        $row = $this->settlement(1);
        $generation = $row->episode->labAgent->generation;
        $control = $generation->agents()->orderBy('id')->firstOrFail();
        $guided = $row->episode->labAgent;
        $blindedModel = $guided->modelVersion->replicate();
        $blindedModel->name = 'blinded-benchmark';
        $blindedModel->save();
        $blinded = $guided->replicate();
        $blinded->model_version_id = $blindedModel->id;
        $blinded->save();
        $release = app(ResearchReleaseSealService::class)->seal($generation)->trigger_context['research_release'];
        $ids = [$guided->id, $blinded->id, $control->id];
        $experiment = AgentLearningCausalExperiment::create(['experiment_key' => 'benchmark-test',
            'lab_generation_id' => $generation->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'gene_key' => 'atr_stop_multiplier',
            'guided_agent_id' => $ids[0], 'blinded_agent_id' => $ids[1], 'control_agent_id' => $ids[2],
            'status' => 'awaiting_counterfactuals']);
        $contracts = array_fill_keys($ids, ['fold_universe_count' => 9, 'per_fold_budget_seconds' => 180, 'max_rows_per_fold' => 4096]);
        $request = ['research_release' => $release, 'replay_dataset_hash' => str_repeat('a', 64),
            'execution_contract' => ['execution_hash' => str_repeat('b', 64)],
            'policy_context' => ['learning_confirmation_contracts' => $contracts],
            'strategies' => array_map(fn ($id) => ['lab_agent_id' => $id], $ids)];
        $service = app(TypedInstrumentFoundryService::class);
        $plan = $service->registerCausalBenchmark($experiment, $request);
        $this->assertSame('planned', $plan['status']);
        $this->assertSame($plan, $service->registerCausalBenchmark($experiment, $request));
        $this->assertSame('BENCHMARK_COMPLETE_FOLD_SET_REQUIRED', $service->settleCausalBenchmark($experiment, [])['reason']);
        $drift = $request;
        data_set($drift, 'policy_context.learning_confirmation_contracts.'.$ids[1].'.per_fold_budget_seconds', 200);
        $this->assertSame('THREE_ARM_EQUAL_COMPUTE_CONTRACT_REQUIRED', $service->registerCausalBenchmark($experiment, $drift)['reason']);
        $receipt = ['protocol' => 'research_worker_release_receipt_v1', 'loaded_code_attested' => true,
            'release_hash' => $release['release_hash'], 'source_hash' => $release['python_source_hash'],
            'boot_source_hash' => $release['python_source_hash']];
        $aggregate = ['leaderboard' => array_map(fn ($id) => ['lab_agent_id' => $id,
            'result' => ['total_trades' => 0, 'trade_ledger' => [], 'data_quality' => ['research_release_receipt' => $receipt]]], $ids)];
        $hasher = new \ReflectionMethod(TypedInstrumentFoundryService::class, 'hash');
        foreach (range(1, 9) as $index) CausalFoldReceipt::create(['receipt_key' => 'benchmark-fold-'.$index, 'agent_learning_causal_experiment_id' => $experiment->id,
            'lab_generation_id' => $generation->id, 'fold_index' => $index, 'fold_count' => 9, 'status' => 'completed',
            'observed_at' => now(), 'completed_at' => now(),
            'request_payload' => $request, 'request_hash' => $hasher->invoke($service, $request),
            'response_payload' => $aggregate, 'response_hash' => $hasher->invoke($service, $aggregate),
            'dataset_hash' => $request['replay_dataset_hash'], 'execution_hash' => str_repeat('b', 64)]);
        $result = $service->settleCausalBenchmark($experiment, $aggregate);
        $this->assertSame('executed_diagnostic', $result['status']);
        $this->assertFalse($result['assessment']['compounding_proven']);
        $this->assertFalse($result['assessment']['actual_per_arm_cpu_attested']);
        $this->assertFalse($result['promotion_evidence']);
        $this->assertCount(9, $result['assessment']['fold_receipt_ids']);
        $progress = new \ReflectionMethod(\App\Services\CausalFoldExecutionService::class, 'recordProgress');
        $progress->invoke(app(\App\Services\CausalFoldExecutionService::class), $experiment, null);
        $this->assertSame(range(1, 9), data_get($experiment->fresh()->evidence, 'fold_execution.completed_fold_indexes'));
        $again = $service->settleCausalBenchmark($experiment, $aggregate);
        $this->assertSame('executed_diagnostic', $again['status']);
        $this->assertDatabaseCount('research_compounding_benchmarks', 1);
    }

    private function settlement(int $number, ?int $window = null, float $newValue = 1.25): AgentLearningSettlement
    {
        $window ??= $number;
        $this->travelTo(CarbonImmutable::parse('2028-01-01', 'UTC'));
        $manifests = [];
        foreach (range(1, 4) as $month) $manifests[] = ['authorization_id' => 'w'.$month,
            'research_epoch_id' => 'synthetic-research', 'purpose' => 'instrument_independent_validation',
            'start_inclusive' => sprintf('2027-%02d-01T00:00:00Z', $month),
            'end_exclusive' => sprintf('2027-%02d-01T00:00:00Z', $month + 1),
            'dataset_sha256' => hash('sha256', 'w'.$month)];
        config()->set('services.instrument_policy.authorized_research_windows', $manifests);
        $receipt = app(InstrumentResearchWindowService::class)->seal('w'.$window, hash('sha256', 'w'.$window));
        $lab = AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD', 'timeframe' => 'H1'],
            ['name' => 'Audit', 'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => $number,
            'trigger_type' => 'test', 'population_size' => 2, 'status' => 'screened',
            'trigger_context' => ['mtf_bundle_hash' => $receipt['dataset_sha256']]]);
        $agents = [];
        foreach (['control' => 1.5, 'candidate' => $newValue] as $role => $value) {
            $model = ModelVersion::create(['name' => $role.$number, 'strategy' => 'hybrid', 'version' => 'v1',
                'generation' => $number, 'status' => 'testing', 'parameters' => ['atr_stop_multiplier' => $value],
                'metadata' => ['execution_contract' => ['spread_points' => 20]]]);
            $agents[$role] = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test',
                'lifecycle_status' => 'screened', 'forward_score' => 10,
                'parameter_diff' => $role === 'candidate' ? ['atr_stop_multiplier' => ['old' => 1.5, 'new' => $newValue]] : []]);
        }
        $data = $receipt['dataset_sha256']; $execution = str_repeat('b', 64);
        $controlMap = LabMutationResponseMap::create(['response_key' => hash('sha256', 'c'.$number),
            'stage' => 'full', 'status' => 'control', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'lab_agent_id' => $agents['control']->id, 'metadata' => ['control_contract' => [
                'protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control',
                'generation_id' => $generation->id, 'data_hash' => $data, 'execution_hash' => $execution]]]);
        $map = LabMutationResponseMap::create(['response_key' => hash('sha256', 'a'.$number), 'stage' => 'full',
            'status' => 'failed', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'lab_agent_id' => $agents['candidate']->id, 'parameter_key' => 'atr_stop_multiplier',
            'direction' => $newValue < 1.5 ? 'decrease' : 'increase', 'old_value' => ['value' => 1.5], 'new_value' => ['value' => $newValue]]);
        $pair = LabLearningLanePair::create(['pair_key' => hash('sha256', 'pair'.$number),
            'lab_generation_id' => $generation->id, 'candidate_agent_id' => $agents['candidate']->id,
            'control_agent_id' => $agents['control']->id, 'candidate_response_map_id' => $map->id,
            'control_response_map_id' => $controlMap->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'baseline_source' => 'control', 'status' => 'canonical_episode_settled',
            'candidate_data_hash' => $data, 'control_data_hash' => $data,
            'candidate_execution_hash' => $execution, 'control_execution_hash' => $execution,
            'pair_integrity_status' => 'verified', 'same_generation' => true,
            'metadata' => ['instrument_research_window_receipt' => $receipt]]);
        $episode = AgentLearningEpisode::create(['episode_id' => (string) Str::uuid(), 'decision_key' => 'decision'.$number,
            'lab_agent_id' => $agents['candidate']->id, 'model_version_id' => $agents['candidate']->model_version_id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'stage' => 'full',
            'status' => 'settled', 'context_hash' => str_repeat('c', 64), 'code_hash' => str_repeat('d', 64),
            'data_hash' => $data, 'execution_hash' => $execution, 'decision_context' => [
                'regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'london', 'venue_phase' => 'london_interfix'], 'opened_at' => now()]);
        return AgentLearningSettlement::create(['settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id,
            'source_key' => 'source'.$number, 'source_type' => LabLearningLanePair::class, 'source_id' => $pair->id,
            'outcome_status' => 'settled', 'failure_class' => 'profit_factor', 'evidence_state' => 'negative',
            'hard_failure' => false, 'outcome' => ['parameter_key' => 'atr_stop_multiplier', 'control_present' => true], 'settled_at' => now()]);
    }
}
