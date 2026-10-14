<?php

namespace Tests\Feature;

use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Services\InstrumentMethodMetaModelService;
use App\Services\ExecutionContractService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchPaperEpochContractService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Synthetic original artifacts test the producer; no independent market claim. */
class InstrumentMethodMetaModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('meta_original_test');
        config()->set('services.lab_evidence.disk', 'meta_original_test');
        $original = Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $original->shouldReceive('learningEligibility')->andReturnUsing(fn ($run) => ['complete' => $run->status === 'completed']);
        $original->shouldReceive('verifiedModelRuntimeIdentity')->andReturnUsing(function ($run) {
            $request = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')->first();
            return $request ? ['request_artifact_hash' => $request->sha256] : null;
        });
        $this->app->instance(LabImmutableEvidenceService::class, $original);
    }

    public function test_original_compressed_products_fit_finite_training_and_export_without_authority(): void
    {
        $run = $this->original(); $owner = app(InstrumentMethodMetaModelService::class);
        $registered = $owner->preregister($this->design([$run->run_id]));
        $this->assertSame('preregistered_training', $registered['status']);
        $this->assertSame('seen_training_only', $registered['design']['prior_exposure']);
        $this->assertSame('chronological_seen_diagnostic', $registered['design']['calibration_use']);
        $result = $owner->fit($registered['registration_hash']);
        $this->assertSame('fitted_diagnostic', $result['status'], json_encode($result));
        $this->assertSame(20, $result['assessment']['training_samples']);
        $this->assertSame(10, $result['assessment']['calibration_samples']);
        $this->assertFalse($result['economic_authority']);
        $this->assertFalse($result['independent_validation']);
        $export = $owner->exportModel($result['model_hash'], $registered['design']['binding']);
        $this->assertSame('exported_diagnostic', $export['status'], json_encode($export));
        $this->assertSame($export['artifact']['artifact_hash'], $owner->exportOriginalModel($result['model_hash'])['artifact']['artifact_hash']);
        $this->assertFalse($export['paper_authority']);
        $this->assertFalse($export['skill_credit']);
        $this->assertFalse($export['mastery_proven']);
        $this->assertCount(1, $export['artifact']['bins']);
        $this->assertCount(1, $export['artifact']['calibration_bins']);
        $this->assertSame(15, $export['artifact']['bins'][0]['wins']);
        $this->assertSame(6, $export['artifact']['calibration_bins'][0]['wins']);
        $this->assertEqualsWithDelta(7 / 12, $export['artifact']['calibration_bins'][0]['score'], 1e-12);
        $this->assertSame($result['model_hash'], $owner->fit($registered['registration_hash'])['model_hash']);
        $this->assertSame(1, DB::table('instrument_method_meta_models')->count());
        $this->assertSame(0, DB::table('lab_evolution_credit_events')->count());
        $this->assertSame(0, DB::table('research_experiment_work_items')->count());
        $this->assertSame(0, DB::table('agent_method_experiences')->count());
    }

    public function test_php_issued_original_artifact_executes_real_python_predictor(): void
    {
        $run = $this->original(); $owner = app(InstrumentMethodMetaModelService::class);
        $registered = $owner->preregister($this->design([$run->run_id]));
        $fit = $owner->fit($registered['registration_hash']);
        $export = $owner->exportModel($fit['model_hash'], $registered['design']['binding']);
        $this->assertSame('exported_diagnostic', $export['status']);
        $script = 'import json,sys; from app.services.instrument_method_meta_model import FiniteInstrumentMetaModel; '
            .'v=json.load(sys.stdin); m=FiniteInstrumentMetaModel(v["artifact"],v["expected_binding"]); '
            .'c={"regime":"trend_up","volatility":"normal_volatility","session":"london","direction":"BUY"}; '
            .'assert m.predict(c,.8,observed_at="2025-01-01T07:25:00+00:00",decision_at="2025-01-01T07:25:00+00:00") is None; '
            .'p=m.predict(c,.8,observed_at="2025-01-01T07:30:00+00:00",decision_at="2025-01-01T07:30:00+00:00"); '
            .'assert abs(p-7/12)<1e-12; print(json.dumps({"score":p,"research_only":m.body["research_only"]}))';
        $process = new Process([env('PYTHON_BINARY', 'python'), '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setInput(json_encode($export, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $process->setTimeout(30); $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $actual = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertEqualsWithDelta(7 / 12, $actual['score'], 1e-12);
        $this->assertTrue($actual['research_only']);
    }

    public function test_small_realistic_roster_terminally_underpowered_without_fake_classifier(): void
    {
        $run = $this->original(12); $owner = app(InstrumentMethodMetaModelService::class);
        $registered = $owner->preregister($this->design([$run->run_id]));
        $result = $owner->fit($registered['registration_hash']);
        $this->assertSame('underpowered', $result['status']);
        $this->assertNull($result['model_hash']);
        $this->assertSame(12, $result['assessment']['training_samples']);
        $this->assertSame(0, DB::table('instrument_method_meta_models')->count());
        $this->assertSame('underpowered', $owner->fit($registered['registration_hash'])['status']);
    }

    public function test_same_physical_originals_cannot_multiply_sample_power(): void
    {
        $one = $this->original(); $two = $this->original(); $owner = app(InstrumentMethodMetaModelService::class);
        $registered = $owner->preregister($this->design([$one->run_id, $two->run_id]));
        $result = $owner->fit($registered['registration_hash']);
        $this->assertSame('fitted_diagnostic', $result['status'], json_encode($result));
        $this->assertSame(20, $result['assessment']['training_samples']);
        $this->assertSame(10, $result['assessment']['calibration_samples']);
        $this->assertSame(30, $result['assessment']['duplicates_excluded']);
    }

    public function test_same_entry_conflicting_outcome_cannot_be_pooled(): void
    {
        $one = $this->original(); $two = $this->original(30, ['outcome_flip' => true]);
        $owner = app(InstrumentMethodMetaModelService::class);
        $registered = $owner->preregister($this->design([$one->run_id, $two->run_id]));
        $result = $owner->fit($registered['registration_hash']);
        $this->assertSame('META_MODEL_SAME_PHYSICAL_ENTRY_CONFLICT', $result['reason']);
        $this->assertSame(0, DB::table('instrument_method_meta_models')->count());
    }

    public function test_different_actual_method_library_or_selector_cannot_pool_labels(): void
    {
        $one = $this->original(30, ['library' => 'a']); $two = $this->original(30, ['library' => 'b']);
        $result = app(InstrumentMethodMetaModelService::class)->preregister($this->design([$one->run_id, $two->run_id]));
        $this->assertSame('META_MODEL_EFFECTIVE_BASELINE_MISMATCH', $result['reason']);
        $this->assertSame(0, DB::table('instrument_method_meta_registrations')->count());
    }

    public function test_caller_authority_low_power_or_postpaper_split_is_rejected(): void
    {
        $run = $this->original(); $owner = app(InstrumentMethodMetaModelService::class);
        $input = $this->design([$run->run_id]);
        $this->assertSame('META_MODEL_UNKNOWN_DESIGN_FIELD', $owner->preregister([...$input, 'Master' => true])['reason']);
        $this->assertSame('META_MODEL_BOUNDED_SAMPLE_REQUIREMENTS_REQUIRED', $owner->preregister([...$input, 'minimum_training' => 1])['reason']);
        $input['calibration_range']['end'] = '2026-01-01T00:00:00+00:00';
        $this->assertSame('META_MODEL_PRE2026_RANGE_REQUIRED', $owner->preregister($input)['reason']);
    }

    public function test_chronology_and_mature_label_not_entry_alone_owns_split(): void
    {
        $run = $this->original(); $owner = app(InstrumentMethodMetaModelService::class);
        $input = $this->design([$run->run_id]);
        $input['training_range']['end'] = '2025-01-01T04:50:00+00:00';
        $registered = $owner->preregister($input); $result = $owner->fit($registered['registration_hash']);
        $this->assertSame('underpowered', $result['status']);
        $this->assertSame(19, $result['assessment']['training_samples']);
        $this->assertSame(1, $result['assessment']['outside_or_boundary_labels_excluded']);
    }

    public function test_censored_originals_never_supply_positive_labels(): void
    {
        $run = $this->original(30, ['censored_last' => true]); $owner = app(InstrumentMethodMetaModelService::class);
        $registered = $owner->preregister($this->design([$run->run_id]));
        $result = $owner->fit($registered['registration_hash']);
        $this->assertSame('underpowered', $result['status']);
        $this->assertSame(9, $result['assessment']['calibration_samples']);
        $this->assertSame(1, $result['assessment']['censored_excluded']);
    }

    public function test_original_artifact_poison_and_rehashed_model_cannot_export(): void
    {
        $run = $this->original(); $owner = app(InstrumentMethodMetaModelService::class);
        $registered = $owner->preregister($this->design([$run->run_id]));
        $fit = $owner->fit($registered['registration_hash']);
        $model = DB::table('instrument_method_meta_models')->first();
        $body = json_decode($model->artifact_json, true); $body['bins'][0]['score'] = 0.99;
        DB::table('instrument_method_meta_models')->where('id', $model->id)->update(['artifact_json' => json_encode($body)]);
        $this->assertSame('META_MODEL_ORIGINAL_MODEL_PROOF_REQUIRED', $owner->exportModel($fit['model_hash'], $registered['design']['binding'])['reason']);
        $this->assertSame('META_MODEL_SERVER_ARTIFACT_SEAL_INVALID', $owner->inspect($registered['registration_hash'])['reason']);
    }

    public function test_artifacts_reopened_after_fit_and_source_or_trade_poison_fails_closed(): void
    {
        $run = $this->original(); $owner = app(InstrumentMethodMetaModelService::class);
        $registered = $owner->preregister($this->design([$run->run_id]));
        $owner->fit($registered['registration_hash']);
        $artifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'trade_ledger')->first();
        Storage::disk('meta_original_test')->put($artifact->storage_path, gzencode('[{"forged":true}]'));
        $this->assertSame('blocked', $owner->inspect($registered['registration_hash'])['status']);
        $this->assertSame(1, DB::table('instrument_method_meta_models')->count());
    }

    public function test_exact_binding_export_refuses_mismatching_execution_or_unknown_fields(): void
    {
        $run = $this->original(); $owner = app(InstrumentMethodMetaModelService::class);
        $registered = $owner->preregister($this->design([$run->run_id]));
        $fit = $owner->fit($registered['registration_hash']); $binding = $registered['design']['binding'];
        $this->assertSame('META_MODEL_EXACT_RUNTIME_BINDING_REQUIRED', $owner->exportModel($fit['model_hash'], [...$binding, 'trusted' => true])['reason']);
        $binding['execution_hash'] = str_repeat('b', 64);
        $this->assertSame('META_MODEL_RUNTIME_BINDING_MISMATCH', $owner->exportModel($fit['model_hash'], $binding)['reason']);
    }

    public function test_both_classes_required_and_finite_scan_budget_preserved(): void
    {
        $run = $this->original(30, ['all_positive' => true]); $owner = app(InstrumentMethodMetaModelService::class);
        $registered = $owner->preregister($this->design([$run->run_id]));
        $result = $owner->fit($registered['registration_hash']);
        $this->assertSame('META_MODEL_BOTH_CLASSES_IN_EACH_SPLIT_REQUIRED', $result['assessment']['reason']);
        $input = [...$this->design([$run->run_id]), 'model_key' => 'scan-capped', 'max_events' => 20];
        $registered = $owner->preregister($input);
        $this->assertSame('META_MODEL_ORIGINAL_RESOURCE_CAP_EXCEEDED', $owner->fit($registered['registration_hash'])['reason']);
    }

    public function test_actual_account_risk_and_hard_limit_controls_have_separate_physical_identity(): void
    {
        $owner = app(InstrumentMethodMetaModelService::class);
        $base = ['initial_balance' => 10000.0, 'risk_per_trade' => 1.0,
            'policy_context' => ['hard_limits' => ['max_account_risk' => 5.0]]];
        $hash = $owner->physicalAccountRiskHash($base);
        foreach ([['initial_balance' => 20000.0], ['risk_per_trade' => 2.0],
            ['policy_context' => ['hard_limits' => ['max_account_risk' => 10.0]]],
            ['policy_context' => ['hard_limits' => ['max_account_risk' => 5.0], 'capital_allocation' => 0.5]]] as $change) {
            $this->assertNotSame($hash, $owner->physicalAccountRiskHash([...$base, ...$change]));
        }
        $intervention = $base; $intervention['policy_context']['instrument_method_learning'] = ['new_meta_recipe' => true];
        $this->assertSame($hash, $owner->physicalAccountRiskHash($intervention));
    }

    public function test_account_scalars_keep_numeric_identity_across_php_integer_and_pydantic_float_wire(): void
    {
        $owner = app(InstrumentMethodMetaModelService::class);
        $original = ['initial_balance' => 10000, 'risk_per_trade' => 1,
            'policy_context' => ['hard_limits' => ['max_account_risk' => 5], 'risk_policy' => ['enabled' => false]]];
        $wire = [...$original, 'initial_balance' => 10000.0, 'risk_per_trade' => 1.0];
        $hash = $owner->physicalAccountRiskHash($original);
        $this->assertSame($hash, $owner->physicalAccountRiskHash($wire));
        $this->assertSame($hash, $owner->physicalAccountRiskHash([...$wire, 'initial_balance' => 1e4]));
        $this->assertNotSame($hash, $owner->physicalAccountRiskHash([...$wire, 'risk_per_trade' => 1.01]));
        $changedFlag = $wire; $changedFlag['policy_context']['risk_policy']['enabled'] = 0;
        $this->assertNotSame($hash, $owner->physicalAccountRiskHash($changedFlag));
        $changedLimit = $wire; $changedLimit['policy_context']['hard_limits']['max_account_risk'] = 5.0;
        $this->assertNotSame($hash, $owner->physicalAccountRiskHash($changedLimit));
    }

    public function test_account_scalar_bool_string_nonfinite_and_missing_values_cannot_alias_real_controls(): void
    {
        $owner = app(InstrumentMethodMetaModelService::class);
        foreach (['initial_balance', 'risk_per_trade'] as $field) {
            foreach ([true, false, '10000', null, INF, -INF, NAN, [], ['value' => 1]] as $invalid) {
                try {
                    $owner->physicalAccountRiskHash(['initial_balance' => 10000, 'risk_per_trade' => 1, $field => $invalid]);
                    $this->fail('Invalid physical scalar was accepted: '.$field);
                } catch (\LogicException $error) {
                    $this->assertSame('META_MODEL_PHYSICAL_ACCOUNT_RISK_REQUIRED', $error->getMessage());
                }
            }
        }
    }

    public function test_year_end_forced_or_late_observable_close_is_excluded_without_poisoning_safe_roster(): void
    {
        $owner = app(InstrumentMethodMetaModelService::class);
        foreach ([false, true] as $censored) {
            $run = $this->original(2, ['year_end' => true, 'censored_last' => $censored]);
            $input = ['model_key' => 'year-end-'.(int) $censored, 'run_ids' => [$run->run_id],
                'training_range' => ['start' => '2025-12-31T23:00:00+00:00', 'end' => '2025-12-31T23:45:00+00:00'],
                'calibration_range' => ['start' => '2025-12-31T23:46:00+00:00', 'end' => '2025-12-31T23:59:59+00:00']];
            $registered = $owner->preregister($input); $fit = $owner->fit($registered['registration_hash']);
            $this->assertSame('underpowered', $fit['status'], json_encode($fit));
            $this->assertSame(1, $fit['assessment']['training_samples']);
            $this->assertSame(0, $fit['assessment']['calibration_samples']);
            $this->assertSame($censored ? 1 : 0, $fit['assessment']['censored_excluded']);
            $this->assertSame($censored ? 0 : 1, $fit['assessment']['post_cutoff_labels_excluded']);
        }
        $this->assertSame(0, DB::table('instrument_method_meta_models')->count());
    }

    protected function design(array $ids): array
    {
        return ['model_key' => 'original-finite-test', 'run_ids' => $ids,
            'training_range' => ['start' => '2025-01-01T00:00:00+00:00', 'end' => '2025-01-01T04:55:00+00:00'],
            'calibration_range' => ['start' => '2025-01-01T05:00:00+00:00', 'end' => '2025-01-01T07:25:00+00:00']];
    }

    protected function original(int $trades = 30, array $options = []): LabEvaluationRun
    {
        $owner = app(LabImmutableEvidenceService::class);
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy' => 'differential_router',
            'parameters' => $options['parameters'] ?? ['entry_confidence' => 0.5], 'initial_balance' => 10000.0, 'risk_per_trade' => 1.0,
            'replay_dataset_hash' => str_repeat('d', 64), 'execution_contract' => $execution,
            'research_release' => ['python_source_hash' => str_repeat('f', 64)]];
        if (array_key_exists('policy_context', $options)) $request['policy_context'] = $options['policy_context'];
        if (isset($options['library'])) {
            $method = ['library_hash' => str_repeat($options['library'], 64), 'policy' => ['rules' => [], 'default_action' => 'BASELINE']];
            $method['contract_hash'] = $this->hash($method); $request['instrument_method_learning'] = $method;
        }
        $trace = []; $ledger = []; $balance = 10000.0;
        for ($i = 0; $i < $trades; $i++) {
            $entry = CarbonImmutable::parse(($options['year_end'] ?? false)
                ? '2025-12-31T23:35:00+00:00' : '2025-01-01T00:00:00+00:00')->addMinutes(15 * $i);
            $success = ($options['all_positive'] ?? false) || ($i < 20 ? $i % 4 !== 3 : ($i - 20) % 5 < 3);
            if (($options['outcome_flip'] ?? false) && $i === 0) $success = ! $success;
            $balance += $success ? 10.0 : -10.0;
            $trace[] = ['candle_time' => $entry->toIso8601String(), 'event_type' => 'signal_evaluation', 'accepted' => true,
                'action' => 'BUY', 'confidence' => 0.8, 'market_regime' => 'trend_up', 'volatility_regime' => 'normal_volatility',
                'features' => ['market_session' => 'london', 'candle_high' => 999999.0]];
            $ledger[] = ['entry_time' => $entry->toIso8601String(), 'signal_time' => $entry->subMinutes(5)->toIso8601String(),
                'exit_time' => $entry->addMinutes(5)->toIso8601String(), 'direction' => 'BUY', 'signal_confidence' => 0.8,
                'exit_reason' => ($options['censored_last'] ?? false) && $i === $trades - 1 ? 'end_of_data' : 'take_profit',
                'execution_cost_percent' => 0.01, 'balance' => $balance];
        }
        $clock = ['protocol' => 'replay_executed_clock_v1', 'complete' => true,
            'semantics' => 'previous_closed_candle_next_open_v1', 'duration_seconds' => 300,
            'owner' => 'ordinary_single_position_v1', 'index_basis' => 'evaluated_frame_zero_based_v1',
            'execution_timeframe' => 'M5', 'input_rows' => 90, 'evaluation_offset_rows' => 0,
            'decision_rows' => 89, 'first_evaluation_index' => 1, 'last_evaluation_index' => 89,
            'index_set_hash' => hash('sha256', "replay-executed-clock-v1:indices\n".implode("\n", range(1, 89))."\n"),
            'schedule_hash' => str_repeat('a', 64), 'promotion_evidence' => false,
            'dataset_hash' => $request['replay_dataset_hash'], 'execution_hash' => $request['execution_contract']['execution_hash'],
            'signal_start' => '2024-12-31T23:55:00+00:00', 'signal_end' => '2025-01-01T07:15:00+00:00',
            'execution_start' => '2025-01-01T00:00:00+00:00', 'execution_end' => '2025-01-01T07:20:00+00:00'];
        if ($options['year_end'] ?? false) $clock = [...$clock, 'input_rows' => 6, 'decision_rows' => 5,
            'last_evaluation_index' => 5, 'index_set_hash' => hash('sha256', "replay-executed-clock-v1:indices\n1\n2\n3\n4\n5\n"),
            'signal_start' => '2025-12-31T23:30:00+00:00', 'signal_end' => '2025-12-31T23:50:00+00:00',
            'execution_start' => '2025-12-31T23:35:00+00:00', 'execution_end' => '2025-12-31T23:55:00+00:00'];
        $clock['receipt_hash'] = $this->hash($clock);
        $clock['receipt_json'] = $this->canonicalJson(array_diff_key($clock, ['receipt_hash' => true]));
        $response = ['decision_trace' => $trace, 'trade_ledger' => $ledger, 'initial_balance' => 10000.0,
            'final_balance' => $balance, 'total_trades' => $trades, 'execution_contract' => $execution,
            'data_quality' => ['replay_executed_clock' => $clock]];
        $run = LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'phase' => 'screening', 'mode' => 'research',
            'status' => 'completed', 'started_at' => now()->subMinutes(2), 'finished_at' => now(),
            'code_hash' => str_repeat('c', 64), 'data_hash' => $request['replay_dataset_hash'],
            'request_hash' => $owner->hash($request), 'response_hash' => $owner->hash($response)]);
        $owner->recordArtifact($run, 'evaluation_request', $request, ['request_hash' => $run->request_hash]);
        $owner->recordArtifact($run, 'evaluation_response', $response);
        $owner->recordArtifact($run, 'decision_trace', $trace, ['complete' => true]);
        $owner->recordArtifact($run, 'trade_ledger', $ledger, ['complete' => true]);
        return $run;
    }
    private function hash(array $value): string { return app(ResearchPaperEpochContractService::class)->parameterHash($value); }
    private function canonicalJson(array $value): string
    {
        $sort = function ($item) use (&$sort) { if (! is_array($item)) return $item; if (! array_is_list($item)) ksort($item); return array_map($sort, $item); };
        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
