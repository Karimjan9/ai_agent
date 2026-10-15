<?php

namespace Tests\Feature;

use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\ModelVersion;
use App\Services\AgentMethodLearningService;
use App\Services\ExecutionContractService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\InstrumentMethodMetaBindingService;
use App\Services\InstrumentMethodMetaModelService;
use App\Services\InstrumentMethodResearchBindingService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/** Conditional original-product software evidence, never independently qualified market data. */
class InstrumentMethodNativeMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // This is a prospectively declared inline, single-resolution software
        // fixture, not a fake sealed MTF/native archive qualification.
        config()->set('services.mtf_pilot.enabled', false);
        Storage::fake('native_meta_original'); config()->set('services.lab_evidence.disk', 'native_meta_original');
        $owner = Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $owner->shouldReceive('learningEligibility')->andReturnUsing(fn ($run) => ['complete' => $run->status === 'completed']);
        $owner->shouldReceive('verifiedModelRuntimeIdentity')->andReturnUsing(function ($run) {
            $request = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')->first();
            return $request ? ['request_artifact_hash' => $request->sha256] : null;
        });
        $this->app->instance(LabImmutableEvidenceService::class, $owner);
    }

    public function test_native_issuer_uses_own_mature_position_net_not_shared_cash_and_ordinary_issuer_stays_closed(): void
    {
        [$run] = $this->original('confidence_string'); $owner = app(InstrumentMethodMetaModelService::class);
        $ordinary = $owner->preregister(array_diff_key($this->design([$run->run_id]), ['native_member' => true]));
        $this->assertSame('META_MODEL_ORDINARY_SOURCE_REQUIRED', $ordinary['reason']);
        $registered = $owner->preregisterNative($this->design([$run->run_id]));
        $this->assertSame('preregistered_training', $registered['status'], json_encode($registered));
        $fit = $owner->fit($registered['registration_hash']);
        $this->assertSame('fitted_diagnostic', $fit['status'], json_encode($fit));
        $this->assertSame(20, $fit['assessment']['training_samples']); $this->assertSame(10, $fit['assessment']['calibration_samples']);
        $export = $owner->exportOriginalModel($fit['model_hash']);
        $this->assertSame(InstrumentMethodMetaModelService::NATIVE_PROTOCOL, $export['artifact']['protocol']);
        $this->assertSame(15, $export['artifact']['bins'][0]['wins']); $this->assertSame(6, $export['artifact']['calibration_bins'][0]['wins']);
        $this->assertEqualsWithDelta(7 / 12, $export['artifact']['calibration_bins'][0]['score'], 1e-12);
        foreach (['skill_credit', 'mastery_proven', 'paper_authority', 'independent_validation', 'economic_authority'] as $field)
            $this->assertFalse($export[$field]);
        $this->assertSame($fit['model_hash'], $owner->fit($registered['registration_hash'])['model_hash']);
        $ordinaryTarget = $this->model('ordinary');
        $refused = app(InstrumentMethodMetaBindingService::class)->attachUnusedModel($ordinaryTarget->id, $fit['model_hash']);
        $this->assertSame('blocked', $refused['status']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0); $this->assertDatabaseCount('paper_authority_admissions', 0);
    }

    public function test_censored_missing_context_corrupt_own_close_and_shared_account_conflicts_never_issue_a_model(): void
    {
        foreach (['censored', 'missing_session', 'bad_confidence', 'foreign_management', 'broken_account', 'duplicate_position'] as $kind) {
            [$run] = $this->original($kind); $owner = app(InstrumentMethodMetaModelService::class);
            $design = $this->design([$run->run_id]); $design['model_key'] .= '-'.$kind;
            $registered = $owner->preregisterNative($design);
            $this->assertSame('preregistered_training', $registered['status'], json_encode($registered));
            $fit = $owner->fit($registered['registration_hash']);
            $this->assertContains($fit['status'], ['underpowered', 'blocked'], json_encode($fit));
            $this->assertNull($fit['model_hash'] ?? null); $this->assertDatabaseCount('instrument_method_meta_models', 0);
        }
    }

    public function test_exact_original_aliases_do_not_add_native_training_power(): void
    {
        [$first] = $this->original(); [$alias] = $this->original('', 30, $this->lastSource);
        $owner = app(InstrumentMethodMetaModelService::class); $registered = $owner->preregisterNative($this->design([$first->run_id, $alias->run_id]));
        $this->assertSame('preregistered_training', $registered['status'], json_encode($registered));
        $fit = $owner->fit($registered['registration_hash']);
        $this->assertSame('fitted_diagnostic', $fit['status'], json_encode($fit));
        $this->assertSame(30, $fit['assessment']['duplicates_excluded']); $this->assertSame(20, $fit['assessment']['training_samples']);
    }

    public function test_canonical_fresh_council_target_revalidates_actual_account_and_executes_php_export_in_python(): void
    {
        [$run] = $this->original(); $issuer = app(InstrumentMethodMetaModelService::class);
        $registered = $issuer->preregisterNative($this->design([$run->run_id])); $fit = $issuer->fit($registered['registration_hash']);
        $this->assertSame('fitted_diagnostic', $fit['status'], json_encode($fit));
        [$version, $request, $bundle] = $this->council('fresh-target', true);
        $binder = app(InstrumentMethodMetaBindingService::class);
        $beforeModels = ModelVersion::orderBy('id')->get()->toArray();
        $changedScope = $bundle; $changedScope['manifest']['streams']['M5']['first_candle_at'] = '2025-01-02T00:00:00Z';
        $scopeRefusal = $binder->attachUnusedNativeCouncil($version->id, 'hour', $fit['model_hash'], $request, $changedScope);
        $this->assertSame('META_BINDING_NATIVE_PHYSICAL_SCOPE_DRIFT', $scopeRefusal['reason'], json_encode($scopeRefusal));
        $target = $binder->attachUnusedNativeCouncil($version->id, 'hour', $fit['model_hash'], $request, $bundle);
        $this->assertSame('attached_native_meta_model_prospectively', $target['status'], json_encode($target));
        $native = app(SpecialistCouncilLifecycleService::class)->runtimeContract($version, $request['replay_dataset_hash'],
            $request['execution_contract']['execution_hash'], 'M5', $bundle, 'XAUUSD');
        $this->assertSame($fit['model_hash'], $native['members'][0]['method_learning']['meta_model']['artifact']['artifact_hash']);
        $request['specialist_council_contract'] = $native;
        // Exercise the actual evaluator's canonical final request binding, not
        // a second dispatcher or a test-only model attachment route.
        $method = new \ReflectionMethod(\App\Services\LabAgentEvaluationService::class, 'bindCouncilEvaluationRequests');
        $request = $method->invoke(app(\App\Services\LabAgentEvaluationService::class), $request, []);
        $this->assertSame($beforeModels, ModelVersion::orderBy('id')->get()->toArray());
        $wire = $this->python('binding', $request);
        $this->assertSame($native['members'][0]['method_learning']['meta_model']['evaluation_binding'], $wire);
        $actual = $this->python('replay', $request);
        $receipt = $actual['specialist_council_receipt'];
        $this->assertSame('computed', $receipt['status']);
        $this->assertEqualsWithDelta(0, $receipt['account']['reconciliation_error'], 1e-7);
        $this->assertSame($native['contract_hash'], $receipt['contract_hash']);
        $members = array_values(array_filter($receipt['members'], fn ($member) => $member['specialist_id'] === 'hour'));
        $this->assertCount(1, $members);
        $samples = $members[0]['method_learning_receipt']['samples'];
        $this->assertNotEmpty($samples, 'Actual original native execution must reach the method, not merely compile it.');
        $statuses = array_column($samples, 'meta_score_status');
        // Actual plain EMA supplies no confidence. The real account must not
        // invent that feature merely to satisfy a positive software fixture.
        $this->assertContains('signal_confidence_unavailable', $statuses);
        foreach ($samples as $sample) $this->assertFalse($sample['feature_availability']['method_meta_model_available']);
        foreach (['initial_balance' => 20000.25, 'risk_per_trade' => .75, 'policy_context' => ['new_behavior_guard' => true], 'evaluation_mode' => 'full'] as $field => $value) {
            $changed = $request; $changed[$field] = $value;
            try { $binder->bindNativeRequests($changed); $this->fail('Expected physical parent drift refusal'); }
            catch (\LogicException $error) { $this->assertSame('META_BINDING_NATIVE_ACTUAL_PARENT_ACCOUNT_DRIFT', $error->getMessage()); }
        }
        $this->assertSame('META_BINDING_NATIVE_TARGET_ALREADY_DECLARED', $binder->attachUnusedNativeCouncil($version->id, 'hour', $fit['model_hash'], $request, $bundle)['reason']);
        $this->assertSame('META_BINDING_NATIVE_SINGLE_ASSET_MEMBER_REQUIRED', $binder->attachUnusedNativeCouncil($version->id, 'swing', $fit['model_hash'], $request, $bundle)['reason']);
        $this->assertCount(1, DB::table('instrument_method_meta_registrations')->where('model_key', 'like', 'native-target:'.$version->id.':%')->get());
        $version->update(['state' => 'evaluating']);
        $this->assertSame('META_BINDING_NATIVE_UNUSED_DRAFT_REQUIRED', $binder->attachUnusedNativeCouncil($version->id, 'hour', $fit['model_hash'], $request, $bundle)['reason']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0); $this->assertDatabaseCount('research_experiment_work_items', 0);
    }

    public function test_native_physical_scope_keeps_peer_risk_horizon_and_unknown_policy_but_excludes_only_transport_metadata(): void
    {
        [, $request] = $this->council('scope-source'); $owner = app(InstrumentMethodMetaModelService::class);
        $native = $request['specialist_council_contract']; $member = $native['members'][0];
        $binding = $owner->nativeEvaluationBinding($request, $native, $member);
        foreach (['trial_ledger'] as $metadata) {
            $changed = $request; $changed['policy_context'][$metadata] = ['different_generation' => 987];
            $this->assertSame($binding, $owner->nativeEvaluationBinding($changed, $native, $member));
        }
        foreach (['repair_contracts', 'repair_contract', 'snapshot_transport', 'historical_stratified_windows', 'full_replay_runtime_policy', 'prospective_probe_window', 'data_boundary'] as $behavior) {
            $changed = $request; $changed['policy_context'][$behavior] = ['rows' => 15001, 'warmup_rows' => 0];
            $this->assertNotSame($binding['council_physical_hash'], $owner->nativeEvaluationBinding($changed, $native, $member)['council_physical_hash']);
        }
        foreach (['capital_weight', 'risk_per_trade_percent', 'horizon', 'parameters'] as $field) {
            $changed = $native; $changed['members'][1][$field] = $field === 'horizon' ? ['kind' => 'swing'] : ($field === 'parameters' ? ['ema_fast' => 6] : .2);
            $this->assertNotSame($binding['council_physical_hash'], $owner->nativeEvaluationBinding($request, $changed, $member)['council_physical_hash']);
        }
        $changed = $request; $changed['policy_context']['unknown_guard'] = true;
        $this->assertNotSame($binding['council_physical_hash'], $owner->nativeEvaluationBinding($changed, $native, $member)['council_physical_hash']);
        foreach (['signal_delay_candles' => 1, 'random_seed' => 7, 'from_date' => '2025-02-04', 'to_date' => '2025-02-05',
            'volume_context' => ['symbol' => 'XAUUSD'], 'mtf_pilot' => ['enabled' => true, 'mode' => 'h1_veto'], 'dataset_tail_rows' => 100] as $field => $value) {
            $changed = $request; $changed[$field] = $value;
            $this->assertNotSame($binding['council_physical_hash'], $owner->nativeEvaluationBinding($changed, $native, $member)['council_physical_hash'], $field);
        }
        $changed = $native; $changed['members'][1]['method_learning'] = $changed['members'][0]['method_learning'];
        $this->assertNotSame($binding['effective_policy_hash'], $owner->nativeEvaluationBinding($request, $changed, $member)['effective_policy_hash']);
        $withPeer = $changed;
        $peerBinding = $owner->nativeEvaluationBinding($request, $withPeer, $member);
        foreach (['scope.context' => ['session' => 'london'], 'scope.evaluated_start' => '2025-02-01T00:00:00Z',
            'scope.evaluated_end' => '2025-11-30T23:55:00Z', 'strategy_family' => 'differential_router'] as $field => $value) {
            $changed = $withPeer; data_set($changed['members'][1]['method_learning'], $field, $value);
            $this->assertNotSame($peerBinding['effective_policy_hash'], $owner->nativeEvaluationBinding($request, $changed, $member)['effective_policy_hash'], $field);
        }
        $changed = $withPeer;
        $changed['members'][1]['method_learning']['meta_model'] = ['artifact' => ['artifact_hash' => str_repeat('f', 64)]];
        try { $owner->nativeEvaluationBinding($request, $changed, $member); $this->fail('Expected peer asset refusal'); }
        catch (\LogicException $error) { $this->assertSame('META_MODEL_NATIVE_PEER_ASSET_UNSUPPORTED', $error->getMessage()); }
        foreach ([['policy_context', null, 'META_MODEL_NATIVE_POLICY_CONTEXT_REQUIRED'],
            ['policy_context', [true], 'META_MODEL_NATIVE_POLICY_CONTEXT_REQUIRED'],
            ['hard_limits', [1], 'META_MODEL_NATIVE_HARD_LIMIT_OBJECT_REQUIRED'],
            ['signal_delay_candles', true, 'META_MODEL_NATIVE_PARENT_RUNTIME_PORT_INVALID']] as [$field, $value, $reason]) {
            $changed = $request; $changed[$field] = $value;
            try { $owner->nativeEvaluationBinding($changed, $native, $member); $this->fail('Expected typed native port refusal'); }
            catch (\LogicException $error) { $this->assertSame($reason, $error->getMessage()); }
        }
        $changed = $native; $changed['members'][0]['method_learning']['policy']['rules'] = ['not' => 'a-list'];
        try { $owner->nativeEvaluationBinding($request, $changed, $changed['members'][0]); $this->fail('Expected malformed policy refusal'); }
        catch (\LogicException $error) { $this->assertSame('META_MODEL_NATIVE_MEMBER_POLICY_OBJECT_REQUIRED', $error->getMessage()); }
    }

    public function test_real_differential_programme_consumes_server_issued_native_score_under_same_optional_policy(): void
    {
        // A separate fixed software question, not a replay/tuning of the EMA
        // market result. This programme natively emits confidence; no score or
        // raw signal is injected and no PnL success is required.
        $this->fixtureFamily = 'differential_router';
        [$run] = $this->original(); $issuer = app(InstrumentMethodMetaModelService::class);
        $registered = $issuer->preregisterNative($this->design([$run->run_id])); $fit = $issuer->fit($registered['registration_hash']);
        $this->assertSame('fitted_diagnostic', $fit['status'], json_encode($fit));
        [$version, $request, $bundle] = $this->council('native-differential-target', true);
        $target = app(InstrumentMethodMetaBindingService::class)->attachUnusedNativeCouncil($version->id, 'hour', $fit['model_hash'], $request, $bundle);
        $this->assertSame('attached_native_meta_model_prospectively', $target['status'], json_encode($target));
        $request['specialist_council_contract'] = app(SpecialistCouncilLifecycleService::class)->runtimeContract($version, $request['replay_dataset_hash'],
            $request['execution_contract']['execution_hash'], 'M5', $bundle, 'XAUUSD');
        $method = new \ReflectionMethod(\App\Services\LabAgentEvaluationService::class, 'bindCouncilEvaluationRequests');
        $request = $method->invoke(app(\App\Services\LabAgentEvaluationService::class), $request, []);
        $this->assertSame($request['specialist_council_contract']['members'][0]['method_learning']['meta_model']['evaluation_binding'], $this->python('binding', $request));
        $actual = $this->python('replay', $request); $receipt = $actual['specialist_council_receipt'];
        $this->assertSame('computed', $receipt['status']); $this->assertEqualsWithDelta(0, $receipt['account']['reconciliation_error'], 1e-7);
        $hour = array_values(array_filter($receipt['members'], fn ($member) => $member['specialist_id'] === 'hour'))[0];
        $samples = $hour['method_learning_receipt']['samples'];
        $scored = array_values(array_filter($samples, fn ($sample) => ($sample['meta_score_status'] ?? '') === 'diagnostic_calibrated_score_available'));
        $closed = [];
        foreach ($actual['decision_trace'] as $row) foreach ($row['member_decisions'] ?? [] as $member)
            if ($member['specialist_id'] === 'hour' && in_array($member['raw_signal'], ['BUY', 'SELL'], true))
                $closed[] = array_intersect_key($member['closed_inputs'], array_flip(['time', 'signal_confidence', 'market_regime', 'volatility_regime', 'market_session']));
        $this->assertNotEmpty($scored, json_encode(['statuses' => array_count_values(array_column($samples, 'meta_score_status')),
            'contexts' => array_slice(array_column($samples, 'context'), 0, 3), 'closed' => array_slice($closed, 0, 6)]));
        foreach ($scored as $sample) {
            $this->assertSame($fit['model_hash'], $sample['meta_model_hash']);
            $this->assertTrue($sample['feature_availability']['method_meta_model_available']);
            $this->assertTrue($sample['used']); $this->assertFalse($sample['outputs']['confirmation']);
        }
        $this->assertDatabaseCount('lab_evolution_credit_events', 0); $this->assertDatabaseCount('paper_authority_admissions', 0);
    }

    public function test_native_target_attachment_precedes_any_version_wrapper_enrollment_even_without_a_replay(): void
    {
        [$run] = $this->original(); $issuer = app(InstrumentMethodMetaModelService::class);
        $registered = $issuer->preregisterNative($this->design([$run->run_id])); $fit = $issuer->fit($registered['registration_hash']);
        [$version, $request, $bundle] = $this->council('already-enrolled', true);
        $carrier = $this->model('native-wrapper'); $carrier->update(['metadata' => [...$carrier->metadata,
            'specialist_council' => ['version_id' => $version->id]]]);
        $lab = \App\Models\AiLaboratory::create(['name' => 'native-meta-sealed-question-fixture', 'symbol' => 'XAUUSD',
            'timeframe' => 'M5', 'strategy_families' => ['ema_rsi_v1']]);
        $generation = \App\Models\LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'draft']);
        \App\Models\LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $carrier->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy_family' => 'ema_rsi_v1', 'origin' => 'synthetic-native-meta-fixture',
            'lifecycle_status' => 'draft', 'parameter_diff' => []]);
        $this->assertSame(0, LabEvaluationRun::where('model_version_id', $carrier->id)->count());
        $blocked = app(InstrumentMethodMetaBindingService::class)->attachUnusedNativeCouncil($version->id, 'hour', $fit['model_hash'], $request, $bundle);
        $this->assertSame('META_BINDING_NATIVE_UNUSED_DRAFT_REQUIRED', $blocked['reason'], json_encode($blocked));
        $this->assertSame(0, DB::table('instrument_method_meta_registrations')->where('model_key', 'like', 'native-target:%')->count());
    }

    public function test_existing_typed_cli_can_preregister_native_originals_without_dispatch_or_authority(): void
    {
        [$run] = $this->original(); $design = $this->design([$run->run_id]);
        Storage::disk('native_meta_original')->put('native-register.json', json_encode($design, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $this->artisan('trading:instrument-methods', ['action' => 'meta-native-register', '--input' => Storage::disk('native_meta_original')->path('native-register.json'), '--json' => true])
            ->assertExitCode(0)->expectsOutputToContain('preregistered_training');
        $this->assertDatabaseCount('instrument_method_meta_registrations', 1); $this->assertDatabaseCount('instrument_method_meta_models', 0);
        $design['confirmed'] = true;
        Storage::disk('native_meta_original')->put('native-bad-register.json', json_encode($design, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $this->artisan('trading:instrument-methods', ['action' => 'meta-native-register', '--input' => Storage::disk('native_meta_original')->path('native-bad-register.json'), '--json' => true])
            ->assertExitCode(1)->expectsOutputToContain('META_MODEL_UNKNOWN_DESIGN_FIELD');
        $this->assertDatabaseCount('research_experiment_work_items', 0); $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $reserved = $this->design([$run->run_id]); $reserved['model_key'] = 'native-target:999:hour';
        $this->assertSame('META_MODEL_RESERVED_TARGET_NAMESPACE', app(InstrumentMethodMetaModelService::class)->preregisterNative($reserved)['reason']);
        unset($reserved['native_member']);
        $this->assertSame('META_MODEL_RESERVED_TARGET_NAMESPACE', app(InstrumentMethodMetaModelService::class)->preregister($reserved)['reason']);
        $reserved['model_key'] = 'NATIVE-TARGET:999:hour';
        $this->assertSame('META_MODEL_RESERVED_TARGET_NAMESPACE', app(InstrumentMethodMetaModelService::class)->preregister($reserved)['reason']);
    }

    private ?array $lastSource = null;
    private string $fixtureFamily = 'ema_rsi_v1';

    private function design(array $ids): array
    {
        return ['model_key' => 'native-meta-'.Str::uuid(), 'native_member' => 'hour', 'run_ids' => $ids,
            'training_range' => ['start' => '2025-01-01T00:00:00Z', 'end' => '2025-01-01T04:55:00Z'],
            'calibration_range' => ['start' => '2025-01-01T05:00:00Z', 'end' => '2025-01-01T07:25:00Z']];
    }

    private function original(string $kind = '', int $count = 30, ?array $same = null): array
    {
        if ($same === null) [, $request] = $this->council('source-'.Str::uuid()); else $request = $same;
        $this->lastSource = $request; $native = $request['specialist_council_contract']; $trace = []; $positions = []; $ledger = [];
        $seconds = 300; $accountNet = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $entry = CarbonImmutable::parse('2025-01-01T00:00:00Z')->addMinutes(15 * $i); $decisions = [];
            foreach ($native['members'] as $index => $member) {
                $positionId = $member['specialist_id'].'-'.$i; $memberHash = $this->hash(['council_version' => $native['council_version'], 'member' => $member]);
                $net = $index === 1 ? 777.0 : (($i < 20 ? $i % 4 !== 3 : ($i - 20) % 5 < 3) ? 10.0 : -10.0);
                $accountNet += $net;
                $input = ['time' => $entry->subMinutes(5)->toIso8601String(), 'signal_confidence' => $kind === 'confidence_string' ? '0.8' : ($kind === 'bad_confidence' ? 'NaN' : .8),
                    'market_regime' => 'trend_up', 'volatility_regime' => 'normal_volatility', 'market_session' => 'asia'];
                if ($kind === 'missing_session') unset($input['market_session']);
                $decisions[] = ['specialist_id' => $member['specialist_id'], 'member_version_hash' => $memberHash,
                    'accepted' => true, 'action' => 'BUY', 'position_id' => $positionId, 'closed_inputs' => $input];
                $positions[] = ['position_id' => $positionId, 'specialist_id' => $member['specialist_id'],
                    'member_version_hash' => $memberHash, 'model_version_id' => $member['model_version_id'],
                    'management_owner' => $kind === 'foreign_management' && $index === 0 ? 'swing' : $member['specialist_id'],
                    'management_version' => $member['management_version'], 'direction' => 'BUY',
                    'entry_time' => $entry->toIso8601String(), 'exit_time' => $entry->addMinutes(5)->toIso8601String(),
                    'net_pnl' => $net, 'gross_pnl_after_embedded_cost' => $net + .75, 'fees' => .5, 'carry' => .25, 'spread_slippage' => .1,
                    'exit_reason' => $kind === 'censored' && $i >= 20 && $index === 0 ? 'end_of_data' : 'take_profit',
                    'outcome_matured' => ! ($kind === 'censored' && $i >= 20 && $index === 0)];
                $ledger[] = ['portfolio_member' => $member['specialist_id'], 'entry_time' => $entry->toIso8601String(), 'balance' => 10000.25 + $accountNet];
            }
            $trace[] = ['event_type' => 'signal_evaluation', 'candle_time' => $entry->toIso8601String(),
                'signal_time' => $entry->subMinutes(5)->toIso8601String(), 'member_decisions' => $decisions];
        }
        if ($kind === 'duplicate_position') $positions[1]['position_id'] = $positions[0]['position_id'];
        $clock = ['protocol' => 'replay_executed_clock_v1', 'complete' => true, 'owner' => 'native_specialist_council_v1',
            'semantics' => 'previous_closed_candle_next_open_v1', 'duration_seconds' => $seconds, 'execution_timeframe' => 'M5',
            'index_basis' => 'evaluated_frame_zero_based_v1', 'input_rows' => 90, 'evaluation_offset_rows' => 0, 'decision_rows' => 89,
            'first_evaluation_index' => 1, 'last_evaluation_index' => 89, 'promotion_evidence' => false,
            'dataset_hash' => $request['replay_dataset_hash'], 'execution_hash' => $request['execution_contract']['execution_hash'],
            'index_set_hash' => hash('sha256', "replay-executed-clock-v1:indices\n".implode("\n", range(1, 89))."\n"),
            'schedule_hash' => str_repeat('a', 64), 'signal_start' => '2024-12-31T23:55:00Z', 'signal_end' => '2025-01-01T07:15:00Z',
            'execution_start' => '2025-01-01T00:00:00Z', 'execution_end' => '2025-01-01T07:20:00Z'];
        $clock = $this->seal($clock, 'receipt_hash', 'receipt_json');
        $nativeReceipt = ['protocol' => 'specialist_council_receipt_v1', 'contract_hash' => $native['contract_hash'], 'status' => 'computed',
            'dataset_hash' => $request['replay_dataset_hash'], 'execution_hash' => $request['execution_contract']['execution_hash'],
            'decision_trace_identity' => ['protocol' => 'native_council_decision_trace_v1', 'contract_hash' => $native['contract_hash'], 'trace_hash' => $this->hash($trace)],
            'replay_executed_clock' => $clock, 'position_ledger' => $positions,
            'account' => ['initial_balance' => 10000.25, 'final_balance' => 10000.25 + $accountNet + ($kind === 'broken_account' ? 1 : 0),
                'net_pnl' => $accountNet, 'fees' => count($positions) * .5, 'carry' => count($positions) * .25, 'spread_slippage' => count($positions) * .1,
                'reserved_capital' => 0.0, 'open_positions' => 0, 'reconciliation_error' => 0.0]];
        $nativeReceipt = $this->seal($nativeReceipt, 'receipt_hash', 'receipt_json');
        $response = ['decision_trace' => $trace, 'trade_ledger' => $ledger, 'total_trades' => count($positions),
            'final_balance' => 10000.25 + $accountNet, 'execution_contract' => $request['execution_contract'], 'specialist_council_receipt' => $nativeReceipt,
            'data_quality' => ['decision_trace' => ['scope_owner' => 'native_specialist_council_v1', 'trace_hash' => $this->hash($trace)]]];
        $owner = app(LabImmutableEvidenceService::class);
        $run = LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'phase' => 'screening', 'mode' => 'research', 'status' => 'completed',
            'started_at' => now()->subMinute(), 'finished_at' => now(), 'code_hash' => str_repeat('c', 64), 'data_hash' => $request['replay_dataset_hash'],
            'request_hash' => $owner->hash($request), 'response_hash' => $owner->hash($response)]);
        foreach (['evaluation_request' => $request, 'evaluation_response' => $response, 'decision_trace' => $trace, 'trade_ledger' => $ledger] as $type => $body)
            $owner->recordArtifact($run, $type, $body, $type === 'evaluation_request' ? ['request_hash' => $run->request_hash] : ['complete' => true]);
        return [$run, $request];
    }

    private function council(string $id, bool $meta = false): array
    {
        $models = [$this->model('hour'), $this->model('swing')]; $this->policy($models[0], $meta);
        $account = ['id' => 'execution', 'version' => '1', 'broker_position_mode' => 'hedging', 'opposite_position_policy' => 'hedge',
            'max_open_positions' => 8, 'max_reserved_capital_percent' => 100, 'max_gross_exposure_percent' => 100,
            'max_total_risk_percent' => 2, 'max_drawdown_percent' => 10, 'max_daily_loss_percent' => 3, 'max_expected_cost_percent' => 1];
        $members = [];
        foreach (['hour', 'swing'] as $index => $role) $members[] = ['specialist_id' => $role, 'role' => $role, 'version' => '1', 'as_of' => '2025-01-01T00:00:00Z',
            'inputs' => ['as_of_closed_candles'], 'scope' => ['symbols' => ['XAUUSD'], 'contexts' => ['trend']],
            'known_limits' => ['synthetic_unqualified'], 'resources' => ['max_compute_ms' => 100, 'max_memory_mb' => 32, 'max_lookback_bars' => 512],
            'horizon' => ['kind' => $role, 'decision_interval_seconds' => 300, 'reevaluation_interval_seconds' => 300, 'max_holding_seconds' => 3600, 'execution_precision' => 'candle'],
            'data_requirements' => $role === 'swing' ? ['gap', 'carry', 'rollover', 'mature_holding_outcomes'] : ['sessions', 'costs'],
            'model_version_id' => $models[$index]->id, 'strategy_version' => 'strategy-v1', 'tactic_version' => 'tactic-v1', 'management_version' => 'management-v1',
            'capital_weight' => .5, 'risk_per_trade_percent' => .5, 'sensor_timeframes' => ['M5']];
        $version = app(SpecialistCouncilLifecycleService::class)->registerDraft(['council_id' => $id, 'version' => '1', 'members' => $members,
            'components' => [], 'routing' => ['id' => 'router', 'version' => '1'], 'allocation' => ['id' => 'capital', 'version' => '1'],
            'risk' => ['id' => 'risk', 'version' => '1'], 'execution' => $account,
            'evaluation_policy' => ['objective' => 'net_return_at_equal_risk', 'champion_model_version_id' => $models[0]->id, 'solo_model_version_id' => $models[0]->id]], 'native-meta-fixture');
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $bundle = ['manifest' => ['streams' => ['M5' => ['first_candle_at' => '2025-01-01T00:00:00Z', 'last_candle_at' => '2025-12-31T23:55:00Z']]]];
        $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy' => $models[0]->strategy, 'base_strategy' => $models[0]->metadata['base_strategy'], 'parameters' => $models[0]->parameters,
            'evaluation_mode' => 'incremental', 'initial_balance' => 10000.25, 'risk_per_trade' => 1.0, 'hard_limits' => [], 'policy_context' => ['hard_limits' => []],
            'replay_dataset_hash' => str_repeat('d', 64), 'execution' => $execution['parameters'], 'execution_contract' => $execution,
            'emit_decision_trace' => true, 'emit_trade_ledger' => true, 'include_cost_sensitivity' => false, 'include_mutation_probe' => false,
            'research_release' => ['python_source_hash' => str_repeat('f', 64)]];
        for ($i = 0; $i < 260; $i++) {
            $price = $this->fixtureFamily === 'differential_router' ? 2000 + $i * .5 + sin($i / 3) * .2 : 2000 + sin($i / 5) * 5 + $i * .02;
            $request['candles'][] = ['time' => CarbonImmutable::parse($this->fixtureFamily === 'differential_router' ? '2025-02-03T04:00:00Z' : '2025-02-03T08:00:00Z')->addMinutes($i * 5)->toIso8601String(),
                'open' => $price, 'high' => $price + 1.5, 'low' => $price - 1.5, 'close' => $price + .1, 'volume' => 100.0];
        }
        $request['specialist_council_contract'] = app(SpecialistCouncilLifecycleService::class)->runtimeContract($version, $request['replay_dataset_hash'], $execution['execution_hash'], 'M5', $bundle, 'XAUUSD');
        return [$version, $request, $bundle];
    }

    private function model(string $name): ModelVersion
    {
        $parameters = $this->fixtureFamily === 'differential_router' ? app(\App\Services\StrategyParameterSchemaService::class)->defaults('differential_router')
            : ['ema_fast' => 4, 'ema_slow' => 10, 'minimum_signal_confidence' => .5];
        return ModelVersion::create(['name' => $name.'-'.Str::uuid(), 'strategy' => $this->fixtureFamily, 'version' => 'model-v1', 'generation' => 1, 'status' => 'testing',
            'parameters' => $parameters, 'metadata' => ['base_strategy' => $this->fixtureFamily === 'differential_router' ? 'differential_router' : 'ema_rsi']]);
    }

    private function policy(ModelVersion $model, bool $meta): void
    {
        $library = app(InstrumentMethodLibraryService::class); $key = 'native-optional-meta';
        $source = $library->registerSource(['kind' => 'imported_text', 'reference' => 'Synthetic native scope conditional fixture',
            'content' => $key, 'metadata' => ['license' => 'owned test fixture', 'access' => 'user_supplied', 'exposure' => ['status' => 'synthetic_fixture']]]);
        $method = $library->registerMethod(['instrument_key' => 'confidence_firewall', 'method_key' => $key, 'revision' => 1,
            'context_predicate' => ['regime' => 'trend_up', 'session' => 'asia', 'volatility' => 'normal'], 'horizon' => 'hour', 'source_keys' => [$source['source_key']], 'known_limitations' => ['Conditional fixture only'],
            'implementation' => ['kind' => 'typed_ast', 'target' => 'confirmation', 'ast' => ['op' => 'OR', 'args' => [
                ['op' => 'NOT', 'args' => [['op' => 'BOOL', 'input_key' => 'available', 'available_at' => '2000-01-01T00:00:00Z']]],
                ['op' => 'GREATER_THAN', 'args' => [['op' => 'NUMBER', 'input_key' => 'score', 'available_at' => '2000-01-01T00:00:00Z'],
                    ['op' => 'CONST', 'type' => 'number', 'value' => .6]]]]],
                'input_bindings' => ['score' => 'method_meta_score', 'available' => 'method_meta_model_available'],
                'budget' => ['cpu_seconds' => 1.0, 'max_calls' => 100000, 'max_node_evaluations' => 4800000]]]);
        $this->assertSame('registered_research_only', $method['status'], json_encode($method));
        $recipe = $library->registerRecipe(['recipe_key' => $key, 'revision' => 1, 'method_hashes' => [$method['method_hash']], 'context_predicate' => ['regime' => 'trend_up', 'session' => 'asia', 'volatility' => 'normal']]);
        $policy = app(AgentMethodLearningService::class)->registerPolicy(['agent_key' => 'model:'.$model->id, 'revision' => 1,
            'recipe_hashes' => [$recipe['recipe_hash']], 'rules' => [['context_predicate' => ['regime' => 'trend_up', 'session' => 'asia', 'volatility' => 'normal'], 'preferred_recipes' => [$recipe['recipe_hash']]]], 'default_action' => 'BASELINE']);
        $this->assertNotSame('blocked', $policy['status'], json_encode($policy));
        app(InstrumentMethodResearchBindingService::class)->attachToUnusedModel($model->id, $policy['policy_hash']);
    }

    private function python(string $mode, array $request): array
    {
        unset($request['research_release']);
        $process = proc_open([env('PYTHON_BINARY', 'python'), 'tests/support/native_instrument_method_meta_fixture.py'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(base_path()).'/ai-service-python',
            ['PYTHONPATH' => dirname(base_path()).'/ai-service-python', 'SYSTEMROOT' => getenv('SYSTEMROOT'), 'PATH' => getenv('PATH')],
            ['bypass_shell' => true, 'create_no_window' => true]);
        $this->assertIsResource($process); fwrite($pipes[0], json_encode(['mode' => $mode, 'request' => $request, 'specialist_id' => 'hour'], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)); fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false); $out = ''; $err = ''; $end = microtime(true) + 60;
        do { $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]); $state = proc_get_status($process);
            if (! $state['running']) break; if (microtime(true) > $end) { proc_terminate($process); $err .= ' TEST_60_SECOND_LIMIT'; break; } usleep(20000);
        } while (true);
        $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        $this->assertSame(0, $state['exitcode'] >= 0 ? $state['exitcode'] : $exit, $err);
        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }
    private function hash(array $value): string { return app(ResearchPaperEpochContractService::class)->parameterHash($value); }
    private function seal(array $body, string $hash, string $json): array
    {
        $sort = function ($value) use (&$sort) { if (! is_array($value)) return $value; if (! array_is_list($value)) ksort($value); return array_map($sort, $value); };
        $encoded = json_encode($sort($body), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        return [...$body, $hash => hash('sha256', $encoded), $json => $encoded];
    }
}
