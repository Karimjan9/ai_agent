<?php

namespace Tests\Feature;

use App\Models\LabEvaluationRun;
use App\Models\ModelVersion;
use App\Models\SpecialistCouncilVersion;
use App\Services\ExecutionContractService;
use App\Services\InstrumentResearchWindowService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ProspectiveRepairProbeWindowService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilContractService;
use App\Services\SpecialistCouncilLifecycleService;
use App\Services\TypedInstrumentFoundryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Native producers execute; authorization fixtures are conditional tests, never live-market proof. */
class SpecialistCouncilSupportRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_original_native_risk_proof_qualifies_and_is_consumed_without_trader_or_paper_authority(): void
    {
        [$version, $assessment, $runIds] = $this->completedNativeExam();
        $proof = $assessment['support_role_qualifications']['bounded-risk'];
        $this->assertSame('research_role_qualified', $proof['status'], json_encode($assessment['reason_codes']).' '.json_encode($proof));
        $this->assertSame(3, $proof['positive_independent_windows']);
        $this->assertFalse($proof['economic_skill_proven']);
        $this->assertFalse($proof['paper_authority_granted']);
        // 110 hourly rows do not satisfy the trader's independent sample power;
        // support risk evidence uses actual calls/size deltas, not PF/trade count.
        $this->assertFalse($assessment['qualified']);
        $this->assertSame([], $assessment['qualified_roles']);
        $this->assertCount(9, $runIds);
        foreach ($proof['comparisons'] as $comparison) {
            $this->assertTrue($comparison['positive_role_capability']);
            $this->assertGreaterThan(0, $comparison['observations']['candidate']['evaluations']);
            $this->assertGreaterThan(0, $comparison['observations']['candidate']['behavior_delta_decisions']);
            $this->assertCount(3, $comparison['original_run_ids']);
        }
        $owner = app(SpecialistCouncilLifecycleService::class);
        $parent = $owner->qualifiedOriginalResearchProof($version->fresh());
        $this->assertFalse($parent['allowed'], 'Real native risk correctness is not a qualified whole-council parent.');
        $this->assertFalse($parent['paper_authority_granted']);
        $this->assertSame('ORIGINAL_RESEARCH_PARENT_PROOF_INVALID_OR_UNQUALIFIED', $parent['reason']);
        $nativeQuestion = $owner->nativePolicyQuestionOutcome(['version_id' => $version->id,
            'window_key' => array_key_first($this->windows), 'source_hash' => LabEvaluationRun::where('run_id', $runIds[0])->sole()->code_hash],
            now()->toIso8601String(), 'independent-examiner');
        $this->assertSame('ORIGINAL_NATIVE_POLICY_COMPARATORS_AND_RETENTION_REQUIRED', $nativeQuestion['reason'],
            'Real support-only producer arms must not be upgraded into an absent whole-question benchmark.');
        $binding = $owner->researchSupportBinding($version->fresh(), 'bounded-risk');
        $this->assertSame('scoped_research_component_only', $binding['authority']);
        $this->assertSame($proof['qualification_hash'], $binding['qualification_hash']);
        $manifest = $version->manifest;
        $manifest['version'] = '2';
        $manifest['support_role_consumptions'] = [['source_version_id' => $version->id,
            'component_id' => 'bounded-risk', 'qualification_hash' => $proof['qualification_hash']]];
        $child = $owner->registerDraft($manifest, 'next-research-owner');
        $this->assertSame('draft', $child->state);
        $this->assertSame($version->id, $child->manifest['support_role_consumptions'][0]['source_version_id']);
        $owner->runtimeContract($child, str_repeat('d', 64), $this->execution()['execution_hash'], 'H1', null, 'XAUUSD');
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $wide = $manifest; $wide['version'] = 'wide';
        $wide['members'][0]['scope']['symbols'][] = 'EURUSD';
        try {
            $owner->registerDraft($wide, 'next-research-owner');
            $this->fail('A confirmed research component escaped its original instrument scope.');
        } catch (\LogicException $error) {
            $this->assertSame('SUPPORT_ROLE_CONSUMPTION_SCOPE_WIDENED', $error->getMessage());
        }

        // Source drift cannot be hidden by copying a previously approved binding.
        $run = LabEvaluationRun::where('run_id', $runIds[0])->sole();
        $run->forceFill(['response_hash' => str_repeat('0', 64)])->save();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SUPPORT_ROLE_ORIGINAL_PROOF_NO_LONGER_VALID');
        $owner->runtimeContract($child, str_repeat('d', 64), $this->execution()['execution_hash'], 'H1', null, 'XAUUSD');
    }

    public function test_historical_native_role_evidence_never_becomes_independent_qualification(): void
    {
        [$version, $assessment] = $this->completedNativeExam('research');
        $proof = $assessment['support_role_qualifications']['bounded-risk'];
        $this->assertSame('dependency', $proof['status']);
        $this->assertSame(0, $proof['positive_independent_windows']);
        $this->assertContains('SUPPORT_ROLE_AUTHORIZED_INDEPENDENT_WINDOWS_REQUIRED', $proof['reason_codes']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SUPPORT_ROLE_NOT_INDEPENDENTLY_QUALIFIED');
        app(SpecialistCouncilLifecycleService::class)->researchSupportBinding($version->fresh(), 'bounded-risk');
    }

    public function test_a_called_but_behaviorally_noop_native_operator_does_not_qualify(): void
    {
        [$version, $assessment] = $this->completedNativeExam('independent', 1.0);
        $proof = $assessment['support_role_qualifications']['bounded-risk'];
        $this->assertSame('dependency', $proof['status']);
        $this->assertSame(0, $proof['positive_independent_windows']);
        $this->assertContains('SUPPORT_ROLE_ACTUAL_BEHAVIOR_CONTRIBUTION_NOT_OBSERVED', $proof['reason_codes']);
        $this->assertFalse($proof['paper_authority_granted']);
    }

    public function test_role_trial_rejects_asserted_flags_and_unregistered_operator_identity(): void
    {
        [$version] = $this->draft();
        $contracts = app(SpecialistCouncilContractService::class);
        try {
            $contracts->sealSupportRoleTrials($version->manifest, [[...$this->trial(), 'qualified' => true]]);
            $this->fail('Asserted qualification was admitted.');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('SUPPORT_ROLE_TRIAL_ASSERTED_OUTCOMES_FORBIDDEN', $error->getMessage());
        }
        DB::table('research_instrument_programs')->delete();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SUPPORT_ROLE_REGISTERED_OPERATOR_REQUIRED');
        $contracts->sealSupportRoleTrials($version->manifest, [$this->trial()]);
    }

    public function test_support_trial_requires_original_control_and_retention_before_outcomes(): void
    {
        [$version] = $this->draft();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SUPPORT_ROLE_ORIGINAL_CANDIDATE_ABLATION_RETENTION_REQUIRED');
        app(SpecialistCouncilLifecycleService::class)->sealEvaluationPlan($version, 'independent-examiner', [
            'purpose' => 'research', 'execution_hash' => $this->execution()['execution_hash'], 'execution_timeframe' => 'H1',
            'initial_capital' => 10000, 'cost_model' => $this->execution()['parameters'], 'risk_policy' => ['risk_per_trade_percent' => .5],
            'windows' => [['window_key' => 'one', 'start_inclusive' => '2025-01-01T00:00:00Z',
                'end_exclusive' => '2025-01-02T00:00:00Z', 'dataset_sha256' => str_repeat('d', 64)]],
            'arms' => [['arm_key' => 'candidate', 'kind' => 'candidate', 'window_key' => 'one',
                'model_version_id' => $version->manifest['members'][0]['model_version_id']]], 'support_role_trials' => [$this->trial()]]);
    }

    public function test_missing_server_role_adapter_is_dependency_not_a_typed_flag_or_shared_pnl_certificate(): void
    {
        [$version] = $this->draft();
        $manifest = $version->manifest;
        $manifest['components'][0]['role'] = 'learning';
        unset($manifest['components'][0]['contract_hash'], $manifest['members'][0]['operator_contract']);
        $manifest = app(SpecialistCouncilContractService::class)->sealManifest($manifest);
        $trial = app(SpecialistCouncilContractService::class)->sealSupportRoleTrials($manifest, [[
            ...$this->trial(), 'role' => 'learning', 'benchmark' => 'native_memory_selector']])[0];
        $method = new \ReflectionMethod(SpecialistCouncilLifecycleService::class, 'nativeSupportObservation');
        $proof = $method->invoke(app(SpecialistCouncilLifecycleService::class), $trial, [
            'receipt' => ['metrics' => ['net_profit' => 999999], 'qualified' => true], 'runtime' => []]);
        $this->assertSame(['SUPPORT_ROLE_ORIGINAL_BENCHMARK_ADAPTER_REQUIRED:native_memory_selector'], $proof['reason_codes']);
        $this->assertSame(0, $proof['evaluations']);
    }

    public function test_original_native_account_and_event_use_qualify_correctness_not_learned_pnl_or_broker_competence(): void
    {
        [$version, $assessment] = $this->completedNativeExam('independent', .5, true);
        foreach (['allocator', 'execution', \App\Services\SpecialistCouncilDataUseService::PROTOCOL] as $id) {
            $proof = $assessment['support_role_qualifications'][$id];
            $this->assertSame('research_role_qualified', $proof['status'], json_encode($proof));
            $this->assertSame(3, $proof['positive_independent_windows']);
            $this->assertFalse($proof['causal_component_value_proven']);
            $this->assertFalse($proof['economic_skill_proven']);
            $this->assertFalse($proof['paper_authority_granted']);
            $this->assertSame('native_candle_correctness_only_not_learned_economic_value',
                $proof['comparisons'][0]['observations']['candidate']['qualification_scope']);
        }
        $this->assertGreaterThan(0, DB::table('specialist_council_data_uses')->where('use', 'evaluation')->count());
        $this->assertFalse($assessment['qualified']);
    }

    public function test_original_equal_budget_policy_benchmark_is_callable_and_bound_but_never_self_qualifies(): void
    {
        [$source] = $this->draft();
        $portfolio = app(\App\Services\ResearchKnowledgePortfolioService::class);
        $policy = $portfolio->registerPolicy(['weights' => ['expected_value' => 1, 'information_gain' => 1], 'compute_cap_seconds' => 180]);
        $challenger = $portfolio->proposePolicyVariants($policy['knowledge_key'])['variants'][0]['policy'];
        $manifest = $source->manifest; $manifest['version'] = 'policy-benchmark';
        unset($manifest['members'][0]['operator_contract']);
        $manifest['components'] = [['id' => $policy['knowledge_key'], 'version' => '1', 'role' => 'learning',
            'input_type' => 'original_research_question', 'output_type' => 'research_ranking',
            'consumer_roles' => ['hour'], 'as_of_only' => true, 'max_compute_ms' => 100]];
        $owner = app(SpecialistCouncilLifecycleService::class);
        $version = $owner->registerDraft($manifest, 'policy-creator'); $panels = [];
        foreach (range(1, 3) as $index) {
            [$experiment, $request] = $this->policyExperiment('policy-panel-'.$index);
            $portfolio->preregisterExperiment($experiment, $request); $panels[] = [$experiment, $request];
        }
        $reference = $owner->preregisterSupportPolicyBenchmark($version, 'policy-examiner', $policy['knowledge_key'],
            [$policy['knowledge_key'], $challenger['knowledge_key']], array_map(fn ($pair) => $pair[0]->id, $panels), 'sealed-policy-seed');
        $this->assertSame('original_benchmark_preregistered', $reference['status']);
        $trial = ['component_id' => $policy['knowledge_key'], 'role' => 'learning', 'benchmark' => 'native_memory_selector',
            'original_policy_benchmark' => $reference['original_policy_benchmark']];
        $contracts = app(SpecialistCouncilContractService::class);
        try {
            $contracts->sealSupportRoleTrials($version->manifest, [[...$trial,
                'original_policy_benchmark' => [...$reference['original_policy_benchmark'], 'qualified' => true]]]);
            $this->fail('A caller-supplied benchmark outcome was accepted.');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('SUPPORT_ROLE_POLICY_BENCHMARK_ASSERTED_RESULTS_FORBIDDEN', $error->getMessage());
        }
        $arms = [];
        foreach (['candidate', 'retention', 'ablation'] as $kind) {
            $model = $owner->attachResearchModel($version, $this->model('policy-carrier-'.$kind));
            $arms[] = ['arm_key' => $kind, 'kind' => $kind, 'window_key' => 'policy-window', 'model_version_id' => $model->id,
                ...($kind === 'ablation' ? ['removed_id' => $policy['knowledge_key']] : [])];
        }
        $owner->sealEvaluationPlan($version, 'policy-examiner', [
            'purpose' => 'research', 'execution_hash' => $this->execution()['execution_hash'], 'execution_timeframe' => 'H1',
            'initial_capital' => 10000, 'cost_model' => $this->execution()['parameters'], 'risk_policy' => ['risk_per_trade_percent' => .5],
            'windows' => [['window_key' => 'policy-window', 'start_inclusive' => '2025-01-01T00:00:00Z',
                'end_exclusive' => '2025-01-02T00:00:00Z', 'dataset_sha256' => str_repeat('d', 64)]],
            'arms' => $arms, 'support_role_trials' => [$trial]]);
        $pending = $owner->settleSupportPolicyBenchmark($version->fresh(), 'policy-examiner', $policy['knowledge_key']);
        $this->assertSame('dependency', $pending['status']);
        $this->assertSame('ALL_FIXED_REAL_QUESTION_OUTCOMES_REQUIRED', $pending['benchmark']['reason']);
        foreach ($panels as $index => [$experiment, $request]) $this->completePolicyExperiment($experiment, $request, $index === 0 ? .2 : -.1);
        $result = $owner->settleSupportPolicyBenchmark($version->fresh(), 'policy-examiner', $policy['knowledge_key']);
        $this->assertSame('provisional_original_benchmark', $result['status']);
        $this->assertSame(3, $result['observation']['evaluations']);
        $this->assertCount(9, $result['observation']['original_fold_receipt_ids']);
        $this->assertCount(2, $result['observation']['same_budget_policy_scores']);
        $this->assertNull($result['observation']['selector_cpu_seconds']);
        $this->assertFalse($result['observation']['compute_advantage_proven']);
        $this->assertFalse($result['role_qualified']);
        $this->assertFalse($result['policy_activated']);
        $this->assertContains('SUPPORT_ROLE_ORIGINAL_BENCHMARK_ADAPTER_REQUIRED:native_memory_selector', $result['observation']['reason_codes']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SUPPORT_ROLE_ORIGINAL_EVALUATOR_REQUIRED');
        $owner->settleSupportPolicyBenchmark($version->fresh(), 'other-examiner', $policy['knowledge_key']);
    }

    private function policyExperiment(string $key): array
    {
        $lab = \App\Models\AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD', 'timeframe' => 'H1'],
            ['name' => 'policy-panel', 'strategy_families' => ['hybrid'], 'is_active' => false]);
        $generation = \App\Models\LabGeneration::create(['ai_laboratory_id' => $lab->id,
            'generation' => \App\Models\LabGeneration::count() + 1, 'trigger_type' => 'test', 'status' => 'draft', 'population_size' => 3]);
        $ids = [];
        foreach ([5 + (int) substr($key, -1), 6, 4] as $index => $wait) {
            $model = ModelVersion::create(['name' => $key.'-'.$index, 'strategy' => 'hybrid', 'version' => $key.'-'.$index,
                'generation' => 1, 'status' => 'testing', 'parameters' => ['wait' => $wait]]);
            $ids[] = \App\Models\LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'draft'])->id;
        }
        $experiment = \App\Models\AgentLearningCausalExperiment::create(['experiment_key' => $key, 'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'target' => 'profit_factor', 'gene_key' => 'wait',
            'guided_agent_id' => $ids[0], 'blinded_agent_id' => $ids[1], 'control_agent_id' => $ids[2], 'status' => 'planned']);
        return [$experiment, ['replay_dataset_hash' => str_repeat('a', 64), 'execution_contract' => ['execution_hash' => str_repeat('b', 64)],
            'research_release' => ['source_hash' => str_repeat('c', 64)],
            'policy_context' => ['causal_fold_job' => ['fold_count' => 3, 'per_fold_budget_seconds' => 60],
                'learning_confirmation_contracts' => ['minimum_trades' => 3]]]];
    }

    /** Conditional native-producer stubs test the adapter; they are NOT original market evidence. */
    public function test_native_policy_panel_adapter_qualifies_role_and_consumes_real_ranker_without_paper_or_cpu_claims(): void
    {
        [$version, $trial, $plan, $arms, $key, $refs, $owner] = $this->nativePolicyFixture();
        $portfolio = app(\App\Services\ResearchKnowledgePortfolioService::class);
        $result = $portfolio->settleNativePolicyChallenge($key);
        $this->assertSame(3, $result['positive_windows']);
        $this->assertFalse($result['compute_advantage_proven']);
        foreach ($result['panels'] as $panel) {
            $this->assertTrue($panel['actual_prefix_selection_changed']);
            $this->assertTrue($panel['retained_original_role_utility']);
        }
        $method = new \ReflectionMethod(SpecialistCouncilLifecycleService::class, 'assessSupportRoles');
        $proof = $method->invoke($owner, $version, $plan, $arms, [])[$trial['component_id']];
        $this->assertSame('research_role_qualified', $proof['status'], json_encode($proof));
        $this->assertSame(3, $proof['positive_independent_windows']);
        $this->assertFalse($proof['paper_authority_granted']);
        $this->assertFalse($proof['economic_skill_proven']);
        $count = DB::table('research_knowledge_entries')->count();
        $this->assertSame($proof, $method->invoke($owner, $version, $plan, $arms, [])[$trial['component_id']]);
        $this->assertSame($count, DB::table('research_knowledge_entries')->count(), 'Read-only proof verification wrote new evidence.');
        $this->assertSame($result, $portfolio->settleNativePolicyChallenge($key), 'Redelivery changed the immutable benchmark.');
        $owner->shouldReceive('researchSupportBinding')->andReturn(['role' => 'learning', 'scope' => array_column($version->manifest['members'], 'scope'),
            'authority' => 'scoped_research_component_only', 'qualification_hash' => $proof['qualification_hash']]);
        // Only this test's qualification lookup is conditional; the original declarative
        // rankResearchQuestions policy and exact frozen native inputs really execute.
        DB::table('specialist_council_evaluation_plans')->insert(['specialist_council_version_id' => $version->id,
            'evaluator_id' => 'policy-examiner', 'plan' => json_encode($plan),
            'plan_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($plan),
            'sealed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $rank = $owner->rankQualifiedResearchQuestions($version, $trial['component_id'], $refs[0]['target_cases'], 'native-seed');
        $this->assertTrue($rank['actual_policy_consumed']);
        $this->assertSame($trial['component_id'], $rank['policy_key']);
        $this->assertFalse($rank['paper_authority_granted']);
        $this->assertCount(2, $rank['ranking']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->partialMock(\App\Services\ResearchKnowledgePortfolioService::class,
            fn ($mock) => $mock->shouldReceive('rankResearchQuestions')->once()->andReturn(['status' => 'research_ranking', 'ranking' => []]));
        try {
            $owner->rankQualifiedResearchQuestions($version, $trial['component_id'], $refs[0]['target_cases'], 'native-seed');
            $this->fail('A refused/filtered ranking was reported as actual policy consumption.');
        } catch (\LogicException $error) {
            $this->assertSame('QUALIFIED_POLICY_MATCHED_OPPORTUNITY_OR_CAP_REQUIRED', $error->getMessage());
        }
    }

    public function test_native_policy_null_and_retention_regression_are_not_qualified(): void
    {
        foreach (['null', 'retention_regression'] as $variant) {
            [$version, $trial, $plan, $arms, $key, , $owner] = $this->nativePolicyFixture($variant);
            $result = app(\App\Services\ResearchKnowledgePortfolioService::class)->settleNativePolicyChallenge($key);
            $this->assertSame(0, $result['positive_windows']);
            $proof = (new \ReflectionMethod(SpecialistCouncilLifecycleService::class, 'assessSupportRoles'))
                ->invoke($owner, $version, $plan, $arms, [])[$trial['component_id']];
            $this->assertSame('dependency', $proof['status']);
            $this->assertContains('SUPPORT_ROLE_CAPABILITY_NOT_INDEPENDENTLY_REPLICATED', $proof['reason_codes']);
            $this->assertFalse($proof['paper_authority_granted']);
        }
    }

    public function test_native_policy_poisoned_original_outcomes_refuse_settlement_and_authority(): void
    {
        [, , , , $key] = $this->nativePolicyFixture('poisoned');
        $result = app(\App\Services\ResearchKnowledgePortfolioService::class)->settleNativePolicyChallenge($key);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('ORIGINAL_NATIVE_POLICY_QUESTION_DRIFT', $result['reason']);
        $this->assertSame(0, DB::table('research_knowledge_entries')
            ->where('subject_type', \App\Services\ResearchKnowledgePortfolioService::META_PROTOCOL.':native_policy_challenge_result')->count());
    }

    public function test_native_policy_no_effect_or_asserted_inputs_are_rejected_before_outcomes(): void
    {
        [, $trial, , , , $refs] = $this->nativePolicyFixture();
        $portfolio = app(\App\Services\ResearchKnowledgePortfolioService::class);
        $candidate = $portfolio->registerPolicy(['weights' => ['expected_value' => 1], 'compute_cap_seconds' => 180]);
        $ablated = $portfolio->registerPolicy(['weights' => ['expected_value' => 0], 'compute_cap_seconds' => 180]);
        $result = $portfolio->preregisterNativePolicyChallenge($candidate['knowledge_key'], $ablated['knowledge_key'], $ablated['knowledge_key'],
            'expected_value', $refs, 'native-seed', 2.5, 'policy-examiner');
        $this->assertSame('NATIVE_POLICY_AXIS_NO_ACTUAL_ORDER_EFFECT', $result['reason']);
        $bad = $refs; $bad[0]['target_cases'][0]['qualified'] = true;
        $key = $trial['original_native_policy_benchmark']['challenge_key'];
        $challenge = app(SpecialistCouncilContractService::class)->originalPolicyJournal($key, 'native_policy_challenge');
        $result = $portfolio->preregisterNativePolicyChallenge($challenge['candidate_policy_key'], $challenge['ablation_policy_key'],
            $challenge['retention_policy_key'], 'cost', $bad, 'native-seed', 2.5, 'policy-examiner');
        $this->assertSame('NATIVE_CASE_ASSERTED_FEATURES_FORBIDDEN', $result['reason']);
    }

    public function test_independent_authority_suffix_is_not_sent_as_a_new_authorization_receipt(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-01-01T00:00:00Z'));
        config(['services.instrument_policy.authorized_research_windows' => [[
            'authorization_id' => 'window-a', 'research_epoch_id' => 'post-paper',
            'purpose' => 'instrument_independent_validation',
            'start_inclusive' => '2027-01-01T00:00:00Z', 'end_exclusive' => '2027-02-01T00:00:00Z',
            'dataset_sha256' => str_repeat('a', 64),
        ]]]);
        $windows = app(InstrumentResearchWindowService::class);
        $canonical = $windows->seal('window-a', str_repeat('a', 64));
        $this->assertNotNull($canonical);
        $this->assertCount(7, $canonical);
        $this->assertTrue($windows->authorized($canonical, str_repeat('a', 64)));
        $owner = app(SpecialistCouncilLifecycleService::class);
        $method = new \ReflectionMethod(SpecialistCouncilLifecycleService::class, 'authorizedPlanWindow');
        $scoped = [...$canonical, 'evaluation_scope' => ['rows' => 15000]];
        $this->assertFalse($windows->authorized($scoped, str_repeat('a', 64)), 'Original owner must not accept an eight-field authority receipt.');
        $this->assertTrue($method->invoke($owner, $scoped, str_repeat('a', 64)));
        $this->assertFalse($method->invoke($owner, [...$scoped, 'qualified' => true], str_repeat('a', 64)));
        $this->assertFalse($method->invoke($owner, [...$scoped, 'window_key' => 'caller-relabel'], str_repeat('a', 64)));
        $this->assertFalse($method->invoke($owner, $scoped, str_repeat('c', 64)));
        [$version] = $this->draft();
        try {
            $owner->sealEvaluationPlan($version, 'independent-examiner', [
                'purpose' => 'independent', 'execution_timeframe' => 'H1', 'execution_hash' => $this->execution()['execution_hash'],
                'initial_capital' => 10000, 'cost_model' => $this->execution()['parameters'], 'risk_policy' => ['risk_per_trade_percent' => .5],
                'windows' => [[...$canonical, 'window_key' => 'caller-relabel']], 'arms' => [],
            ]);
            $this->fail('A caller renamed the server-authorized physical window.');
        } catch (\LogicException $error) {
            $this->assertSame('INDEPENDENT_WINDOW_KEY_DIFFERS_FROM_SERVER_IDENTITY', $error->getMessage());
        }
        config(['services.instrument_policy.authorized_research_windows.0.end_exclusive' => '2029-02-01T00:00:00Z']);
        $this->assertNull($windows->seal('window-a', str_repeat('a', 64)), 'Future original windows are not completed evidence.');
        $this->assertFalse($method->invoke($owner, $scoped, str_repeat('a', 64)));
        $this->assertDatabaseCount('specialist_council_evaluation_plans', 0);
    }

    public function test_original_research_parent_and_native_policy_question_reject_flags_without_original_independent_producers(): void
    {
        [$version] = $this->draft();
        $owner = app(SpecialistCouncilLifecycleService::class);
        $proof = $owner->qualifiedOriginalResearchProof($version);
        $this->assertFalse($proof['allowed']);
        $this->assertFalse($proof['economic_parent']);
        $this->assertFalse($proof['paper_authority_granted']);
        $this->assertSame('ORIGINAL_PREREGISTERED_EVALUATION_PLAN_MISSING', $proof['reason']);
        $outcome = $owner->nativePolicyQuestionOutcome(['version_id' => $version->id, 'window_key' => 'invented',
            'source_hash' => str_repeat('c', 64)], now()->toIso8601String(), 'independent-examiner');
        $this->assertSame('dependency', $outcome['status']);
        $this->assertSame('ORIGINAL_PREREGISTERED_EVALUATION_PLAN_MISSING', $outcome['reason']);
        $this->assertSame('draft', $version->fresh()->state);
        $this->assertDatabaseCount('specialist_council_evaluations', 0);
    }

    private function nativePolicyFixture(string $variant = 'positive'): array
    {
        $base = SpecialistCouncilVersion::where('council_id', 'support-council')->where('version', '1')->first();
        if (! $base) [$base] = $this->draft();
        $portfolio = app(\App\Services\ResearchKnowledgePortfolioService::class);
        $candidate = $portfolio->registerPolicy(['weights' => ['cost' => -1], 'compute_cap_seconds' => 180]);
        $ablated = $portfolio->registerPolicy(['weights' => ['cost' => 0], 'compute_cap_seconds' => 180]);
        $manifest = $base->manifest; $manifest['version'] = 'native-policy-'.$variant;
        unset($manifest['members'][0]['operator_contract']);
        $manifest['components'] = [['id' => $candidate['knowledge_key'], 'version' => '1', 'role' => 'learning',
            'input_type' => 'original_research_question', 'output_type' => 'research_ranking', 'consumer_roles' => ['hour'],
            'as_of_only' => true, 'max_compute_ms' => 100]];
        $version = app(SpecialistCouncilLifecycleService::class)->registerDraft($manifest, 'policy-creator');
        $refs = []; $specs = []; $windows = []; $offset = 0;
        foreach ([1, 3, 5] as $month) {
            $window = ['window_key' => 'native-'.$month, 'authorization_id' => 'native-'.$month,
                'start_inclusive' => '2027-'.sprintf('%02d', $month).'-01T00:00:00Z',
                'end_exclusive' => '2027-'.sprintf('%02d', $month).'-10T00:00:00Z', 'dataset_sha256' => hash('sha256', 'native-'.$month)];
            $windows[$window['window_key']] = $window; $panel = [];
            foreach (['target_cases', 'retention_cases'] as $kind) {
                $ids = [++$offset, ++$offset];
                $questions = array_map(fn ($id) => hash('sha256', 'original-question-'.$id), $ids);
                $first = strcmp($questions[0], $questions[1]) < 0 ? 0 : 1;
                foreach ($ids as $i => $id) {
                    $cheap = $i !== $first; $panel[$kind][] = ['version_id' => $id, 'window_key' => $window['window_key']];
                    $specs[$id] = ['version_id' => $id, 'window_key' => $window['window_key'], 'window' => $window,
                        'scope' => array_column($version->manifest['members'], 'scope'), 'question_hash' => $questions[$i],
                        'kind' => $kind, 'cheap' => $cheap, 'selector_input' => ['question_id' => $questions[$i], 'ready' => true,
                            'safety_preserved' => true, 'cost_ceiling_seconds' => $cheap ? 1 : 10, 'features' => ['expected_value' => 0]]];
                }
            }
            $refs[] = $panel;
        }
        foreach ($refs as $panel) {
            $inputs = array_map(fn ($case) => $specs[$case['version_id']]['selector_input'], $panel['target_cases']);
            $a = $portfolio->rankResearchQuestions($inputs, 'native-seed', $candidate['knowledge_key'], false);
            $b = $portfolio->rankResearchQuestions($inputs, 'native-seed', $ablated['knowledge_key'], false);
            $this->assertNotSame(array_column($a['ranking'], 'question_id'), array_column($b['ranking'], 'question_id'), json_encode([$inputs, $a, $b]));
        }
        $this->mock(InstrumentResearchWindowService::class, fn ($mock) => $mock->shouldReceive('authorized')->andReturn(true));
        $owner = $this->partialMock(SpecialistCouncilLifecycleService::class, function ($mock) use ($specs, $variant): void {
            $mock->shouldReceive('nativePolicyQuestionSpec')->andReturnUsing(fn ($id) => $specs[$id]);
            $mock->shouldReceive('nativePolicyQuestionOutcome')->andReturnUsing(function ($spec) use ($variant): array {
                if ($variant === 'poisoned') return ['status' => 'dependency', 'reason' => 'ORIGINAL_NATIVE_POLICY_QUESTION_DRIFT'];
                $positive = $variant !== 'null' && $spec['cheap'];
                if ($variant === 'retention_regression' && $spec['kind'] === 'retention_cases') $positive = ! $spec['cheap'];
                return ['status' => 'original_independent_question_observed', 'powered' => true, 'positive' => $positive,
                    'end_to_end_wall_seconds' => 1.5, 'original_sources' => [['run_id' => 'conditional-native-'.$spec['version_id']]]];
            });
        });
        $owner->__construct(app(SpecialistCouncilContractService::class), app(ResearchPaperEpochContractService::class), app(LabImmutableEvidenceService::class));
        $challenge = $portfolio->preregisterNativePolicyChallenge($candidate['knowledge_key'], $ablated['knowledge_key'],
            $ablated['knowledge_key'], 'cost', $refs, 'native-seed', 2.5, 'policy-examiner');
        $this->assertSame('awaiting_original_independent_native_panels', $challenge['status'], json_encode($challenge));
        $trial = app(SpecialistCouncilContractService::class)->sealSupportRoleTrials($version->manifest, [[
            'component_id' => $candidate['knowledge_key'], 'role' => 'learning', 'benchmark' => 'native_memory_selector',
            'minimum_evaluations' => 4, 'minimum_behavior_delta_decisions' => 1,
            'original_native_policy_benchmark' => ['challenge_key' => $challenge['knowledge_key']]]])[0];
        $plan = ['manifest_hash' => $version->manifest_hash, 'purpose' => 'independent', 'windows' => $windows, 'support_role_trials' => [$trial]]; $arms = [];
        foreach ($windows as $key => $window) foreach (['candidate', 'retention', 'ablation'] as $kind) {
            $arms[] = ['kind' => $kind, 'window_key' => $key, 'removed_id' => $trial['component_id'],
                'support_producer' => ['run_id' => 'conditional-'.$key.'-'.$kind, 'code_hash' => str_repeat('c', 64),
                    'runtime' => ['members' => [], 'ablation_removed_id' => $trial['component_id']],
                    'receipt' => ['status' => 'computed', 'asof_policy' => 'previous_closed_candle_next_open', 'receipt_hash' => str_repeat('d', 64)]]];
        }
        return [$version, $trial, $plan, $arms, $challenge['knowledge_key'], $refs, $owner];
    }

    /** Conditional original fold fixtures exercise the server benchmark; they are not live authority. */
    private function completePolicyExperiment(\App\Models\AgentLearningCausalExperiment $experiment, array $request, float $delta): void
    {
        $hash = fn (array $payload): string => app(ResearchPaperEpochContractService::class)->parameterHash($payload);
        $folds = collect();
        foreach (range(1, 3) as $index) {
            $items = [];
            foreach ([$experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id] as $role => $id) {
                $items[] = ['lab_agent_id' => $id, 'result' => ['profit_factor' => $role === 0 ? 1 + $delta : 1, 'total_trades' => 5]];
            }
            $response = json_decode(json_encode(['leaderboard' => $items]), true);
            $folds->push(\App\Models\CausalFoldReceipt::create(['receipt_key' => $experiment->experiment_key.'-'.$index,
                'agent_learning_causal_experiment_id' => $experiment->id, 'lab_generation_id' => $experiment->lab_generation_id,
                'fold_index' => $index, 'fold_count' => 3, 'status' => 'completed', 'completed_at' => now(), 'observed_at' => now(),
                'request_payload' => $request, 'request_hash' => $hash($request), 'response_payload' => $response, 'response_hash' => $hash($response),
                'dataset_hash' => $request['replay_dataset_hash'], 'execution_hash' => $request['execution_contract']['execution_hash']]));
        }
        $experiment->update(['evidence' => ['fold_execution' => ['settlement' => ['status' => 'completed', 'atomic' => true,
            'fold_count' => 3, 'receipt_ids' => $folds->pluck('id')->all(), 'receipt_hashes' => $folds->pluck('response_hash')->all(),
            'aggregate_hash' => str_repeat('f', 64)]]]]);
    }

    private function completedNativeExam(string $purpose = 'independent', float $multiplier = .5, bool $infrastructure = false): array
    {
        [$version] = $this->draft($multiplier, $infrastructure);
        $execution = $this->execution();
        // Only authorization/database-envelope admission is stubbed. Actual typed
        // program, native execution, account/receipt seals, ablation and budgets run.
        $this->mock(InstrumentResearchWindowService::class, function ($mock): void {
            $mock->shouldReceive('seal')->andReturnUsing(fn ($key, $hash) => $this->windows[$key]);
            $mock->shouldReceive('authorized')->andReturn(true);
        });
        $this->partialMock(LabImmutableEvidenceService::class, function ($mock): void {
            $mock->shouldReceive('learningEligibility')->andReturn(['complete' => true]);
            $mock->shouldReceive('verifiedModelRuntimeIdentity')->andReturn(['verified' => true]);
        });
        $owner = app(SpecialistCouncilLifecycleService::class);
        $paths = []; $windows = []; $arms = []; $requests = [];
        try {
            foreach ([1, 3, 5] as $month) {
                $key = 'window-'.$month; $start = CarbonImmutable::create(2025, $month, 6, 2, 0, 0, 'UTC');
                $datasetRoot = storage_path('app/lab-datasets');
                if (! is_dir($datasetRoot)) mkdir($datasetRoot, 0755, true);
                $path = tempnam($datasetRoot, 'support-native-'); $paths[] = $path;
                $file = fopen($path, 'wb'); fputcsv($file, ['time', 'open', 'high', 'low', 'close', 'volume', 'volume_available'], ',', '"', '');
                $rows = [];
                for ($index = 0; $index < 110; $index++) {
                    $price = 2000.0 + 10 * sin($index / 12) + $index * .025;
                    $time = $start->addHours($index)->toIso8601String(); $rows[] = ['time' => $time];
                    fputcsv($file, [$time, $price, $price + .5, $price - .5, $price, 100, 1], ',', '"', '');
                }
                fclose($file); $hash = hash_file('sha256', $path);
                $probe = app(ProspectiveRepairProbeWindowService::class)->seal($rows, $hash, $execution['execution_hash'], $key, 100, 10);
                $scope = ['start_inclusive' => $probe['evaluated_start'], 'end_exclusive' => $start->addHours(110)->toIso8601String(),
                    'rows' => 100, 'decision_rows' => 99, 'warmup_rows' => 10,
                    'policy_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($probe)];
                $window = ['window_key' => $key, 'authorization_id' => $key, 'start_inclusive' => $start->toIso8601String(),
                    'end_exclusive' => $scope['end_exclusive'], 'dataset_sha256' => $hash,
                    'prospective_probe_window' => $probe, 'evaluation_scope' => $scope];
                $this->windows[$key] = $window; $windows[] = $window;
                foreach (['candidate', 'retention', ...array_fill(0, count($version->manifest['components']), 'ablation')] as $armIndex => $kind) {
                    $removed = $kind === 'ablation' ? $version->manifest['components'][$armIndex - 2]['id'] : null;
                    $armKey = $kind.($removed !== null ? '-'.$removed : '').'-'.$month;
                    $carrier = $owner->attachResearchModel($version, $this->model($armKey));
                    $arms[] = ['arm_key' => $armKey, 'kind' => $kind, 'window_key' => $key,
                        'model_version_id' => $carrier->id, ...($removed !== null ? ['removed_id' => $removed] : [])];
                }
            }
            $plan = $owner->sealEvaluationPlan($version->fresh(), 'independent-examiner', [
                'purpose' => $purpose, 'execution_timeframe' => 'H1', 'execution_hash' => $execution['execution_hash'],
                'initial_capital' => 10000.0, 'cost_model' => $execution['parameters'],
                'risk_policy' => [...array_diff_key($version->manifest['execution'], ['id' => true, 'version' => true]), 'risk_per_trade_percent' => .5],
                'windows' => $windows, 'arms' => $arms, 'support_role_trials' => $this->trials($infrastructure)]);
            foreach ($plan['arms'] as $key => $arm) {
                $model = $owner->attachEvaluationArm($version->fresh(), $key, ModelVersion::findOrFail($arm['model_version_id']));
                $index = array_search($arm['window_key'], array_keys($this->windows), true);
                $window = $plan['windows'][$arm['window_key']];
                $runtime = $owner->runtimeContractForModel($model, 'H1', $window['dataset_sha256'], $execution['execution_hash'], null, 'XAUUSD');
                $requests[$key] = $owner->bindEvaluationRequest($version->fresh(), $key, [
                    'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy' => $model->strategy, 'version' => $model->version,
                    'base_strategy' => 'ema_rsi', 'dataset_path' => $paths[$index], 'initial_balance' => 10000.0,
                    'risk_per_trade' => .5, 'replay_dataset_hash' => $window['dataset_sha256'],
                    'execution_hash' => $execution['execution_hash'], 'execution' => $execution['parameters'],
                    'execution_contract' => $execution, 'specialist_council_contract' => $runtime]);
            }
            // Preserve every preregistered original arm/window. Infrastructure
            // trials have 18 arms: one monolithic test process is not their
            // execution budget. Bound each native producer call to six arms,
            // retaining the existing 50s limit and refusing missing/extra or
            // duplicate witnesses instead of accepting a shortened exam.
            $results = [];
            foreach (array_chunk($requests, 6, true) as $requestChunk) {
                $process = new Process(['python', '-c', "import json,sys; from app.schemas import SimpleBacktestRequest; from app.services.backtester import run_simple_ema_rsi_backtest; req=json.load(sys.stdin); print(json.dumps({k:run_simple_ema_rsi_backtest(SimpleBacktestRequest.model_validate(v)).model_dump(mode='json') for k,v in req.items()}))"], dirname(base_path()).'/ai-service-python');
                $process->setTimeout(50); $process->setInput(json_encode($requestChunk, JSON_THROW_ON_ERROR)); $process->mustRun();
                $chunkResults = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($chunkResults) || array_keys($chunkResults) !== array_keys($requestChunk)
                    || array_intersect_key($results, $chunkResults) !== []) {
                    throw new \LogicException('ORIGINAL_SUPPORT_ROLE_CHUNK_WITNESS_SET_MISMATCH');
                }
                $results += $chunkResults;
            }
            if (array_keys($results) !== array_keys($requests)) throw new \LogicException('ORIGINAL_SUPPORT_ROLE_COMPLETE_WITNESS_SET_MISMATCH');
            $runIds = [];
            foreach ($requests as $key => $request) {
                $arm = $plan['arms'][$key];
                $run = LabEvaluationRun::create(['run_id' => 'support-original-'.$key, 'model_version_id' => $arm['model_version_id'],
                    'phase' => $arm['evaluation_phase'], 'mode' => 'incremental', 'status' => 'running',
                    'started_at' => now(), 'request_hash' => str_repeat('b', 64),
                    'data_hash' => $request['replay_dataset_hash'], 'parameter_hash' => str_repeat('f', 64), 'code_hash' => str_repeat('e', 64)]);
                $evidence = app(LabImmutableEvidenceService::class);
                $evidence->recordArtifact($run, 'evaluation_request', $request, ['request_hash' => $run->request_hash]);
                $artifact = $evidence->recordArtifact($run, 'evaluation_response', $results[$key]);
                $run->forceFill(['status' => 'completed', 'finished_at' => now(), 'response_hash' => $artifact->sha256])->save(); $runIds[] = $run->run_id;
                if ($infrastructure) app(\App\Services\SpecialistCouncilDataUseService::class)->recordReplayUse(
                    $version->fresh(), $request, $run->run_id, $results[$key]['specialist_council_receipt']);
            }
            $assessment = $owner->evaluateOriginalRuns($version->fresh(), 'independent-examiner', $runIds);
            return [$version->fresh(), $assessment, $runIds];
        } finally {
            foreach ($paths as $path) if (is_file($path)) unlink($path);
        }
    }

    private array $windows = [];

    private function draft(float $multiplier = .5, bool $infrastructure = false): array
    {
        config(['services.execution_contract.allowed_sessions_utc' => [], 'services.execution_contract.stop_loss_percent' => 1.0,
            'services.execution_contract.take_profit_percent' => 2.0]);
        $foundry = app(TypedInstrumentFoundryService::class);
        $program = $foundry->compileResearchCandidate(['op' => 'CONST', 'type' => 'number', 'value' => $multiplier], [
            'pre_2026_only' => true, 'data_hash' => str_repeat('d', 64), 'execution_hash' => $this->execution()['execution_hash'],
            'symbol' => 'XAUUSD', 'timeframe' => 'H1'], 'support:risk-proposal');
        $operator = $foundry->decisionOperatorContract($program['program_key'], 'risk_multiplier', [], [
            'cpu_seconds' => .5, 'max_calls' => 10000, 'max_node_evaluations' => 10000])['operator'];
        $body = array_diff_key($operator, ['contract_hash' => true, 'contract_json' => true]);
        $body['component_id'] = 'bounded-risk';
        $operator = [...$body, 'contract_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($body),
            'contract_json' => json_encode($body, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
        $model = $this->model('hour-source');
        $member = ['specialist_id' => 'hour-owner', 'role' => 'hour', 'version' => '1', 'as_of' => '2024-12-31T00:00:00Z',
            'inputs' => ['as_of_closed_candles'], 'scope' => ['symbols' => ['XAUUSD'], 'contexts' => ['any']],
            'known_limits' => ['synthetic_conditional_test_not_market_authority'], 'resources' => ['max_compute_ms' => 100, 'max_memory_mb' => 32, 'max_lookback_bars' => 512],
            'horizon' => ['kind' => 'hour', 'decision_interval_seconds' => 3600, 'reevaluation_interval_seconds' => 3600, 'max_holding_seconds' => 10800],
            'data_requirements' => ['sessions', 'costs'], 'model_version_id' => $model->id, 'strategy_version' => 'strategy-1',
            'tactic_version' => 'tactic-1', 'management_version' => 'management-1', 'capital_weight' => .5, 'risk_per_trade_percent' => .1,
            'sensor_timeframes' => ['H4', 'H1', 'M15', 'M5'], 'operator_contract' => $operator];
        $component = ['id' => 'bounded-risk', 'version' => '1', 'role' => 'risk', 'input_type' => 'as_of_observation',
            'output_type' => 'risk_multiplier', 'consumer_roles' => ['hour'], 'as_of_only' => true, 'max_compute_ms' => 100];
        $components = [$component];
        if ($infrastructure) foreach (['capital' => 'allocator', 'execution' => 'execution', 'data' => \App\Services\SpecialistCouncilDataUseService::PROTOCOL] as $role => $id) {
            $components[] = [...$component, 'id' => $id, 'role' => $role, 'output_type' => $role.'_correctness'];
        }
        $version = app(SpecialistCouncilLifecycleService::class)->registerDraft(['council_id' => 'support-council', 'version' => '1',
            'members' => [$member], 'components' => $components, 'routing' => ['id' => 'router', 'version' => '1'],
            'allocation' => ['id' => 'allocator', 'version' => '1'], 'risk' => ['id' => 'external-risk', 'version' => '1'],
            'execution' => ['id' => 'execution', 'version' => '1', 'broker_position_mode' => 'hedging', 'opposite_position_policy' => 'hedge',
                'max_open_positions' => 8, 'max_reserved_capital_percent' => 100.0, 'max_gross_exposure_percent' => 100.0,
                'max_total_risk_percent' => 2.0, 'max_drawdown_percent' => 10.0, 'max_daily_loss_percent' => 3.0, 'max_expected_cost_percent' => 1.0],
            'evaluation_policy' => ['objective' => 'net_return_at_equal_risk', 'champion_model_version_id' => $model->id,
                'solo_model_version_id' => $model->id]], 'support-creator');
        return [$version];
    }

    private function trial(): array
    {
        return ['component_id' => 'bounded-risk', 'role' => 'risk', 'benchmark' => 'native_operator_risk',
            'minimum_evaluations' => 1, 'minimum_behavior_delta_decisions' => 1, 'minimum_opportunity_fraction' => .5];
    }

    private function trials(bool $infrastructure): array
    {
        $trials = [$this->trial()];
        if ($infrastructure) foreach (['capital' => 'allocator', 'execution' => 'execution', 'data' => \App\Services\SpecialistCouncilDataUseService::PROTOCOL] as $role => $id) {
            $trials[] = ['component_id' => $id, 'role' => $role,
                'benchmark' => SpecialistCouncilContractService::SUPPORT_BENCHMARKS[$role], 'minimum_evaluations' => 1];
        }
        return $trials;
    }

    private function model(string $name): ModelVersion
    {
        return ModelVersion::create(['name' => $name, 'strategy' => 'ema_rsi_v1', 'version' => $name.'-1', 'generation' => 1,
            'status' => 'testing', 'parameters' => ['ema_fast' => 4, 'ema_slow' => 10, 'rsi_period' => 4,
                'rsi_buy_min' => 1, 'rsi_buy_max' => 99, 'rsi_sell_min' => 1, 'rsi_sell_max' => 99],
            'metadata' => ['base_strategy' => 'ema_rsi']]);
    }

    private function execution(): array { return app(ExecutionContractService::class)->for('XAUUSD', 'H1'); }
}
