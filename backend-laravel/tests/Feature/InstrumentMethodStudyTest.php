<?php

namespace Tests\Feature;

use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\ModelVersion;
use App\Services\AgentMethodLearningService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\InstrumentMethodStudyService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchPaperEpochContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/** Synthetic software products exercise admission/account arithmetic, never market proof. */
class InstrumentMethodStudyTest extends TestCase
{
    use RefreshDatabase;
    private array $design;
    private array $policies;
    private array $identities = [];
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('instrument_method_study_test');
        config()->set('services.lab_evidence.disk', 'instrument_method_study_test');
        $arms = []; $this->policies = [];
        foreach (['L1A1', 'L2A1', 'L1A2', 'L2A2'] as $key) {
            $model = ModelVersion::create(['name' => 'synthetic-'.$key, 'strategy' => 'confirmation_entry_mtf',
                'version' => 1, 'parameters' => ['lookback' => 20], 'metadata' => ['base_strategy' => 'confirmation_entry_mtf']]);
            $hash = hash('sha256', 'synthetic-policy-'.$key);
            $this->policies[$hash] = ['policy_hash' => $hash, 'agent_key' => 'model:'.$model->id,
                'library_hash' => str_repeat(str_starts_with($key, 'L1') ? 'a' : 'b', 64),
                'rules' => [['context_predicate' => ['regime' => 'trend_up'],
                    'preferred_recipes' => [str_repeat(str_starts_with($key, 'L1') ? 'c' : 'd', 64)]]],
                'default_action' => str_ends_with($key, 'A1') ? 'BASELINE' : 'WAIT'];
            $arms[$key] = ['model_id' => $model->id, 'policy_hash' => $hash];
        }
        $this->design = ['study_key' => 'synthetic-software-study', 'arms' => $arms,
            'data_hash' => str_repeat('e', 64), 'execution_hash' => str_repeat('f', 64),
            'scope' => ['symbol' => 'XAUUSD', 'timeframe' => 'M1', 'evaluated_start' => '2025-12-20T12:00:00Z',
                'evaluated_end' => '2025-12-20T12:10:00Z', 'context' => ['regime' => 'trend_up']],
            'evaluated_rows' => 10, 'initial_balance' => 1000.0, 'risk_per_trade' => 1.0,
            'execution_costs' => ['spread_points' => 35.0, 'commission_percent' => 0.01],
            'hard_limits' => ['max_total_risk_percent' => 5.0],
            'resource_caps' => ['max_rows' => 10, 'max_decision_events' => 32, 'max_runtime_seconds' => 120], 'minimum_trades' => 2];
        $library = Mockery::mock(InstrumentMethodLibraryService::class);
        $library->shouldReceive('inspectRecipe')->andReturnUsing(fn ($hash) => ['status' => 'registered_research_only',
            'recipe_hash' => $hash, 'contract' => ['recipe_key' => 'stable-recipe-key', 'revision' => $hash === str_repeat('c', 64) ? 1 : 2]]);
        $this->app->instance(InstrumentMethodLibraryService::class, $library);
        $agents = Mockery::mock(AgentMethodLearningService::class);
        $agents->shouldReceive('policy')->andReturnUsing(fn ($hash) => $this->policies[$hash] ?? ['status' => 'blocked']);
        $agents->shouldReceive('settleOriginalRun')->andReturnUsing(function ($run): array {
            $key = array_key_first(array_filter($this->design['arms'], fn ($arm) => $arm['model_id'] === $run->model_version_id));
            $policy = $this->design['arms'][$key]['policy_hash'];
            return ['status' => 'verified_diagnostic', 'policy_hashes' => [$policy],
                'experiences' => [['facts' => ['data_hash' => $this->design['data_hash'], 'execution_hash' => $this->design['execution_hash'],
                    'library_hash' => $this->policies[$policy]['library_hash'], 'scope' => $this->design['scope']]]],
                'observations' => ['event_count' => 10, 'used_count' => 5, 'in_scope_count' => 10,
                    'behavior_changed_count' => 3, 'veto_count' => 1, 'reason_counts' => ['recipe_evaluated' => 5]]];
        });
        $this->app->instance(AgentMethodLearningService::class, $agents);
        $owner = Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $owner->shouldReceive('learningEligibility')->andReturn(['complete' => true]);
        $owner->shouldReceive('verifiedModelRuntimeIdentity')->andReturnUsing(fn ($run) => $this->identities[$run->run_id] ?? null);
        $this->app->instance(LabImmutableEvidenceService::class, $owner);
    }

    public function test_preregistration_freezes_separate_library_and_agent_axes_and_prospective_binding(): void
    {
        $owner = app(InstrumentMethodStudyService::class); $result = $owner->preregister($this->design);
        $this->assertSame('preregistered_diagnostic', $result['status'], $result['reason'] ?? '');
        $this->assertSame($result['study_hash'], $owner->preregister($this->design)['study_hash']);
        $binding = $owner->bindingForModel($this->design['arms']['L2A1']['model_id']);
        $this->assertEquals(['study_hash' => $result['study_hash'], 'arm' => 'L2A1', 'resource_caps' => $this->design['resource_caps']], $binding);
        $this->assertNull($owner->bindingForModel(999999));
        $this->assertFalse($result['design']['independent_windows']);
        $changed = $this->design; $changed['minimum_trades'] = 99;
        $this->assertSame('STUDY_PREREGISTRATION_IS_IMMUTABLE', $owner->preregister($changed)['reason']);
        $bad = $this->design; $bad['master_instruments'] = true;
        $this->assertSame('STUDY_UNSUPPORTED_FIELDS', $owner->preregister($bad)['reason']);
        $this->policies[$this->design['arms']['L2A1']['policy_hash']]['default_action'] = 'WAIT';
        $this->assertSame('STUDY_AGENT_AXIS_NOT_SEPARATE', $owner->preregister([...$this->design, 'study_key' => 'wrong-axis'])['reason']);
    }

    public function test_original_mature_ledger_computes_conditional_effects_and_interaction_once(): void
    {
        $owner = app(InstrumentMethodStudyService::class); $study = $owner->preregister($this->design);
        $runs = $this->runs($study['study_hash'], ['L1A1' => 10, 'L2A1' => 30, 'L1A2' => 25, 'L2A2' => 60]);
        $assessment = $owner->settle($study['study_hash'], $runs);
        $this->assertSame('assessed_local_diagnostic', $assessment['status'], $assessment['reason'] ?? '');
        $this->assertEquals(20.0, $assessment['effects']['library_given_A1']['value']);
        $this->assertEquals(35.0, $assessment['effects']['library_given_A2']['value']);
        $this->assertEquals(15.0, $assessment['effects']['agent_given_L1']['value']);
        $this->assertEquals(30.0, $assessment['effects']['agent_given_L2']['value']);
        $this->assertEquals(15.0, $assessment['effects']['interaction']['value']);
        $this->assertFalse($assessment['confirmation_proven']); $this->assertFalse($assessment['mastery_proven']);
        $this->assertSame($assessment, $owner->settle($study['study_hash'], array_reverse($runs)));
        $replacement = $runs; $replacement[0] = (string) Str::uuid();
        $this->assertSame('STUDY_ASSESSMENT_ORIGINALS_ARE_IMMUTABLE', $owner->settle($study['study_hash'], $replacement)['reason']);
        $this->assertDatabaseCount('agent_method_experiences', 0);
    }

    public function test_missing_originals_wait_and_censored_trades_are_underpowered_not_losses(): void
    {
        $owner = app(InstrumentMethodStudyService::class); $study = $owner->preregister($this->design);
        $this->assertSame('awaiting_originals', $owner->settle($study['study_hash'], [])['status']);
        $runs = $this->runs($study['study_hash'], ['L1A1' => 0, 'L2A1' => -10, 'L1A2' => 0, 'L2A2' => -10], true);
        $this->assertSame('awaiting_originals', $owner->settle($study['study_hash'], array_slice($runs, 0, 3))['status']);
        $assessment = $owner->settle($study['study_hash'], $runs);
        $this->assertSame('underpowered_local_diagnostic', $assessment['status'], $assessment['reason'] ?? '');
        $this->assertSame('underpowered', $assessment['effects']['library_given_A1']['classification']);
        $this->assertSame(1, $assessment['arms']['L1A1']['censored_trades']);
        $this->assertEquals(5.0, $assessment['arms']['L1A1']['matured_net_profit']);
    }

    public function test_actual_cost_or_cap_drift_is_technical_and_cannot_publish_assessment(): void
    {
        $owner = app(InstrumentMethodStudyService::class); $study = $owner->preregister($this->design);
        $runs = $this->runs($study['study_hash'], ['L1A1' => 10, 'L2A1' => 30, 'L1A2' => 25, 'L2A2' => 60]);
        $run = LabEvaluationRun::where('run_id', $runs[0])->first();
        $request = $this->products[$run->run_id]['request']; $request['execution_contract']['parameters']['spread_points'] = 999.0;
        $this->replaceRequest($run, $request);
        $result = $owner->settle($study['study_hash'], $runs);
        $this->assertSame('STUDY_EXECUTION_COSTS_DIFFER', $result['reason']);
        $this->assertNull(DB::table('instrument_method_studies')->value('assessment'));
        $request['execution_contract']['parameters'] = $this->design['execution_costs'];
        $request['policy_context']['instrument_method_study']['resource_caps']['max_runtime_seconds'] = 999;
        $this->replaceRequest($run, $request);
        $this->assertSame('STUDY_REQUEST_RESOURCE_CAPS_DIFFER', $owner->settle($study['study_hash'], $runs)['reason']);
        $this->assertFalse($result['paper_authority']);
    }

    public function test_preexisting_model_runs_baseline_drift_and_repeat_attempts_are_rejected(): void
    {
        $owner = app(InstrumentMethodStudyService::class); $model = ModelVersion::find($this->design['arms']['L2A2']['model_id']);
        $model->update(['parameters' => ['lookback' => 21]]);
        $this->assertSame('STUDY_BASELINE_PARAMETERS_OR_METADATA_DIFFER', $owner->preregister($this->design)['reason']);
        $model->update(['parameters' => ['lookback' => 20]]);
        $study = $owner->preregister($this->design);
        $runs = $this->runs($study['study_hash'], ['L1A1' => 10, 'L2A1' => 30, 'L1A2' => 25, 'L2A2' => 60]);
        $first = LabEvaluationRun::where('run_id', $runs[0])->first();
        $duplicate = $first->replicate(); $duplicate->run_id = (string) Str::uuid(); $duplicate->save();
        $this->assertSame('STUDY_ONE_ORIGINAL_ATTEMPT_PER_ARM_REQUIRED', $owner->settle($study['study_hash'], $runs)['reason']);
        $this->assertSame('STUDY_MUST_PRECEDE_ANY_MODEL_RUN', $owner->preregister([...$this->design, 'study_key' => 'observed-source'])['reason']);
        $this->assertNull(DB::table('instrument_method_studies')->value('assessment'));
    }

    public function test_powered_negative_and_null_effects_remain_local_diagnostics(): void
    {
        $owner = app(InstrumentMethodStudyService::class); $study = $owner->preregister($this->design);
        $runs = $this->runs($study['study_hash'], ['L1A1' => 10, 'L2A1' => 10, 'L1A2' => 0, 'L2A2' => 0]);
        $assessment = $owner->settle($study['study_hash'], $runs);
        $this->assertSame('assessed_local_diagnostic', $assessment['status'], $assessment['reason'] ?? '');
        $this->assertSame('null', $assessment['effects']['library_given_A1']['classification']);
        $this->assertSame('negative_local_diagnostic', $assessment['effects']['agent_given_L1']['classification']);
        $this->assertFalse($assessment['economic_authority']);
        $tampered = $assessment; $tampered['effects']['library_given_A1']['value'] = 999999;
        DB::table('instrument_method_studies')->update(['assessment' => json_encode($tampered)]);
        $this->assertSame('STUDY_ASSESSMENT_CONTENT_MISMATCH', $owner->settle($study['study_hash'], $runs)['reason']);
    }

    public function test_native_position_ledger_reconciles_account_and_excludes_censored_profit(): void
    {
        $owner = app(InstrumentMethodStudyService::class); $study = $owner->preregister($this->design);
        $runs = $this->runs($study['study_hash'], ['L1A1' => 10, 'L2A1' => 30, 'L1A2' => 25, 'L2A2' => 60]);
        foreach ($runs as $id) {
            $run = LabEvaluationRun::where('run_id', $id)->first(); $response = $this->products[$id]['response'];
            $profit = $response['final_balance'] - 1000;
            $native = ['protocol' => 'specialist_council_receipt_v1', 'decision_trace_identity' => ['decision_rows' => 10],
                'position_ledger' => [['entry_time' => '2025-12-20T12:00:00Z', 'exit_time' => '2025-12-20T12:04:00Z',
                    'net_pnl' => 5.0, 'outcome_matured' => true], ['entry_time' => '2025-12-20T12:05:00Z',
                    'exit_time' => '2025-12-20T12:10:00Z', 'net_pnl' => $profit - 5.0, 'outcome_matured' => false]],
                'account' => ['initial_balance' => 1000.0, 'final_balance' => 1000.0 + $profit, 'net_pnl' => $profit,
                    'fees' => 1.0, 'carry' => 0.0, 'spread_slippage' => 2.0]];
            $native['receipt_hash'] = $this->hash($native);
            $response['specialist_council_receipt'] = $native;
            $run->update(['response_hash' => app(LabImmutableEvidenceService::class)->hash($response)]);
            $this->artifact($run, 'evaluation_response', $response);
        }
        $assessment = $owner->settle($study['study_hash'], $runs);
        $this->assertSame('underpowered_local_diagnostic', $assessment['status'], $assessment['reason'] ?? '');
        $this->assertEquals(5.0, $assessment['arms']['L2A2']['matured_net_profit']);
        $this->assertEquals(60.0, $assessment['arms']['L2A2']['total_net_profit']);
        $this->assertEquals(3.0, $assessment['arms']['L2A2']['observed_costs']);
        $this->assertFalse($assessment['paper_authority']);
    }

    public function test_nonempty_malformed_native_receipt_cannot_fall_back_to_ordinary_ledger(): void
    {
        $owner = app(InstrumentMethodStudyService::class); $study = $owner->preregister($this->design);
        $runs = $this->runs($study['study_hash'], ['L1A1' => 10, 'L2A1' => 30, 'L1A2' => 25, 'L2A2' => 60]);
        $run = LabEvaluationRun::where('run_id', $runs[0])->first();
        $response = $this->products[$runs[0]]['response']; $response['specialist_council_receipt'] = 'invalid';
        $run->update(['response_hash' => app(LabImmutableEvidenceService::class)->hash($response)]);
        $this->artifact($run, 'evaluation_response', $response);
        $this->assertSame('STUDY_NATIVE_ORIGINAL_ACCOUNT_RECEIPT_INVALID', $owner->settle($study['study_hash'], $runs)['reason']);
        $this->assertNull(DB::table('instrument_method_studies')->value('assessment'));
    }

    private function runs(string $studyHash, array $profits, bool $censored = false): array
    {
        $owner = app(LabImmutableEvidenceService::class); $ids = [];
        foreach ($this->design['arms'] as $key => $arm) {
            $model = ModelVersion::findOrFail($arm['model_id']);
            $request = ['replay_dataset_hash' => $this->design['data_hash'], 'initial_balance' => 1000.0, 'risk_per_trade' => 1.0,
                'execution_contract' => ['execution_hash' => $this->design['execution_hash'], 'parameters' => $this->design['execution_costs']],
                'hard_limits' => $this->design['hard_limits'], 'policy_context' => ['instrument_method_study' => [
                    'study_hash' => $studyHash, 'arm' => $key, 'resource_caps' => $this->design['resource_caps']]]];
            $response = ['initial_balance' => 1000.0, 'final_balance' => 1000.0 + $profits[$key], 'total_trades' => 2,
                // Actual Python SimpleBacktestResponse defaults this port to {}.
                'specialist_council_receipt' => [],
                'trade_ledger' => [['entry_time' => '2025-12-20T12:00:00Z', 'exit_time' => '2025-12-20T12:04:00Z',
                    'balance' => 1005.0, 'exit_reason' => 'target', 'execution_cost_percent' => 0.02],
                    ['entry_time' => '2025-12-20T12:05:00Z', 'exit_time' => '2025-12-20T12:10:00Z',
                        'balance' => 1000.0 + $profits[$key], 'exit_reason' => $censored ? 'end_of_data' : 'stop', 'execution_cost_percent' => 0.02]],
                'decision_trace' => array_fill(0, 10, ['synthetic_software_data' => true]),
                'data_quality' => ['decision_trace' => ['evaluated_candle_count' => 10]]];
            $run = LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'model_version_id' => $model->id,
                'phase' => 'screening', 'mode' => 'research', 'status' => 'completed', 'started_at' => now()->addMinute(),
                'finished_at' => now()->addMinutes(2), 'duration_ms' => 1000, 'data_hash' => $this->design['data_hash'],
                'request_hash' => $owner->hash($request), 'response_hash' => $owner->hash($response)]);
            $requestArtifact = $this->artifact($run, 'evaluation_request', $request, ['request_hash' => $run->request_hash]);
            $this->artifact($run, 'evaluation_response', $response);
            $this->identities[$run->run_id] = ['request_artifact_hash' => $requestArtifact->sha256, 'model_version_id' => $model->id,
                'parameter_hash' => $this->hash((array) $model->parameters), 'runtime_basis' => $owner->modelRuntimeBasis($model)];
            $this->products[$run->run_id] = ['request' => $request, 'response' => $response]; $ids[] = $run->run_id;
        }
        return $ids;
    }
    private function replaceRequest(LabEvaluationRun $run, array $request): void
    {
        $owner = app(LabImmutableEvidenceService::class); $run->update(['request_hash' => $owner->hash($request)]);
        $artifact = $this->artifact($run, 'evaluation_request', $request, ['request_hash' => $run->request_hash]);
        $this->identities[$run->run_id]['request_artifact_hash'] = $artifact->sha256;
    }
    private function artifact(LabEvaluationRun $run, string $type, array $body, array $metadata = []): LabEvidenceArtifact
    {
        return app(LabImmutableEvidenceService::class)->recordArtifact($run, $type, $body, $metadata);
    }
    private function hash(array $body): string { return app(ResearchPaperEpochContractService::class)->parameterHash($body); }
}
