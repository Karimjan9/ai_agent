<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\SpecialistCouncilVersion;
use App\Services\ExecutionContractService;
use App\Services\CandidateGateDecisionService;
use App\Services\LabAgentEvaluationService;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\ProspectiveRepairProbeWindowService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilLifecycleService;
use App\Services\SpecialistCouncilPreparationService;
use App\Services\SpecialistCouncilResearchFeedbackService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SpecialistCouncilPreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_atomic_native_preparation_preregisters_all_arms_without_dispatch_or_authority(): void
    {
        Queue::fake();
        [$generation, $request, $models] = $this->fixture();
        $before = $models->mapWithKeys(fn (ModelVersion $model): array => [$model->id => $model->parameters])->all();
        $receipt = app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
        $this->assertSame('prepared_for_canonical_dispatch', $receipt['status']);
        $this->assertSame('canonical_lab_dispatcher', $receipt['next_owner']);
        $this->assertSame(['candidate', 'solo', 'without-hour'], $receipt['arm_keys']);
        $this->assertFalse($receipt['promotion_evidence']);
        $this->assertFalse($receipt['paper_authority_granted']);
        $this->assertSame('research_only', $receipt['learning_consumption_receipt']['authority']);
        $this->assertFalse($receipt['learning_consumption_receipt']['confirmed_trait_inherited']);
        $this->assertSame([], $receipt['learning_consumption_receipt']['prior_feedback']);
        $sealedPlan = json_decode(\Illuminate\Support\Facades\DB::table('specialist_council_evaluation_plans')->first()->plan, true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame($receipt['learning_consumption_receipt'], $sealedPlan['learning_consumption_receipt']);
        $this->assertSame('draft', $generation->fresh()->status);
        $this->assertSame($receipt, data_get($generation->fresh()->trigger_context, 'specialist_council_preparation'));
        $this->assertDatabaseCount('specialist_council_versions', 1);
        $this->assertDatabaseCount('specialist_council_evaluation_plans', 1);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('paper_authority_admissions', 0);
        $this->assertDatabaseCount('lab_generations', 1);
        $this->assertSame('evaluating', SpecialistCouncilVersion::findOrFail($receipt['version_id'])->state);
        foreach ($models as $model) $this->assertSame($before[$model->id], $model->fresh()->parameters);
        $this->assertSame($receipt['version_id'], data_get($models[2]->fresh()->metadata, 'specialist_council.version_id'));
        $this->assertSame($receipt['version_id'], data_get($models[3]->fresh()->metadata, 'specialist_council.version_id'));
        $this->assertNull(data_get($models[0]->fresh()->metadata, 'specialist_council'));
        foreach ($request['evaluation_plan']['arms'] as $arm) {
            $model = ModelVersion::findOrFail($arm['model_version_id']);
            $binding = app(SpecialistCouncilLifecycleService::class)->evaluationBindingForModel($model, str_repeat('d', 64));
            $this->assertSame($arm['arm_key'], $binding['arm_key']);
        }
        Queue::assertNothingPushed();
    }

    public function test_identical_unused_draft_retry_is_idempotent_but_changed_request_is_not(): void
    {
        [$generation, $request] = $this->fixture();
        $owner = app(SpecialistCouncilPreparationService::class);
        $first = $owner->prepare($generation, $request);
        $this->assertSame($first, $owner->prepare($generation->fresh(), $request));
        $this->assertDatabaseCount('specialist_council_versions', 1);
        $this->assertDatabaseCount('specialist_council_evaluation_plans', 1);
        $request['manifest']['known_limit'] = 'changed-after-preregistration';
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('CANONICAL_COUNCIL_PREPARATION_RETRY_CHANGED');
        $owner->prepare($generation->fresh(), $request);
    }

    public function test_late_invalid_ablation_rolls_back_version_plan_and_every_model_binding(): void
    {
        [$generation, $request, $models] = $this->fixture();
        $request['evaluation_plan']['arms'][2]['removed_id'] = 'unsealed-component';
        try {
            app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
            $this->fail('Expected invalid preregistration to be refused.');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('Ablation must remove a sealed member or component.', $error->getMessage());
        }
        $this->assertRolledBack($generation, $models);
    }

    public function test_plan_that_cannot_bind_to_actual_execution_rolls_back_before_dispatch(): void
    {
        [$generation, $request, $models] = $this->fixture();
        $request['evaluation_plan']['cost_model']['commission_percent'] = 999;
        try {
            app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
            $this->fail('Expected actual execution parity to be required.');
        } catch (\LogicException $error) {
            $this->assertSame('COUNCIL_PLAN_COSTS_MUST_MATCH_ACTUAL_CANONICAL_EXECUTION', $error->getMessage());
        }
        $this->assertRolledBack($generation, $models);
    }

    public function test_dispatcher_and_preparation_share_one_lease(): void
    {
        [$generation, $request] = $this->fixture();
        $lease = Cache::lock("lab-generation-dispatch:{$generation->ai_laboratory_id}:H1:{$generation->id}", 300);
        $this->assertTrue($lease->get());
        try {
            app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
            $this->fail('Expected dispatch conflict to be refused.');
        } catch (\LogicException $error) {
            $this->assertSame('CANONICAL_COUNCIL_DISPATCH_LEASE_BUSY', $error->getMessage());
        } finally { $lease->release(); }
        $this->assertDatabaseCount('specialist_council_versions', 0);
    }

    public function test_snapshot_or_original_observation_prevents_prospective_binding(): void
    {
        [$generation, $request] = $this->fixture();
        $generation->forceFill(['trigger_context' => ['research_release' => ['release_hash' => str_repeat('a', 64)]]])->save();
        try {
            app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
            $this->fail('Expected sealed generation to be immutable.');
        } catch (\LogicException $error) {
            $this->assertSame('CANONICAL_COUNCIL_DRAFT_ALREADY_SEALED_OR_DISPATCHED', $error->getMessage());
        }
        $generation->forceFill(['trigger_context' => []])->save();
        LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
            'model_version_id' => $request['carrier_model_version_id'], 'phase' => 'screening', 'status' => 'started']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('CANONICAL_COUNCIL_MODEL_ALREADY_OBSERVED_OR_REUSED');
        app(SpecialistCouncilPreparationService::class)->prepare($generation->fresh(), $request);
    }

    public function test_native_model_from_another_generation_is_not_an_unused_carrier(): void
    {
        [$generation, $request] = $this->fixture();
        $foreign = $this->model('foreign');
        $request['evaluation_plan']['arms'][2]['model_version_id'] = $foreign->id;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('CANONICAL_COUNCIL_MODEL_OUTSIDE_UNUSED_DRAFT');
        app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
    }

    public function test_unpaired_or_duplicate_native_arm_cannot_be_prepared(): void
    {
        [$generation, $request] = $this->fixture();
        $missing = $request;
        array_pop($missing['evaluation_plan']['arms']);
        try {
            app(SpecialistCouncilPreparationService::class)->prepare($generation, $missing);
            $this->fail('Expected ablation preregistration to be required.');
        } catch (\LogicException $error) {
            $this->assertSame('CANONICAL_COUNCIL_RESEARCH_REQUIRES_CANDIDATE_SOLO_ABLATION', $error->getMessage());
        }
        $this->assertDatabaseCount('specialist_council_versions', 0);
        $request['evaluation_plan']['arms'][2]['model_version_id'] = $request['carrier_model_version_id'];
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SAME_NATIVE_MODEL_CANNOT_BE_TWO_ARMS_IN_ONE_WINDOW');
        app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
    }

    public function test_declared_calendar_scope_must_match_original_actual_probe(): void
    {
        [$generation, $request, $models] = $this->fixture();
        $request['evaluation_plan']['windows'][0]['evaluation_scope']['rows'] = 7;
        $request['evaluation_plan']['windows'][0]['evaluation_scope']['decision_rows'] = 6;
        try {
            app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
            $this->fail('Expected loaded/evaluated row parity to be verified before preparation.');
        } catch (\LogicException $error) {
            $this->assertSame('COUNCIL_PLAN_PROBE_NOT_SEALED_TO_ACTUAL_CALENDAR_AND_EXECUTION', $error->getMessage());
        }
        $this->assertRolledBack($generation, $models);
    }

    public function test_an_ablation_that_removes_the_last_trader_is_refused_before_dispatch(): void
    {
        [$generation, $request, $models] = $this->fixture();
        $request['manifest']['members'] = [$request['manifest']['members'][0]];
        try {
            app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
            $this->fail('Expected empty ablated council runtime to be refused.');
        } catch (\LogicException $error) {
            $this->assertSame('ABLATION_HAS_NO_TRADING_MEMBER', $error->getMessage());
        }
        $this->assertRolledBack($generation, $models);
    }

    public function test_declared_cross_asset_member_cannot_join_an_unsupported_single_account_replay(): void
    {
        [$generation, $request, $models] = $this->fixture();
        $request['manifest']['members'][1]['scope']['symbols'] = ['EURUSD'];
        try {
            app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
            $this->fail('Expected cross-asset accounting to remain an explicit dependency.');
        } catch (\LogicException $error) {
            $this->assertSame('CANONICAL_COUNCIL_SINGLE_ACCOUNT_MEMBER_SYMBOL_MISMATCH', $error->getMessage());
        }
        $this->assertRolledBack($generation, $models);
    }

    public function test_retry_rechecks_original_model_and_arm_bindings(): void
    {
        [$generation, $request, $models] = $this->fixture();
        $owner = app(SpecialistCouncilPreparationService::class);
        $owner->prepare($generation, $request);
        $model = $models[0]->fresh(); $metadata = $model->metadata;
        $metadata['specialist_council_evaluation']['arm_key'] = 'forged-solo';
        $model->forceFill(['metadata' => $metadata])->save();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('DECLARED_COUNCIL_EVALUATION_BINDING_INVALID');
        $owner->prepare($generation->fresh(), $request);
    }

    public function test_carriers_cannot_steal_a_reserved_cooperative_causal_or_academy_arm(): void
    {
        [$generation, $request, $models] = $this->fixture();
        foreach (['cooperative_experiment_block', 'causal_learning_cohort', 'academy_experiment', 'prospective_repair', 'control_pair_contract'] as $owner) {
            $model = $models[2]->fresh();
            $model->forceFill(['metadata' => ['base_strategy' => 'ema_rsi', $owner => ['protocol' => 'already-reserved']]])->save();
            try {
                app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
                $this->fail('Expected another original experiment owner to retain its arm.');
            } catch (\LogicException $error) {
                $this->assertSame('CANONICAL_COUNCIL_NATIVE_MODEL_RESERVED_FOR_ANOTHER_EXPERIMENT:'.$owner, $error->getMessage());
            }
            $this->assertDatabaseCount('specialist_council_versions', 0);
            $this->assertDatabaseCount('specialist_council_evaluation_plans', 0);
        }
        $this->assertNull(data_get($generation->fresh()->trigger_context, 'specialist_council_preparation'));
    }

    public function test_prior_research_receipts_are_consumed_once_from_the_canonical_owner(): void
    {
        [$generation, $request] = $this->fixture();
        $prior = [['receipt_id' => 91, 'receipt_key' => 'original-observation', 'classification' => 'NO_BEHAVIORAL_EFFECT',
            'assessment_hash' => str_repeat('a', 64), 'selection_authority' => 'research_only', 'promotion_evidence' => false]];
        $feedback = \Mockery::mock(app(SpecialistCouncilResearchFeedbackService::class))->makePartial();
        $this->app->instance(SpecialistCouncilResearchFeedbackService::class, $feedback);
        $feedback->shouldReceive('priorObservations')->once()->with($request['manifest']['council_id'], 8)->andReturn($prior);
        $feedback->shouldReceive('assertPriorObservations')->once()->with($request['manifest']['council_id'], $prior)->andReturnNull();
        $first = app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
        $this->assertSame($prior, $first['learning_consumption_receipt']['prior_feedback']);
        $this->assertSame(app(ResearchPaperEpochContractService::class)->parameterHash($prior), $first['prior_feedback_digest']);
        $this->assertSame($first, app(SpecialistCouncilPreparationService::class)->prepare($generation->fresh(), $request));
        $this->assertFalse($first['learning_consumption_receipt']['confirmed_trait_inherited']);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('specialist_council_versions', 1);
    }

    public function test_same_completed_question_is_a_dedup_not_a_new_experiment_or_independent_window(): void
    {
        [$generation, $request, $models] = $this->fixture();
        $feedback = \Mockery::mock(app(SpecialistCouncilResearchFeedbackService::class))->makePartial();
        $this->app->instance(SpecialistCouncilResearchFeedbackService::class, $feedback);
        $feedback->shouldReceive('completedQuestionForSource')->once()->withArgs(fn (string $question, string $source): bool =>
                preg_match('/^[a-f0-9]{64}$/', $question) === 1 && preg_match('/^[a-f0-9]{64}$/', $source) === 1)
            ->andReturn(['receipt_id' => 77, 'same_release_completed_question' => true]);
        try {
            app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
            $this->fail('Expected completed identical question to reuse original evidence instead of new compute.');
        } catch (\LogicException $error) {
            $this->assertSame('CANONICAL_COUNCIL_EXACT_COMPLETED_QUESTION_ALREADY_OBSERVED', $error->getMessage());
        }
        $this->assertRolledBack($generation, $models);
    }

    public function test_unused_draft_preparation_cannot_retry_under_changed_source(): void
    {
        [$generation, $request] = $this->fixture();
        $source = str_repeat('a', 64);
        $this->partialMock(\App\Services\LabImmutableEvidenceService::class)->shouldReceive('codeHash')
            ->andReturnUsing(function () use (&$source): string { return $source; });
        $owner = app(SpecialistCouncilPreparationService::class);
        $owner->prepare($generation, $request);
        $source = str_repeat('b', 64);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('CANONICAL_COUNCIL_PREPARATION_ORIGINAL_SOURCE_DRIFT');
        $owner->prepare($generation->fresh(), $request);
    }

    public function test_original_unbound_native_member_cannot_become_ordinary_champion_or_full_replay(): void
    {
        [$generation, $request, $models] = $this->fixture();
        app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
        $member = LabAgent::where('model_version_id', $models[1]->id)->firstOrFail();
        $this->assertNull(data_get($member->modelVersion->metadata, 'specialist_council_evaluation'));
        $gate = app(CandidateGateDecisionService::class);
        $screen = $gate->recordScreening($member, ['trade_count' => 100, 'profit_factor' => 3,
            'net_profit' => 1000, 'net_return_percent' => 10, 'max_drawdown_percent' => 1]);
        $this->assertSame('failed', $screen->decision);
        $this->assertSame(['SPECIALIST_COUNCIL_RESEARCH_ONLY'], $screen->reason_codes);
        $selection = $gate->recordFullReplaySelection($member, true);
        $this->assertSame('failed', $selection->decision);
        try {
            app(LabAgentEvaluationService::class)->evaluate($member);
            $this->fail('Unbound native source must not fall into ordinary full validation.');
        } catch (\RuntimeException $error) {
            $this->assertSame('SPECIALIST_COUNCIL_RESEARCH_ONLY_FULL_VALIDATION_FORBIDDEN', $error->getMessage());
        }
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('model_market_performance', 0);
    }

    public function test_declared_generation_preparation_tamper_throws_instead_of_falling_back_to_ordinary_selection(): void
    {
        [$generation, $request, $models] = $this->fixture();
        app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
        $context = $generation->fresh()->trigger_context;
        $context['specialist_council_preparation']['paper_authority_granted'] = true;
        $generation->forceFill(['trigger_context' => $context])->save();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('CANONICAL_COUNCIL_PREPARATION_DECLARATION_INVALID');
        app(CandidateGateDecisionService::class)->recordScreening(LabAgent::where('model_version_id', $models[1]->id)->firstOrFail(), []);
    }

    public function test_clean_discovery_is_owned_by_original_native_plan_and_screening_keeps_exact_probe(): void
    {
        [$generation, $request, $models, $rows, $bundle] = $this->discoveryFixture();
        $owner = app(SpecialistCouncilPreparationService::class);
        $receipt = $owner->prepare($generation, $request);
        $generation = $generation->fresh();
        $this->assertSame($bundle['bundle_hash'], $receipt['discovery_bundle_hash']);
        $this->assertTrue($owner->isResearchGeneration($generation));
        $this->assertTrue($owner->inspectDiscoveryOwner($generation, $bundle['manifest'])['allowed']);
        $this->assertSame($receipt, $owner->prepare($generation, $request));
        $candidate = LabAgent::where('model_version_id', $models[2]->id)->firstOrFail();
        $evaluator = app(LabAgentEvaluationService::class);
        $actualBundle = (new \ReflectionMethod($evaluator, 'replayMtfBundle'))->invoke($evaluator, $candidate, false, true);
        $this->assertSame($bundle['bundle_hash'], $actualBundle['bundle_hash']);
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $payload = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluation_mode' => 'incremental',
            'replay_dataset_hash' => $bundle['bundle_hash'], 'execution' => $execution['parameters'], 'execution_contract' => $execution,
            'specialist_council_contract' => ['policy' => $request['manifest']['execution']],
            'dataset_tail_rows' => 5000, 'policy_context' => ['historical_stratified_windows' => [['old' => true]]]];
        $payload = (new \ReflectionMethod($evaluator, 'sealCleanDiscoveryWindow'))->invoke($evaluator, $payload, $rows, $bundle);
        $this->assertNull($payload['dataset_tail_rows']);
        $this->assertSame([], $payload['policy_context']['historical_stratified_windows']);
        $payload = app(SpecialistCouncilLifecycleService::class)->bindEvaluationRequestForModel($models[2]->fresh(), $payload);
        $this->assertSame($request['evaluation_plan']['windows'][0]['prospective_probe_window'], $payload['policy_context']['prospective_probe_window']);
        $this->assertSame(15000, $payload['policy_context']['prospective_probe_window']['evaluated_rows']);
        $this->assertSame(512, $payload['policy_context']['prospective_probe_window']['warmup_rows']);
        try {
            (new \ReflectionMethod($evaluator, 'replayMtfBundle'))->invoke($evaluator, $candidate, false, false);
            $this->fail('Discovery must never become full data by using the native owner.');
        } catch (\RuntimeException $error) {
            $this->assertSame('DISCOVERY_ONLY_BUNDLE_REPLAY_SCOPE_FORBIDDEN', $error->getMessage());
        }
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_label_only_clean_bundle_has_no_native_owner(): void
    {
        [$generation, $request, $models, $rows, $bundle] = $this->discoveryFixture();
        $generation->forceFill(['trigger_context' => ['mtf_bundle_manifest' => $bundle['manifest'], 'mtf_bundle_hash' => $bundle['bundle_hash']]])->save();
        $owner = app(SpecialistCouncilPreparationService::class);
        $this->assertFalse($owner->isResearchGeneration($generation));
        $this->assertFalse($owner->inspectDiscoveryOwner($generation, $bundle['manifest'])['allowed']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DISCOVERY_ONLY_BUNDLE_REPLAY_SCOPE_FORBIDDEN');
        (new \ReflectionMethod(LabAgentEvaluationService::class, 'replayMtfBundle'))
            ->invoke(app(LabAgentEvaluationService::class), LabAgent::where('model_version_id', $models[2]->id)->firstOrFail(), false, true);
    }

    public function test_prepared_native_council_dispatches_singletons_and_oversized_direct_batch_is_rejected_before_evidence(): void
    {
        [$generation, $request] = $this->fixture();
        app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
        $generation = $generation->fresh();
        $agents = $generation->agents()->with('modelVersion')->get();
        $dispatcher = app(\App\Console\Commands\DispatchLabGeneration::class);
        $jobs = (new \ReflectionMethod($dispatcher, 'screeningJobs'))->invoke($dispatcher,
            $generation, $agents, $agents->pluck('id')->all(), 4, 'XAUUSD', 'H1');
        $this->assertCount(4, $jobs);
        foreach ($jobs as $job) $this->assertCount(1, $job->labAgentIds);
        $generation->agents()->update(['lifecycle_status' => 'queued']);
        try {
            app(LabAgentEvaluationService::class)->screenBatch($agents->pluck('id')->all(), 'XAUUSD');
            $this->fail('Stale cohort job cannot bundle several native research arm leases.');
        } catch (\RuntimeException $error) {
            $this->assertSame('PROSPECTIVE_SCREEN_REQUIRES_SINGLE_CANDIDATE_JOB', $error->getMessage());
        }
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('specialist_council_evaluations', 0);
    }

    public function test_clean_plan_dataset_calendar_or_claim_mismatch_rolls_back_before_registration(): void
    {
        [$generation, $request, $models] = $this->discoveryFixture();
        $request['evaluation_plan']['windows'][0]['dataset_sha256'] = str_repeat('e', 64);
        try {
            app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
            $this->fail('Plan labels cannot substitute a different original dataset.');
        } catch (\LogicException $error) {
            $this->assertSame('CANONICAL_COUNCIL_DISCOVERY_PLAN_DATA_OR_BUDGET_MISMATCH', $error->getMessage());
        }
        $this->assertRolledBack($generation, $models);
    }

    public function test_new_nonarm_model_or_native_parameter_drift_cannot_reuse_original_generation_owner(): void
    {
        [$generation, $request, $models] = $this->fixture();
        $owner = app(SpecialistCouncilPreparationService::class);
        $owner->prepare($generation, $request);
        $models[1]->forceFill(['parameters' => ['ema_fast' => 8, 'ema_slow' => 10]])->save();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('CANONICAL_COUNCIL_PREPARATION_ORIGINAL_MODEL_DRIFT');
        $owner->isResearchGeneration($generation->fresh());
    }

    private function discoveryFixture(): array
    {
        [$generation, $request, $models] = $this->fixture();
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $rows = [];
        $start = CarbonImmutable::parse('2025-01-06T02:00:00Z');
        for ($i = 0; $i < 15512; $i++) $rows[] = ['time' => $start->addMinutes(5 * $i)->toIso8601String()];
        $hash = str_repeat('b', 64);
        $probe = app(ProspectiveRepairProbeWindowService::class)->seal($rows, $hash, $execution['execution_hash'], 'original-native-council-clean-discovery', 15000, 512);
        $end = CarbonImmutable::parse($probe['loaded_end'])->addMinutes(5)->toIso8601ZuluString();
        $window = ['window_key' => 'original-window', 'start_inclusive' => $probe['loaded_start'], 'end_exclusive' => $end,
            'dataset_sha256' => $hash, 'prospective_probe_window' => $probe,
            'evaluation_scope' => ['start_inclusive' => $probe['evaluated_start'], 'end_exclusive' => $end,
                'rows' => 15000, 'decision_rows' => 14999, 'warmup_rows' => 512,
                'policy_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($probe)]];
        $request['evaluation_plan']['windows'] = [$window];
        $calendar = array_intersect_key($probe, array_flip(['loaded_rows', 'warmup_rows', 'evaluated_rows', 'loaded_start',
            'loaded_end', 'evaluated_start', 'evaluated_end', 'evaluated_month_counts']));
        $manifest = ['protocol' => MultiTimeframeSnapshotService::PROTOCOL,
            'validation_bundle_protocol' => MultiTimeframeSnapshotService::DISCOVERY_BUNDLE_PROTOCOL,
            'bundle_hash' => $hash, 'data_role' => 'pre_2026_discovery_only', 'symbol' => 'XAUUSD',
            'closed_cutoff' => $end, 'entry_rows' => 15512, 'discovery_scope' => ['scope_hash' => str_repeat('c', 64), 'calendar' => $calendar],
            'bounded_cost_contract' => ['requested_m5_rows' => 15512, 'evaluated_rows' => 15000, 'warmup_rows' => 512],
            'independent_evidence' => false, 'full_validation_eligible' => false, 'paper_eligible' => false,
            'runtime_trade_authority' => false, 'parent_authority' => false, 'promotion_evidence' => false];
        $bundle = ['bundle_hash' => $hash, 'manifest' => $manifest, 'manifest_path' => '/fixture/native-council-manifest.json',
            'entry_dataset_path' => '/fixture/m5.csv', 'context_dataset_paths' => ['H4' => '/fixture/h4.csv', 'H1' => '/fixture/h1.csv', 'M15' => '/fixture/m15.csv']];
        // Actual MTF provider/SQL/immutable-byte validation is separately exercised
        // by ProspectiveCleanDiscoverySnapshotTest; this tests the intake seam.
        $mtf = \Mockery::mock(MultiTimeframeSnapshotService::class)->makePartial();
        $mtf->shouldReceive('discoveryBundleReadiness')->andReturnUsing(fn (array $candidate): array => [
            'allowed' => $candidate === $manifest, 'reason' => 'verified_original_fixture_bytes']);
        $mtf->shouldReceive('restoreAgentOwnedConfirmationValidationBundle')->with($manifest, true)->andReturn($bundle);
        $this->app->instance(MultiTimeframeSnapshotService::class, $mtf);
        $request['discovery_bundle_manifest'] = $manifest;
        return [$generation, $request, $models, $rows, $bundle];
    }

    public function test_cli_prepares_one_existing_draft_and_never_creates_generation(): void
    {
        Storage::fake('local');
        [$generation, $request] = $this->fixture();
        Storage::disk('local')->put('council-preparation/input.json', json_encode($request, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $this->artisan('trading:specialist-council', ['action' => 'prepare', '--generation-id' => $generation->id,
            '--preparation' => Storage::disk('local')->path('council-preparation/input.json')])->assertSuccessful();
        $this->assertDatabaseCount('lab_generations', 1);
        $this->assertDatabaseCount('specialist_council_versions', 1);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_creator_and_evaluator_separation_and_2026_paper_boundary_are_preserved(): void
    {
        [$generation, $request, $models] = $this->fixture();
        $request['evaluator_id'] = $request['creator_id'];
        try {
            app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
            $this->fail('Expected creator self-certification to be refused.');
        } catch (\LogicException $error) {
            $this->assertSame('CREATOR_OR_MEMBER_CANNOT_SELF_CERTIFY', $error->getMessage());
        }
        $request['evaluator_id'] = 'native-evaluator';
        $request['evaluation_plan']['windows'][0]['end_exclusive'] = '2026-01-02T00:00:00Z';
        try {
            app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
            $this->fail('Expected paper-only data boundary to be refused.');
        } catch (\LogicException $error) {
            $this->assertSame('2026_PAPER_EVENTS_ARE_NOT_RESEARCH_DATA', $error->getMessage());
        }
        $this->assertRolledBack($generation, $models);
    }

    private function assertRolledBack(LabGeneration $generation, $models): void
    {
        $this->assertDatabaseCount('specialist_council_versions', 0);
        $this->assertDatabaseCount('specialist_council_evaluation_plans', 0);
        $this->assertNull(data_get($generation->fresh()->trigger_context, 'specialist_council_preparation'));
        foreach ($models as $model) {
            $this->assertNull(data_get($model->fresh()->metadata, 'specialist_council'));
            $this->assertNull(data_get($model->fresh()->metadata, 'specialist_council_evaluation'));
        }
    }

    private function fixture(): array
    {
        $models = collect(['hour', 'day', 'carrier', 'ablation'])->map(fn (string $name): ModelVersion => $this->model($name));
        $lab = AiLaboratory::create(['name' => 'Prospective native council fixture', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['ema_rsi'], 'is_active' => false]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'historical_research',
            'status' => 'draft', 'population_size' => 4, 'trigger_context' => [], 'started_at' => now()]);
        foreach ($models as $model) LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi', 'origin' => 'test',
            'lifecycle_status' => 'draft', 'parameter_diff' => []]);
        $account = ['id' => 'canonical-execution', 'version' => '1', 'broker_position_mode' => 'hedging', 'opposite_position_policy' => 'hedge',
            'max_open_positions' => 8, 'max_reserved_capital_percent' => 100, 'max_gross_exposure_percent' => 100,
            'max_total_risk_percent' => 2, 'max_drawdown_percent' => 10, 'max_daily_loss_percent' => 3, 'max_expected_cost_percent' => 1];
        $manifest = ['council_id' => 'prospective-native-council', 'version' => '1',
            'members' => [$this->passport('hour', $models[0]), $this->passport('day', $models[1])], 'components' => [],
            'routing' => ['id' => 'scope-router', 'version' => '1'], 'allocation' => ['id' => 'shared-capital', 'version' => '1'],
            'risk' => ['id' => 'external-hard-risk', 'version' => '1'], 'execution' => $account,
            'evaluation_policy' => ['objective' => 'net_return_at_equal_risk', 'champion_model_version_id' => $models[0]->id,
                'solo_model_version_id' => $models[0]->id]];
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $rows = [];
        for ($i = 0; $i < 8; $i++) $rows[] = ['time' => CarbonImmutable::parse('2025-01-06T02:00:00Z')->addMinutes(5 * $i)->toIso8601String()];
        $probe = app(ProspectiveRepairProbeWindowService::class)->seal($rows, str_repeat('d', 64), $execution['execution_hash'], 'native-council-preparation', 6, 2);
        $plan = ['purpose' => 'research', 'execution_hash' => $execution['execution_hash'], 'execution_timeframe' => 'M5',
            'initial_capital' => 10000, 'cost_model' => $execution['parameters'],
            'risk_policy' => [...array_diff_key($account, ['id' => true, 'version' => true]), 'risk_per_trade_percent' => .5],
            'windows' => [['window_key' => 'original-window', 'start_inclusive' => '2025-01-06T02:00:00Z',
                'end_exclusive' => '2025-01-06T02:40:00Z', 'dataset_sha256' => str_repeat('d', 64), 'prospective_probe_window' => $probe,
                'evaluation_scope' => ['start_inclusive' => $probe['evaluated_start'], 'end_exclusive' => '2025-01-06T02:40:00Z',
                    'rows' => 6, 'decision_rows' => 5, 'warmup_rows' => 2, 'policy_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($probe)]]],
            'arms' => [['arm_key' => 'candidate', 'kind' => 'candidate', 'window_key' => 'original-window', 'model_version_id' => $models[2]->id],
                ['arm_key' => 'solo', 'kind' => 'solo', 'window_key' => 'original-window', 'model_version_id' => $models[0]->id],
                ['arm_key' => 'without-hour', 'kind' => 'ablation', 'removed_id' => 'hour', 'window_key' => 'original-window', 'model_version_id' => $models[3]->id]]];
        return [$generation, ['protocol' => SpecialistCouncilPreparationService::PROTOCOL, 'creator_id' => 'native-creator',
            'research_question' => 'Does this two-horizon council change native outcomes relative to the exact solo and one member ablation at equal capital and risk?',
            'evaluator_id' => 'native-evaluator', 'carrier_model_version_id' => $models[2]->id, 'manifest' => $manifest,
            'evaluation_plan' => $plan], $models];
    }

    private function model(string $name): ModelVersion
    {
        return ModelVersion::create(['name' => $name, 'strategy' => 'ema_rsi_v1', 'version' => 'v1-'.$name,
            'generation' => 1, 'status' => 'testing', 'parameters' => ['ema_fast' => 4, 'ema_slow' => 10], 'metadata' => ['base_strategy' => 'ema_rsi']]);
    }

    private function passport(string $role, ModelVersion $model): array
    {
        return ['specialist_id' => $role, 'role' => $role, 'version' => '1', 'as_of' => '2025-01-06T02:00:00Z',
            'inputs' => ['as_of_closed_candles'], 'scope' => ['symbols' => ['XAUUSD'], 'contexts' => ['trend']],
            'known_limits' => ['research_unqualified'], 'resources' => ['max_compute_ms' => 100, 'max_memory_mb' => 32, 'max_lookback_bars' => 512],
            'horizon' => ['kind' => $role, 'decision_interval_seconds' => 300, 'reevaluation_interval_seconds' => 300,
                'max_holding_seconds' => 3600, 'execution_precision' => 'candle'], 'data_requirements' => ['sessions', 'costs'],
            'model_version_id' => $model->id, 'strategy_version' => 'strategy-v1', 'tactic_version' => 'tactic-v1',
            'management_version' => 'management-v1', 'capital_weight' => .5, 'risk_per_trade_percent' => .5,
            'sensor_timeframes' => ['H4', 'H1', 'M15', 'M5']];
    }
}
