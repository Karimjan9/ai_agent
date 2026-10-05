<?php

namespace Tests\Feature;

use App\Models\LabEvaluationRun;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentReceipt;
use App\Models\ResearchExperimentWorkItem;
use App\Models\SpecialistCouncilVersion;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchLoopArbiterService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilContractService;
use App\Services\SpecialistCouncilLifecycleService;
use App\Services\SpecialistCouncilResearchFeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Synthetic owner facts exercise publication/knowledge guards, not market improvement. */
class SpecialistCouncilResearchFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_empty_original_evaluation_atomically_closes_as_technical_information(): void
    {
        [$version] = $this->fixture(false);
        $service = app(SpecialistCouncilLifecycleService::class);
        $assessment = $service->evaluateOriginalRuns($version->fresh(), 'independent-examiner', []);
        $this->assertSame('technical_unassessable', $assessment['research_observation_status']);
        $this->assertSame(0, $assessment['positive_independent_windows']);
        $this->assertFalse($assessment['qualified']);
        $this->assertDatabaseCount('specialist_council_evaluations', 1);
        $this->assertDatabaseCount('research_experiment_receipts', 1);
        $this->assertDatabaseCount('research_knowledge_entries', 1);
        $receipt = ResearchExperimentReceipt::sole();
        $this->assertSame('TECHNICAL_QUARANTINE', $receipt->classification);
        $work = ResearchExperimentWorkItem::sole();
        $this->assertSame('blocked', $work->status);
        $this->assertSame('specialist_council_technical_repair', $work->work_type);
        $this->assertFalse($work->payload['executable']);
        $this->assertSame([], app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class));
        $service->evaluateOriginalRuns($version->fresh(), 'independent-examiner', []);
        $this->assertDatabaseCount('research_experiment_receipts', 1);
        $this->assertDatabaseCount('research_experiment_work_items', 1);
        $this->assertDatabaseCount('research_knowledge_entries', 1);
    }

    public function test_scoped_negative_comparison_becomes_terminal_knowledge_not_global_ban(): void
    {
        [$version] = $this->fixture(true, 'research_compared', -10);
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $one = $service->recordAssessment($version);
        $two = $service->recordAssessment($version);
        $this->assertSame($one['receipt_id'], $two['receipt_id']);
        $this->assertSame('local_negative', $one['economic_direction']);
        $receipt = ResearchExperimentReceipt::sole();
        $this->assertSame('INCONCLUSIVE', $receipt->classification);
        $this->assertFalse($receipt->terminal_reason['global_harmful_ban']);
        $this->assertSame('SCOPED_COUNCIL_COMPARISON_NOT_BETTER_THAN_SOLO', $receipt->terminal_reason['code']);
        $this->assertDatabaseCount('research_experiment_work_items', 0);
        $knowledge = DB::table('research_knowledge_entries')->sole();
        $this->assertSame('research_only', $knowledge->authority);
        $this->assertSame('EPISODIC', $knowledge->knowledge_type);
        $observations = $service->priorObservations($version->council_id);
        $this->assertCount(1, $observations);
        $this->assertSame($one['receipt_id'], $observations[0]['receipt_id']);
        $this->assertSame($version->manifest_hash, $observations[0]['scope']['manifest_hash']);
        $this->assertSame([], $service->priorObservations('unrelated-council'));
        $this->assertFalse($observations[0]['promotion_evidence']);
        $service->assertPriorObservations($version->council_id, $observations);
        $this->assertSame($receipt->evidence_hash, $observations[0]['evidence_hash']);
    }

    public function test_local_null_is_closed_without_credit_or_retry_compute(): void
    {
        [$version] = $this->fixture(true, 'research_compared', 0);
        $result = app(SpecialistCouncilResearchFeedbackService::class)->recordAssessment($version);
        $this->assertSame('local_null', $result['economic_direction']);
        $this->assertSame('INCONCLUSIVE', $result['classification']);
        $this->assertSame('SCOPED_COUNCIL_COMPARISON_NO_INCREMENTAL_BENEFIT', ResearchExperimentReceipt::sole()->terminal_reason['code']);
        $this->assertDatabaseCount('research_experiment_work_items', 0);
        $this->assertFalse(data_get(ResearchExperimentReceipt::sole()->payload, 'evidence.confirmed_skill_credit'));
    }

    public function test_promising_local_result_waits_for_authorized_unused_post_paper_data(): void
    {
        [$version] = $this->fixture(true, 'research_compared', 10);
        $result = app(SpecialistCouncilResearchFeedbackService::class)->recordAssessment($version);
        $this->assertSame('BEHAVIORAL_ACTIVATION_HYPOTHESIS', $result['classification']);
        $this->assertSame('locally_promising', $result['economic_direction']);
        $work = ResearchExperimentWorkItem::sole();
        $this->assertSame('blocked', $work->status);
        $this->assertFalse(data_get($work->payload, 'executable'));
        $this->assertFalse(data_get($work->payload, 'data_policy.paper_2026_is_research'));
        $this->assertSame(2027, data_get($work->payload, 'data_policy.minimum_research_year'));
        $this->assertTrue(data_get($work->payload, 'same_evidence_replay_forbidden'));
        $this->assertSame([], app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class));
        $this->assertFalse($version->assessment['qualified']);
        $this->assertSame('evaluated', $version->fresh()->state);
    }

    public function test_data_dependency_is_not_a_negative_strategy_or_underpowered_market_claim(): void
    {
        [$version] = $this->fixture(true, 'data_missing', -10);
        $result = app(SpecialistCouncilResearchFeedbackService::class)->recordAssessment($version);
        $this->assertSame('INCONCLUSIVE', $result['classification']);
        $this->assertSame('not_powered_or_assessable', $result['economic_direction']);
        $this->assertSame('specialist_council_data_repair', ResearchExperimentWorkItem::sole()->work_type);
        $this->assertFalse(data_get(ResearchExperimentReceipt::sole()->payload, 'contract.claim.global_harmful_ban'));
    }

    public function test_underpowered_result_gets_one_blocked_prospective_power_scope(): void
    {
        [$version] = $this->fixture(true, 'underpowered', 10);
        $result = app(SpecialistCouncilResearchFeedbackService::class)->recordAssessment($version);
        $this->assertSame('UNDERPOWERED', $result['classification']);
        $work = ResearchExperimentWorkItem::sole();
        $this->assertSame('specialist_council_power_extension', $work->work_type);
        $this->assertSame(1, data_get($work->payload, 'retry_condition.max_experiments'));
        $this->assertSame('blocked', $work->status);
        $this->assertFalse(data_get($work->payload, 'executable'));
    }

    public function test_changed_original_source_hash_cannot_publish_feedback(): void
    {
        [$version, $run] = $this->fixture(true);
        $run->forceFill(['response_hash' => str_repeat('a', 64)])->save();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_RESEARCH_FEEDBACK_ORIGINAL_RUN_HASH_CHANGED');
        app(SpecialistCouncilResearchFeedbackService::class)->recordAssessment($version);
    }

    public function test_forged_consumption_snapshot_is_refused_even_with_valid_receipt_id(): void
    {
        [$version] = $this->fixture(true);
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $service->recordAssessment($version);
        $snapshot = $service->priorObservations($version->council_id);
        $snapshot[0]['economic_direction'] = 'locally_promising';
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_PRIOR_RESEARCH_SNAPSHOT_ORIGINAL_RECEIPT_INVALID');
        $service->assertPriorObservations($version->council_id, $snapshot);
    }

    public function test_tampered_prior_evidence_is_not_returned_or_consumed(): void
    {
        [$version, $run] = $this->fixture(true);
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $service->recordAssessment($version);
        $snapshot = $service->priorObservations($version->council_id);
        $run->forceFill(['code_hash' => str_repeat('0', 64)])->save();
        $this->assertSame([], $service->priorObservations($version->council_id));
        $this->expectException(\LogicException::class);
        $service->assertPriorObservations($version->council_id, $snapshot);
    }

    public function test_fresh_model_version_provider_and_window_labels_do_not_renew_same_question(): void
    {
        [$version] = $this->fixture(false);
        $row = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $version->id)->sole();
        $plan = json_decode($row->plan, true);
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $original = $service->questionFingerprint($version->manifest, $plan);
        $renamed = $version->manifest;
        $renamed['version'] = 'different-version-label';
        $renamed['members'][0]['model_version_id'] = 9999;
        $renamed['members'][0]['specialist_id'] = 'different-owner-label';
        $renamed['execution']['id'] = 'other-account-label';
        $plan['windows']['original-2025']['dataset_sha256'] = str_repeat('a', 64);
        $plan['windows']['original-2025']['window_key'] = 'renamed-provider-window';
        $this->assertSame($original, $service->questionFingerprint($renamed, $plan));
        $renamed['members'][0]['parameters']['ema_fast'] = 5;
        $this->assertNotSame($original, $service->questionFingerprint($renamed, $plan));
    }

    public function test_resealed_mutable_projection_cannot_override_original_assessment(): void
    {
        [$version] = $this->fixture(true);
        $forged = [...$version->assessment, 'qualified' => true];
        $version->forceFill(['assessment' => $forged, 'assessment_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($forged)])->save();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_RESEARCH_FEEDBACK_ORIGINAL_ASSESSMENT_INVALID');
        app(SpecialistCouncilResearchFeedbackService::class)->recordAssessment($version);
    }

    public function test_failed_knowledge_publication_rolls_back_original_assessment_transaction(): void
    {
        [$version] = $this->fixture(false);
        $this->mock(ResearchExperimentConversionKernelService::class)->shouldReceive('record')->once()
            ->andReturn(['status' => 'blocked', 'reason' => 'INJECTED_PUBLICATION_FAILURE']);
        try { app(SpecialistCouncilLifecycleService::class)->evaluateOriginalRuns($version->fresh(), 'independent-examiner', []); }
        catch (\LogicException $error) { $this->assertStringContainsString('INJECTED_PUBLICATION_FAILURE', $error->getMessage()); }
        $this->assertSame('evaluating', $version->fresh()->state);
        $this->assertNull($version->fresh()->assessment);
        $this->assertDatabaseCount('specialist_council_evaluations', 0);
        $this->assertDatabaseCount('research_experiment_receipts', 0);
    }

    public function test_complete_original_research_arms_compare_without_fictitious_champion_authority(): void
    {
        // Producer/parity execution is covered by native integration tests. This
        // fixture isolates the assessment/conversion owner with sealed artifacts.
        $this->partialMock(LabImmutableEvidenceService::class, function ($mock): void {
            $mock->shouldReceive('learningEligibility')->andReturn(['complete' => true]);
            $mock->shouldReceive('verifiedModelRuntimeIdentity')->andReturn(['verified' => true]);
        });
        $mock = \Mockery::mock(SpecialistCouncilLifecycleService::class, [app(SpecialistCouncilContractService::class),
            app(ResearchPaperEpochContractService::class), app(LabImmutableEvidenceService::class)])->makePartial();
        $mock->shouldReceive('bindEvaluationRequest')->andReturnUsing(fn ($version, $key, $request) => $request);
        $mock->shouldReceive('assertOriginalArmScope')->andReturnNull();
        $mock->shouldReceive('attestReplayResult')->andReturn(['producer_fixture_only' => true]);
        $mock->shouldReceive('runtimeContract')->andReturnUsing(fn ($version) => ['manifest_hash' => $version->manifest_hash, 'contract_hash' => str_repeat('a', 64)]);
        $mock->shouldReceive('runtimeContractForAblation')->andReturnUsing(fn ($version) => ['manifest_hash' => $version->manifest_hash, 'contract_hash' => str_repeat('a', 64)]);
        $this->app->instance(SpecialistCouncilLifecycleService::class, $mock);
        [$version] = $this->fixture(false, 'research_compared', -10, true);
        $planRow = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $version->id)->sole();
        $plan = json_decode($planRow->plan, true);
        $runIds = [];
        foreach ($plan['arms'] as $key => $arm) {
            $binding = ['version_id' => $version->id, 'manifest_hash' => $version->manifest_hash,
                'plan_hash' => $planRow->plan_hash, 'arm_key' => $key];
            $runtime = ['manifest_hash' => $version->manifest_hash, 'contract_hash' => str_repeat('a', 64)];
            $request = ['specialist_council_evaluation' => $binding, 'specialist_council_contract' => $runtime,
                'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'replay_dataset_hash' => str_repeat('d', 64),
                'execution_hash' => $plan['execution_hash'], 'initial_balance' => $plan['initial_capital'],
                'cost_model' => $plan['cost_model'], 'risk_policy' => $plan['risk_policy'], 'risk_per_trade' => .5,
                'specialist_council_evaluation_policy' => ['synthetic_comparison_fixture' => true]];
            $metrics = ['net_profit' => $arm['kind'] === 'candidate' ? 10 : 0, 'total_costs' => 1,
                'total_trades' => 8, 'matured_trades' => 8, 'censored_trades' => 0, 'max_drawdown_percent' => 1,
                'max_daily_loss_percent' => 1, 'max_gross_exposure_percent' => 20, 'max_total_risk_percent' => 1];
            $positions = [...array_fill(0, 8, ['specialist_id' => 'hour-owner', 'outcome_matured' => true]),
                ...array_fill(0, 8, ['specialist_id' => 'day-owner', 'outcome_matured' => true])];
            $response = ['specialist_council_receipt' => ['protocol' => 'specialist_council_receipt_v1',
                'contract_hash' => $runtime['contract_hash'], 'metrics' => $metrics, 'position_ledger' => $positions,
                'members' => [['specialist_id' => 'hour-owner', 'stages' => ['decision:observed' => 10]],
                    ['specialist_id' => 'day-owner', 'stages' => ['decision:observed' => 10]]]]];
            $run = LabEvaluationRun::create(['run_id' => 'original-'.$key, 'model_version_id' => $arm['model_version_id'],
                'phase' => 'screening', 'mode' => 'incremental', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now(),
                'request_hash' => str_repeat('b', 64), 'data_hash' => str_repeat('d', 64), 'parameter_hash' => str_repeat('f', 64), 'code_hash' => str_repeat('e', 64)]);
            $evidence = app(LabImmutableEvidenceService::class);
            $evidence->recordArtifact($run, 'evaluation_request', $request, ['request_hash' => $run->request_hash]);
            $responseArtifact = $evidence->recordArtifact($run, 'evaluation_response', $response);
            $run->forceFill(['response_hash' => $responseArtifact->sha256])->save();
            $runIds[] = $run->run_id;
        }
        $assessment = app(SpecialistCouncilLifecycleService::class)->evaluateOriginalRuns($version->fresh(), 'independent-examiner', $runIds);
        $this->assertSame('research_compared', $assessment['research_observation_status']);
        $this->assertCount(1, $assessment['comparisons']);
        $this->assertNull($assessment['comparisons'][0]['champion']);
        $this->assertCount(1, $assessment['comparisons'][0]['ablations']);
        $this->assertTrue($assessment['comparisons'][0]['ablations'][0]['incremental_value_observed']);
        $this->assertSame(1, $assessment['observed_positive_windows']);
        $this->assertSame(0, $assessment['positive_independent_windows']);
        $this->assertFalse($assessment['qualified']);
        $this->assertContains('RESEARCH_COMPARISON_HAS_NO_INDEPENDENT_PROMOTION_AUTHORITY', $assessment['reason_codes']);
        $this->assertSame('BEHAVIORAL_ACTIVATION_HYPOTHESIS', ResearchExperimentReceipt::sole()->classification);
        $this->assertSame('blocked', ResearchExperimentWorkItem::sole()->status);
        $feedback = app(SpecialistCouncilResearchFeedbackService::class);
        $fingerprint = data_get(ResearchExperimentReceipt::sole()->payload, 'contract.identity.research_question_fingerprint');
        $completed = $feedback->completedQuestionForSource($fingerprint, str_repeat('e', 64));
        $this->assertSame(3, $completed['completed_original_arm_count']);
        $this->assertSame(ResearchExperimentReceipt::sole()->id, $completed['receipt_id']);
        $this->assertNull($feedback->completedQuestionForSource($fingerprint, str_repeat('0', 64)));
    }

    /** Fixture examiner facts are explicitly synthetic; native replay has separate acceptance. */
    private function fixture(bool $withAssessment, string $status = 'research_compared', float $delta = -10, bool $completeResearchPlan = false): array
    {
        $model = ModelVersion::create(['name' => 'Feedback native model', 'strategy' => 'ema_rsi_v1',
            'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => ['ema_fast' => 4, 'ema_slow' => 10],
            'metadata' => ['base_strategy' => 'ema_rsi']]);
        $passport = ['specialist_id' => 'hour-owner', 'role' => 'hour', 'version' => 'v1', 'as_of' => '2025-12-01T00:00:00Z',
            'inputs' => ['as_of_closed_candles'], 'scope' => ['symbols' => ['XAUUSD'], 'contexts' => ['trend']],
            'known_limits' => ['synthetic_test_only'], 'resources' => ['max_compute_ms' => 100, 'max_memory_mb' => 32, 'max_lookback_bars' => 512],
            'horizon' => ['kind' => 'hour', 'decision_interval_seconds' => 300, 'reevaluation_interval_seconds' => 300,
                'max_holding_seconds' => 3600, 'execution_precision' => 'candle'], 'data_requirements' => ['sessions', 'costs'],
            'model_version_id' => $model->id, 'strategy_version' => 'strategy-v1', 'tactic_version' => 'tactic-v1',
            'management_version' => 'management-v1', 'capital_weight' => .2, 'risk_per_trade_percent' => .5,
            'sensor_timeframes' => ['H4', 'H1', 'M15', 'M5']];
        $service = app(SpecialistCouncilLifecycleService::class);
        $members = [$passport];
        if ($completeResearchPlan) $members[] = [...$passport, 'specialist_id' => 'day-owner', 'role' => 'day',
            'horizon' => [...$passport['horizon'], 'kind' => 'day', 'max_holding_seconds' => 14400]];
        $version = $service->registerDraft(['council_id' => 'feedback-council', 'version' => 'v1', 'members' => $members,
            'components' => [], 'routing' => ['id' => 'router', 'version' => '1'], 'allocation' => ['id' => 'allocator', 'version' => '1'],
            'risk' => ['id' => 'risk', 'version' => '1'], 'execution' => ['id' => 'native', 'version' => '1',
                'broker_position_mode' => 'hedging', 'opposite_position_policy' => 'hedge', 'max_open_positions' => 8,
                'max_reserved_capital_percent' => 100, 'max_gross_exposure_percent' => 100, 'max_total_risk_percent' => 2,
                'max_drawdown_percent' => 10, 'max_daily_loss_percent' => 3, 'max_expected_cost_percent' => 1],
            'evaluation_policy' => ['objective' => 'net_return_at_equal_risk', 'champion_model_version_id' => $model->id,
                'solo_model_version_id' => $model->id]], 'evolution-owner');
        $armDefinitions = [['arm_key' => 'candidate-2025', 'kind' => 'candidate', 'window_key' => 'original-2025', 'model_version_id' => $model->id]];
        if ($completeResearchPlan) {
            $armDefinitions[] = ['arm_key' => 'solo-2025', 'kind' => 'solo', 'window_key' => 'original-2025', 'model_version_id' => $model->id];
            $armDefinitions[] = ['arm_key' => 'ablation-2025', 'kind' => 'ablation', 'window_key' => 'original-2025',
                'model_version_id' => $model->id, 'removed_id' => 'hour-owner'];
        }
        $plan = $service->sealEvaluationPlan($version, 'independent-examiner', ['purpose' => 'research',
            'execution_hash' => str_repeat('e', 64), 'execution_timeframe' => 'M5', 'initial_capital' => 10000,
            'cost_model' => ['commission_percent' => .1], 'risk_policy' => ['max_risk' => 2, 'risk_per_trade_percent' => .5],
            'windows' => [['window_key' => 'original-2025', 'start_inclusive' => '2025-01-01T00:00:00Z',
                'end_exclusive' => '2025-02-01T00:00:00Z', 'dataset_sha256' => str_repeat('d', 64)]],
            'arms' => $armDefinitions]);
        if (! $withAssessment) return [$version, null];
        $run = LabEvaluationRun::create(['run_id' => 'synthetic-original-run', 'model_version_id' => $model->id,
            'phase' => 'screening', 'mode' => 'incremental', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now(),
            'request_hash' => str_repeat('b', 64), 'response_hash' => str_repeat('c', 64), 'data_hash' => str_repeat('d', 64),
            'parameter_hash' => str_repeat('f', 64), 'code_hash' => str_repeat('e', 64)]);
        $source = ['run_id' => $run->run_id];
        foreach (['request_hash', 'response_hash', 'data_hash', 'parameter_hash', 'code_hash'] as $field) $source[$field] = $run->{$field};
        $assessment = ['protocol' => SpecialistCouncilLifecycleService::ASSESSMENT_PROTOCOL, 'version_id' => $version->id,
            'manifest_hash' => $version->manifest_hash, 'plan_hash' => $plan['plan_hash'],
            'original_run_ids' => [$run->run_id], 'original_sources' => [$source], 'qualified' => false,
            'research_observation_status' => $status, 'positive_independent_windows' => 0,
            'reason_codes' => ['RESEARCH_COMPARISON_HAS_NO_INDEPENDENT_PROMOTION_AUTHORITY'],
            'comparisons' => [['window_key' => 'original-2025', 'candidate' => ['net_profit' => $delta],
                'solo' => ['net_profit' => 0], 'champion' => null, 'net_profit_delta_vs_solo' => $delta,
                'incremental_value' => $delta > 0, 'powered' => true,
                'ablations' => [['removed_id' => 'hour-owner', 'incremental_value_observed' => $delta > 0]]]]];
        $hash = app(ResearchPaperEpochContractService::class)->parameterHash($assessment);
        DB::table('specialist_council_evaluations')->insert(['specialist_council_version_id' => $version->id,
            'evaluator_id' => 'independent-examiner', 'original_run_ids' => json_encode([$run->run_id]),
            'assessment' => json_encode($assessment, JSON_PRESERVE_ZERO_FRACTION), 'assessment_hash' => $hash,
            'created_at' => now(), 'updated_at' => now()]);
        $version->forceFill(['state' => 'evaluated', 'assessment' => $assessment, 'assessment_hash' => $hash])->save();
        return [$version->fresh(), $run];
    }
}
