<?php

namespace Tests\Feature;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AiLaboratory;
use App\Models\CausalFoldReceipt;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchExposureCaptureRecord;
use App\Services\ExecutionContractService;
use App\Services\CausalFoldExecutionService;
use App\Services\CompositionAuthorityKernelService;
use App\Services\LabAgentEvaluationService;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabInstrumentResearchService;
use App\Services\InstrumentResearchWindowService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\ResearchReleaseSealService;
use App\Services\ResearchWindowExposureInventoryService;
use App\Services\ScopedSelectorPanelService;
use App\Services\ScopedResearchCertificateService;
use App\Services\ScopedResearchAuthorityService;
use App\Services\StrategyParameterSchemaService;
use App\Services\TypedInstrumentFoundryService;
use App\Services\TacticCatalogueService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Synthetic original artifacts exercise protocol guards, never market edge. */
class ScopedSelectorPanelTest extends TestCase
{
    use RefreshDatabase;

    private string $originalStorage;
    private string $fixtureStorage;
    private ?string $canonicalFixtureRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = storage_path();
        $this->fixtureStorage = sys_get_temp_dir().'/scoped-selector-panel-'.bin2hex(random_bytes(6));
        app()->useStoragePath($this->fixtureStorage);
        config()->set('filesystems.disks.local.root', $this->fixtureStorage.'/app');
        config()->set('services.learning_lane.causal_minimum_trades_per_window', 3);
        $this->travelTo(CarbonImmutable::parse('2026-10-09T00:00:00Z'));
        $this->partialMock(ResearchReleaseSealService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('assertCurrent')->andReturn([]);
            $mock->shouldReceive('responseValid')->andReturnTrue();
        });
    }

    protected function tearDown(): void
    {
        if ($this->status()->isFailure() || $this->status()->isError()) {
            app()->useStoragePath($this->originalStorage);
            fwrite(STDERR, "Synthetic original diagnostic fixture retained: ".$this->fixtureStorage.
                ($this->canonicalFixtureRoot ? " ; CSV namespace: ".$this->canonicalFixtureRoot : '')."\n");
            parent::tearDown();
            return;
        }
        if ($this->canonicalFixtureRoot !== null && is_dir($this->canonicalFixtureRoot)) {
            $canonical = str_replace('\\', '/', realpath($this->canonicalFixtureRoot));
            $owner = str_replace('\\', '/', realpath(base_path('storage/app/lab-datasets'))).'/scoped-selector-software-';
            if (str_starts_with($canonical, $owner)) File::deleteDirectory($canonical);
        }
        app()->useStoragePath($this->originalStorage);
        $resolved = realpath($this->fixtureStorage);
        $prefix = str_replace('\\', '/', realpath(sys_get_temp_dir())).'/scoped-selector-panel-';
        if ($resolved && str_starts_with(str_replace('\\', '/', $resolved), $prefix)) File::deleteDirectory($resolved);
        parent::tearDown();
    }

    public function test_single_question_unequal_cap_and_relabelled_physical_question_are_refused(): void
    {
        $members = $this->members();
        $owner = app(ScopedSelectorPanelService::class);
        $this->assertSame('SELECTOR_PANEL_DISTINCT_MULTI_QUESTION_MEMBERSHIP_REQUIRED',
            $owner->preregister($members[0][0], [$members[0][0]->id], 'fixed')['reason_code']);
        $members[1][0]->update(['evidence' => $members[0][0]->evidence]);
        $this->assertSame('SELECTOR_PANEL_REPEATED_PHYSICAL_QUESTION_OR_SEED',
            $owner->preregister($members[0][0], $this->ids($members), 'fixed')['reason_code']);
        $members[1][0]->update(['evidence' => ['source_context_scope' => ['session' => 'fixture-1']]]);
        $benchmark = $this->benchmarkRow($members[1][0]);
        $contract = json_decode($benchmark->sealed_contract, true); $contract['per_arm_fold_seconds_limit'] = 11;
        DB::table('research_compounding_benchmarks')->where('id', $benchmark->id)->update(['sealed_contract' => json_encode($contract)]);
        $this->assertSame('SELECTOR_PANEL_EQUAL_BASELINE_LEGAL_SPACE_AND_CAPS_REQUIRED',
            $owner->preregister($members[0][0], $this->ids($members), 'fixed')['reason_code']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_membership_is_prospective_and_original_seal_cannot_be_rewritten(): void
    {
        $members = $this->members(); $owner = app(ScopedSelectorPanelService::class);
        $panel = $owner->preregister($members[0][0], $this->ids($members), 'fixed', ['minimum_trades' => 3]);
        $this->assertTrue($panel['valid']);
        $this->assertSame($panel, $owner->preregister($members[0][0], array_reverse($this->ids($members)), 'fixed', ['minimum_trades' => 3]));
        $this->assertSame('SELECTOR_PANEL_PHYSICAL_QUESTION_BUDGET_ALREADY_SEALED',
            $owner->preregister($members[0][0], $this->ids($members), 'unobserved-relabel', ['minimum_trades' => 3])['reason_code']);
        $this->assertSame('SELECTOR_PANEL_COMPLETE_ORIGINAL_FOLD_SET_REQUIRED', $owner->inspectPanel($panel['panel_key'])['reason_code']);
        $this->complete($members[0][0], $members[0][1], .3);
        $this->assertSame('SELECTOR_PANEL_MEMBERSHIP_MUST_PRECEDE_ORIGINAL_EXECUTION',
            $owner->preregister($members[0][0], $this->ids($members), 'different-seed', ['minimum_trades' => 3])['reason_code']);
        $row = DB::table('research_compounding_benchmarks')->where('benchmark_key', $panel['panel_key'])->first();
        $envelope = json_decode($row->sealed_contract, true); $envelope['body']['rules']['minimum_effect'] = .00000001;
        DB::table('research_compounding_benchmarks')->where('id', $row->id)->update(['sealed_contract' => json_encode($envelope)]);
        $this->assertSame('SELECTOR_PANEL_ORIGINAL_SEAL_INVALID', $owner->inspectPanel($panel['panel_key'])['reason_code']);
    }

    public function test_complete_original_folds_and_artifacts_measure_positive_panel_and_actual_effort(): void
    {
        $members = $this->members(); $owner = app(ScopedSelectorPanelService::class);
        $panel = $owner->preregister($members[0][0], $this->ids($members), 'fixed', ['minimum_trades' => 3]);
        foreach ($members as [$experiment, $request]) $this->complete($experiment, $request, .3);
        $result = $owner->inspectPanel($panel['panel_key']);
        $this->assertSame('original_selector_panel_observed', $result['status'], json_encode($result));
        $this->assertSame('positive', $result['verdict']);
        $this->assertCount(5, $result['original_products']);
        $this->assertEqualsWithDelta(.3, $result['paired_mean_utility_difference'], 1e-12);
        $this->assertEquals(11, $result['measured_primary_effort']['memory_enabled']);
        $this->assertEquals(11.5, $result['measured_primary_effort']['memory_blinded']);
        $this->assertNull($result['selector_cpu_seconds']);
        $this->assertTrue($result['checks']['actual_primary_compute_measured']);
        $this->assertFalse($result['scope_authority_confirmed']);
        $this->assertFalse($result['component_credit']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertSame($result, $owner->inspectPanel($panel['panel_key']));
    }

    public function test_missing_actual_effort_and_tampered_original_fold_cannot_certify(): void
    {
        $members = $this->members(); $owner = app(ScopedSelectorPanelService::class);
        $panel = $owner->preregister($members[0][0], $this->ids($members), 'fixed', ['minimum_trades' => 3]);
        $this->complete($members[0][0], $members[0][1], .3, missingEffort: true);
        $this->assertSame('SELECTOR_PANEL_PRIMARY_COMPUTE_NOT_MEASURED', $owner->inspectPanel($panel['panel_key'])['reason_code']);
        $fold = CausalFoldReceipt::where('agent_learning_causal_experiment_id', $members[0][0]->id)->first();
        DB::table('causal_fold_receipts')->where('id', $fold->id)->update(['response_hash' => str_repeat('f', 64)]);
        $this->assertSame('SELECTOR_PANEL_ORIGINAL_FOLD_IDENTITY_INVALID', $owner->inspectPanel($panel['panel_key'])['reason_code']);
    }

    public function test_complete_negative_and_equal_useful_components_close_as_valid_terminal_panels(): void
    {
        foreach (['negative' => -.3, 'inconclusive' => 0.0] as $expected => $delta) {
            $members = $this->members($expected); $owner = app(ScopedSelectorPanelService::class);
            $panel = $owner->preregister($members[0][0], $this->ids($members), $expected, ['minimum_trades' => 3]);
            foreach ($members as [$experiment, $request]) $this->complete($experiment, $request, $delta);
            $result = $owner->inspectPanel($panel['panel_key']);
            $this->assertTrue($result['valid'], json_encode($result));
            $this->assertTrue($result['terminal']);
            $this->assertSame($expected, $result['verdict']);
            // Blind can be a useful component while the selector has no advantage.
            $this->assertGreaterThan(0, $result['questions'][0]['blinded_control_utility']);
            $this->assertFalse($result['component_credit']);
        }
    }

    public function test_cpu_primary_never_substitutes_wall_measurement(): void
    {
        $members = $this->members(); $owner = app(ScopedSelectorPanelService::class);
        $panel = $owner->preregister($members[0][0], $this->ids($members), 'cpu-fixed',
            ['minimum_trades' => 3, 'resource_metric' => ScopedSelectorPanelService::CPU_METRIC]);
        foreach ($members as [$experiment, $request]) $this->complete($experiment, $request, .3);
        $this->assertSame('SELECTOR_PANEL_PRIMARY_COMPUTE_NOT_MEASURED', $owner->inspectPanel($panel['panel_key'])['reason_code']);
    }

    public function test_complete_unsafe_native_panel_is_terminal_negative_and_missing_safety_stays_blocked(): void
    {
        $members = $this->members('unsafe'); $owner = app(ScopedSelectorPanelService::class);
        $panel = $owner->preregister($members[0][0], $this->ids($members), 'unsafe', ['minimum_trades' => 3]);
        foreach ($members as [$experiment, $request]) $this->complete($experiment, $request, .3, candidateDrawdown: 9);
        $result = $owner->inspectPanel($panel['panel_key']);
        $this->assertTrue($result['valid'], json_encode($result)); $this->assertTrue($result['terminal']);
        $this->assertSame('negative', $result['verdict']); $this->assertFalse($result['checks']['all_questions_safe']);
        $first = CausalFoldReceipt::where('agent_learning_causal_experiment_id', $members[0][0]->id)->first();
        $payload = $first->response_payload; unset($payload['leaderboard'][0]['result']['monte_carlo']);
        DB::table('causal_fold_receipts')->where('id', $first->id)->update(['response_payload' => json_encode($payload, JSON_PRESERVE_ZERO_FRACTION),
            'response_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($payload)]);
        $missing = $owner->inspectPanel($panel['panel_key']);
        $this->assertFalse($missing['valid']); $this->assertFalse($missing['terminal']);
    }

    public function test_native_scope_partial_clock_and_wrong_measured_segment_count_are_refused(): void
    {
        $members = $this->members(); $owner = app(ScopedSelectorPanelService::class);
        $panel = $owner->preregister($members[0][0], $this->ids($members), 'native', ['minimum_trades' => 3]);
        $this->complete($members[0][0], $members[0][1], .3);
        $fold = CausalFoldReceipt::where('agent_learning_causal_experiment_id', $members[0][0]->id)->first(); $original = $fold->response_payload;
        foreach (['scope', 'clock', 'segments'] as $case) {
            $payload = $original;
            if ($case === 'scope') $payload['leaderboard'][0]['result']['learning_confirmation']['fold_offset'] = 99;
            elseif ($case === 'clock') $payload['leaderboard'][0]['result']['data_quality']['replay_executed_clock']['last_evaluation_index'] = 204;
            else $payload['leaderboard'][0]['result']['benchmark']['arm_replay_resources']['measured_segments'] = 2;
            DB::table('causal_fold_receipts')->where('id', $fold->id)->update(['response_payload' => json_encode($payload, JSON_PRESERVE_ZERO_FRACTION),
                'response_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($payload)]);
            $result = $owner->inspectPanel($panel['panel_key']); $this->assertFalse($result['valid']);
            $this->assertSame(match ($case) { 'scope' => 'SELECTOR_PANEL_ORIGINAL_NATIVE_FOLD_SCOPE_INVALID',
                'clock' => 'SELECTOR_PANEL_ORIGINAL_EXECUTED_CLOCK_INCOMPLETE', default => 'SELECTOR_PANEL_PRIMARY_COMPUTE_NOT_MEASURED' }, $result['reason_code']);
        }
    }

    public function test_sealed_selector_certificate_binds_panel_and_terminal_result_is_idempotent(): void
    {
        $members = $this->members(); $owner = app(ScopedSelectorPanelService::class);
        $panel = app(TypedInstrumentFoundryService::class)->registerSelectorPanel($members[0][0], $this->ids($members), 'fixed', ['minimum_trades' => 3]);
        $design = ['validation_start' => '2027-01-01T00:00:00Z', 'validation_end' => '2027-02-01T00:00:00Z',
            'evaluator_hash' => hash('sha256', 'synthetic-source'), 'context_hash' => hash('sha256', 'synthetic-context'),
            'execution_hash' => hash('sha256', 'synthetic-execution'), 'owner_source_hash' => hash('sha256', 'synthetic-owner'),
            'data_manifest_hash' => null, 'metric' => $panel['metric'], 'stopping_rule' => $panel['stopping_rule'], 'subject' => $panel['subject']];
        $certificate = app(ScopedResearchCertificateService::class)->register('selector', $members[0][0], $design);
        $this->assertTrue($certificate['valid'], json_encode($certificate));
        foreach ($members as [$experiment, $request]) $this->complete($experiment, $request, .3);
        $assessment = $owner->assessOriginalPanel($certificate['certificate_id']);
        $this->assertSame('positive', $assessment['verdict'], json_encode($assessment));
        $settled = app(TypedInstrumentFoundryService::class)->settleSelectorPanel($panel['panel_key'], $certificate['certificate_id']);
        $this->assertSame($assessment, $settled);
        $this->assertSame('SELECTOR_PANEL_SETTLEMENT_OWNER_MISMATCH',
            $owner->settlePanel(hash('sha256', 'other-panel'), $certificate['certificate_id'])['reason_code']);
        $this->assertSame($settled, $owner->settlePanel($panel['panel_key'], $certificate['certificate_id']));
        $this->assertDatabaseHas('research_compounding_benchmarks', ['benchmark_key' => $panel['panel_key'], 'status' => 'selector_panel_positive']);
        // A closed diagnostic source is never upgraded by a completion/redelivery callback.
        $members[0][0]->update(['status' => 'outcomes_pending']);
        $before = $members[0][0]->fresh()->getAttributes();
        $callback = $owner->reconcileForExperiment($members[0][0]->fresh());
        $this->assertSame('SELECTOR_PANEL_SEALED_SCOPE_BINDING_REQUIRED', $callback['panels'][0]['reason_code']);
        $this->assertSame($before, $members[0][0]->fresh()->getAttributes());
        $this->assertDatabaseMissing('scoped_research_certificates', ['record_type' => 'independent_assessment']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public static function originalIngressVariants(): array
    {
        return ['wire shape only' => [false], 'captured selector window without aggregate replay manifest' => [true]];
    }

    #[DataProvider('originalIngressVariants')]
    public function test_original_fold_ingress_round_trip_preserves_wire_maps_lists_floats_and_rejects_tamper(bool $selectorWindow): void
    {
        // Codec/ingress proof only: no evaluator response, statistical claims, or authority are manufactured.
        app()->forgetInstance(ResearchReleaseSealService::class);
        $experiment = $this->members('codec-ingress', $selectorWindow)[0][0];
        $epochs = app(ResearchPaperEpochContractService::class);
        $period = ['start_inclusive' => '2027-01-01T00:00:00+00:00', 'end_exclusive' => '2027-02-01T00:00:00+00:00'];
        $hash = app(LabImmutableEvidenceService::class)->codeHash();
        $registered = app(ScopedResearchCertificateService::class)->register($selectorWindow ? 'selector' : 'component', $experiment, [
            'authority_policy' => ScopedResearchCertificateService::AUTHORITY_POLICY,
            'validation_start' => $period['start_inclusive'], 'validation_end' => $period['end_exclusive'], 'validation_windows' => [$period],
            'evaluator_hash' => $hash, 'owner_source_hash' => $hash, 'context_hash' => hash('sha256', 'codec-context'),
            'execution_hash' => hash('sha256', 'codec-execution'), 'data_manifest_hash' => null,
            'metric' => 'profit_factor', 'stopping_rule' => 'complete_sealed_window',
            'subject' => [...($selectorWindow ? ['selector_panel_key' => hash('sha256', 'ownership-only-selector-window'),
                'experiment_ids' => [(int) $experiment->id]] : []), 'arm_models' => LabAgent::whereIn('id', [$experiment->guided_agent_id,
                $experiment->blinded_agent_id, $experiment->control_agent_id])->with('modelVersion')->get()->map(fn ($agent) => [
                    'model_version_id' => (int) $agent->model_version_id, 'parameter_hash' => $epochs->parameterHash($agent->modelVersion->parameters),
                ])->all()],
            'exposure_policy' => ['protocol' => 'prospective_scoped_exposure_policy_v1', 'holding_fence_seconds' => 600,
                'execution_timeframe' => 'M5', 'context_timeframes' => ['H4', 'H1', 'M15'],
                'warmup_policy' => 'all_original_closed_source_rows_inside_registered_window', 'selection_policy' => 'frozen_before_first_event'],
        ]);
        $this->assertTrue($registered['valid'], json_encode($registered));
        $this->travelTo(CarbonImmutable::parse('2028-01-01T00:00:00Z'));
        $streams = []; $start = CarbonImmutable::parse('2027-01-10T00:00:00Z');
        foreach (['M5' => 300, 'H4' => 14400, 'H1' => 3600, 'M15' => 900] as $frame => $seconds) {
            $path = storage_path('app/lab-datasets/codec-'.$frame.'.csv'); File::ensureDirectoryExists(dirname($path));
            $end = $start->addSeconds($seconds)->toIso8601ZuluString();
            File::put($path, "time,open,high,low,close,volume\n".$start->toIso8601ZuluString().",100,101,99,100,10\n".$end.",100,101,99,100,10\n");
            $streams[$frame] = ['path' => $path, 'sha256' => hash_file('sha256', $path), 'rows' => 2,
                'first_candle_at' => $start->toIso8601ZuluString(), 'last_candle_at' => $end];
        }
        $manifest = ['protocol' => MultiTimeframeSnapshotService::PROTOCOL, 'streams' => $streams, 'bundle_hash' => $epochs->parameterHash($streams)];
        config()->set('services.instrument_policy.authorized_research_windows', [[
            'authorization_id' => 'codec-original', 'research_epoch_id' => 'codec-2027', 'purpose' => 'instrument_independent_validation',
            ...$period, 'dataset_sha256' => $manifest['bundle_hash'], 'mtf_bundle_manifest' => $manifest]]);
        $window = app(InstrumentResearchWindowService::class)->seal('codec-original', $manifest['bundle_hash']);
        if ($selectorWindow) $manifest = app(InstrumentResearchWindowService::class)->canonicalScopedManifest($window, $manifest);
        $ids = [$experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id];
        $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluation_mode' => 'full', 'dataset_path' => $streams['M5']['path'],
            'replay_dataset_hash' => $manifest['bundle_hash'], 'mtf_snapshot_manifest' => $manifest,
            'mtf_dataset_paths' => array_map(fn ($stream) => $stream['path'], $streams), 'candles' => [],
            'strategies' => array_map(fn ($id) => ['lab_agent_id' => (int) $id, 'parameters' => ['fraction' => 1.0],
                'composition_runtime_contract' => new \stdClass, 'empty_list' => []], $ids),
            'policy_context' => ['causal_fold_job' => ['experiment_id' => (int) $experiment->id, 'fold_index' => 1],
                'learning_confirmation_contracts' => array_fill_keys($ids, ['maximum_holding_bars' => 1])]];
        if ($selectorWindow) {
            $registration = app(ScopedResearchCertificateService::class)->verifiedRegistration($registered['certificate_id']);
            // Ownership-only fixture: this receipt exercises the captured original request boundary,
            // not worker admission, measured trading statistics, or a scientific authority assertion.
            $request['policy_context']['scoped_research_certificate'] = [
                'protocol' => ScopedResearchCertificateService::AUTHORITY_POLICY, 'purpose' => 'independent_scoped_selector_research',
                'certificate_id' => $registered['certificate_id'], 'design_hash' => $registration['design_hash'],
                'selector_panel_key' => $registration['design']['subject']['selector_panel_key'],
                'window_key' => $window['window_key'], 'promotion_evidence' => false];
            $request['policy_context']['authorized_research_transport'] = [
                'protocol' => InstrumentResearchWindowService::TRANSPORT_PROTOCOL, 'dataset_hash' => $manifest['bundle_hash'],
                'window' => $window, 'scoped_original_window' => ['protocol' => 'scoped_original_window_v1',
                    'purpose' => 'independent_scoped_selector_research', 'certificate_id' => $registered['certificate_id'],
                    'design_hash' => $registration['design_hash']]];
        }
        $fold = CausalFoldReceipt::create(['receipt_key' => hash('sha256', 'codec-original-fold'),
            'agent_learning_causal_experiment_id' => $experiment->id, 'lab_generation_id' => $experiment->lab_generation_id,
            'fold_index' => 1, 'fold_count' => 1, 'status' => 'running', 'attempt_count' => 1, 'lease_token' => 'codec-original-lease',
            'started_at' => now(), 'observed_at' => now()]);
        $owner = app(ResearchWindowExposureInventoryService::class); $requestHash = $epochs->parameterHash($request);
        $prefix = $owner->recordCausalFoldRequest($fold, $request, $requestHash);
        $this->assertCount(1, $prefix);
        $record = ResearchExposureCaptureRecord::findOrFail($prefix[0]);
        $this->assertSame($record->payload_hash, $epochs->parameterHash($record->payload));
        $this->assertInstanceOf(\stdClass::class, $record->payload['request']['strategies'][0]['composition_runtime_contract']);
        $this->assertSame([], $record->payload['request']['strategies'][0]['empty_list']);
        $this->assertSame(1.0, $record->payload['request']['strategies'][0]['parameters']['fraction']);
        $owner->assertCausalFoldRequestCaptured($fold->fresh(), $request, $requestHash, $prefix);
        $response = $selectorWindow ? ['leaderboard' => array_map(fn ($id) => ['lab_agent_id' => (int) $id,
            'result' => ['data_quality' => ['replay_executed_clock' => ['protocol' => 'replay_executed_clock_v1',
                'complete' => true, 'dataset_hash' => $manifest['bundle_hash'], 'execution_hash' => hash('sha256', 'codec-execution'),
                'decision_rows' => 1, 'duration_seconds' => 300, 'signal_start' => '2027-01-10T00:00:00+00:00',
                'signal_end' => '2027-01-10T00:00:00+00:00', 'execution_start' => '2027-01-10T00:05:00+00:00',
                'execution_end' => '2027-01-10T00:05:00+00:00']]]], $ids)] : [];
        $fold->update(['status' => 'completed', 'completed_at' => now(), 'request_payload' => $request,
            'request_hash' => $requestHash, 'response_payload' => $response, 'response_hash' => $epochs->parameterHash($response),
            'dataset_hash' => $manifest['bundle_hash'], 'execution_hash' => hash('sha256', 'codec-execution')]);
        $fold->refresh();
        $this->assertSame($requestHash, $epochs->parameterHash($fold->request_payload));
        $this->assertInstanceOf(\stdClass::class, $fold->request_payload['strategies'][0]['composition_runtime_contract']);
        $verified = $owner->verifiedOriginalFoldRequest($fold, $registered['certificate_id']);
        $this->assertSame($requestHash, $epochs->parameterHash($verified));
        $this->assertFalse(app(LabImmutableEvidenceService::class)->decisionTraceCompletenessForOriginalFold($fold, $ids[0])['complete']);
        if ($selectorWindow) {
            $product = ['experiment_id' => (int) $experiment->id, 'fold_receipt_ids' => [(int) $fold->id]];
            $publishedRequest = $request;
            $publishedRequest['policy_context']['causal_fold_aggregate'] = ['experiment_id' => (int) $experiment->id];
            $runIds = [];
            foreach ($ids as $id) {
                $agent = LabAgent::with('modelVersion', 'generation')->findOrFail($id);
                $run = app(LabImmutableEvidenceService::class)->beginRun($agent, 'full_validation', 'ownership_protocol_only');
                app(LabImmutableEvidenceService::class)->recordArtifact($run, 'evaluation_request', $publishedRequest);
                $run->update(['data_hash' => $manifest['bundle_hash']]);
                $runIds[] = (int) $run->id;
            }
            $windowOwner = new \ReflectionMethod(ScopedResearchAuthorityService::class, 'selectorWindowFromOriginalFolds');
            $proof = fn () => $windowOwner->invoke(app(ScopedResearchAuthorityService::class),
                $registration, $product, $runIds, $publishedRequest);
            $this->assertArrayNotHasKey('replay_manifest', $response['leaderboard'][0]['result']);
            $this->assertSame($window, $proof());
            LabEvaluationRun::findOrFail($runIds[1])->update(['data_hash' => hash('sha256', 'different-original-source')]);
            try { $proof(); $this->fail('Different published original source was accepted.'); }
            catch (\LogicException $error) { $this->assertSame('ORIGINAL_SELECTOR_PUBLISHED_SOURCE_MISMATCH', $error->getMessage()); }
            LabEvaluationRun::findOrFail($runIds[1])->update(['data_hash' => $manifest['bundle_hash']]);
            $outside = $response; $outside['leaderboard'][0]['result']['data_quality']['replay_executed_clock']['execution_end'] = '2027-02-01T00:00:00+00:00';
            // Deliberate database corruption bypasses the model's already enforced completed-receipt immutability.
            DB::table('causal_fold_receipts')->where('id', $fold->id)->update([
                'response_payload' => json_encode($outside, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'response_hash' => $epochs->parameterHash($outside)]);
            try { $proof(); $this->fail('Original economic clock outside its physical window was accepted.'); }
            catch (\LogicException $error) { $this->assertSame('ORIGINAL_SELECTOR_CAPTURED_CLOCK_OUTSIDE_WINDOW', $error->getMessage()); }
            DB::table('causal_fold_receipts')->where('id', $fold->id)->update([
                'response_payload' => json_encode($response, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'response_hash' => $epochs->parameterHash($response)]);
            $this->assertSame($window, $proof());
            $this->assertDatabaseMissing('scoped_research_certificates', ['record_type' => 'independent_assessment']);
        }
        $tampered = $record->payload; $tampered['request']['strategies'][0]['composition_runtime_contract'] = [];
        DB::table('research_exposure_capture_records')->where('id', $record->id)->update([
            'payload' => json_encode($tampered, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);
        try { $owner->verifiedOriginalFoldRequest($fold, $registered['certificate_id']); $this->fail('Changed native MAP shape was accepted.'); }
        catch (\LogicException $error) { $this->assertSame('EXPOSURE_CAPTURE_SEAL_OR_PAYLOAD_DRIFT', $error->getMessage()); }
        if ($selectorWindow) {
            try { $proof(); $this->fail('Changed captured original request was accepted by the selector window owner.'); }
            catch (\LogicException $error) { $this->assertSame('EXPOSURE_CAPTURE_SEAL_OR_PAYLOAD_DRIFT', $error->getMessage()); }
        }
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_original_scoped_wire_preserves_json_hashes_in_both_http_hops_without_changing_legacy_codec(): void
    {
        // Wire-only regression. The private serializer grants no runtime/scientific authority.
        $payload = ['strategies' => [['parameters' => ['whole_float' => 1.0], 'empty_map' => new \stdClass, 'empty_list' => []]],
            'fold_receipts' => [['result' => ['whole_zero' => 0.0]]],
            'policy_context' => ['scoped_research_certificate' => ['protocol' => ScopedResearchCertificateService::AUTHORITY_POLICY,
                'purpose' => 'independent_scoped_selector_research']]];
        $wire = new \ReflectionMethod(CausalFoldExecutionService::class, 'sendOriginalWire');
        $predicate = new \ReflectionMethod(CausalFoldExecutionService::class, 'isScopedSelectorWire');
        $owner = app(CausalFoldExecutionService::class);
        $preserve = $predicate->invoke($owner, $payload);
        $this->assertTrue($preserve);
        $wrongProtocol = $payload; $wrongProtocol['policy_context']['scoped_research_certificate']['protocol'] = 'independent_original_scope_authority_v1';
        $this->assertFalse($predicate->invoke($owner, $wrongProtocol));
        $wrongPurpose = $payload; $wrongPurpose['policy_context']['scoped_research_certificate']['purpose'] = 'independent_scoped_component_research';
        $this->assertFalse($predicate->invoke($owner, $wrongPurpose));
        foreach (['run-all', 'aggregate-causal-folds'] as $endpoint) {
            Http::fake(['http://synthetic-original-wire.test/api/backtest/'.$endpoint => function ($request) use ($payload) {
                $expected = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
                $this->assertSame($expected, $request->body());
                $this->assertSame(hash('sha256', $expected), hash('sha256', $request->body()));
                $decoded = json_decode($request->body(), false, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(1.0, $decoded->strategies[0]->parameters->whole_float);
                $this->assertSame(0.0, $decoded->fold_receipts[0]->result->whole_zero);
                $this->assertInstanceOf(\stdClass::class, $decoded->strategies[0]->empty_map);
                $this->assertSame([], $decoded->strategies[0]->empty_list);
                return Http::response('{"ok":true}');
            }]);
            $response = $wire->invoke(app(CausalFoldExecutionService::class), Http::acceptJson(),
                'http://synthetic-original-wire.test/api/backtest/'.$endpoint, $payload, $preserve);
            $this->assertTrue($response->successful());
        }
        Http::fake(['http://synthetic-legacy-wire.test/api/backtest/run-all' => function ($request) use ($payload) {
            $this->assertSame(json_encode($payload, JSON_THROW_ON_ERROR), $request->body());
            $decoded = json_decode($request->body(), false, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(1, $decoded->strategies[0]->parameters->whole_float);
            return Http::response('{"ok":true}');
        }]);
        $this->assertTrue($wire->invoke(app(CausalFoldExecutionService::class), Http::acceptJson(),
            'http://synthetic-legacy-wire.test/api/backtest/run-all', $payload, false)->successful());
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public static function futureRuntimeVariants(): array
    {
        return ['bare original runtime' => [false], 'compound original runtime' => [true],
            'undersized original nine-fold universe' => [false, true]];
    }

    #[DataProvider('futureRuntimeVariants')]
    public function test_future_physical_roster_binds_real_provider_bytes_before_native_fold_dispatch(bool $compound, bool $undersized = false): void
    {
        // No worker/readiness/issuer fake: only synthetic physical CSV data, not a market-edge assertion.
        app()->forgetInstance(ResearchReleaseSealService::class);
        config()->set('services.internal_api.token', str_repeat('synthetic-selector-internal-key-', 2));
        config()->set('services.learning_lane.causal_fold_count', $undersized ? 9 : 1);
        config()->set('services.learning_lane.causal_per_fold_budget_seconds', 45);
        config()->set('services.learning_lane.causal_max_rows_per_fold', 512);
        config()->set('services.learning_lane.confirmation_maximum_holding_bars', $undersized ? 240 : 2);
        $period = ['start_inclusive' => '2027-01-01T00:00:00+00:00', 'end_exclusive' => '2027-02-01T00:00:00+00:00'];
        $record = ['authorization_id' => 'synthetic-future-selector', 'research_epoch_id' => 'synthetic-2027-selector',
            'purpose' => 'instrument_independent_validation', ...$period];
        config()->set('services.instrument_policy.authorized_research_windows', [$record]);
        $members = $this->members('future-provider', true, $compound); $owner = app(ScopedSelectorPanelService::class);
        $modelIds = collect($members)->flatMap(fn ($item) => [$item[0]->guided_agent_id, $item[0]->blinded_agent_id, $item[0]->control_agent_id]);
        $originalModels = LabAgent::whereIn('id', $modelIds)->with('modelVersion')->get()
            ->mapWithKeys(fn ($agent) => [$agent->model_version_id => $agent->modelVersion->getRawOriginal()])->all();
        $plans = array_fill_keys($this->ids($members), $record['authorization_id']);
        $panel = app(TypedInstrumentFoundryService::class)->registerFutureSelectorPanel($members[0][0], $this->ids($members), $plans,
            'fixed-before-provider-bytes', ['minimum_trades' => 3]);
        $this->assertSame('selector_panel_preregistered', $panel['status'], json_encode($panel));
        $reservedAgent = LabAgent::findOrFail($members[0][0]->guided_agent_id);
        $this->assertTrue($owner->reservesOrdinaryEvaluation($reservedAgent));
        try {
            app(LabAgentEvaluationService::class)->evaluate($reservedAgent);
            $this->fail('Reserved future original must not begin ordinary historical evaluation.');
        } catch (\RuntimeException $error) {
            $this->assertSame('SCOPED_SELECTOR_ORIGINAL_EXECUTOR_REQUIRED', $error->getMessage());
        }
        $row = DB::table('research_compounding_benchmarks')->where('benchmark_key', $panel['panel_key'])->first();
        $sealed = json_decode($row->sealed_contract, true)['body'];
        foreach ($sealed['members'] as $member) {
            $this->assertNull($member['dataset_hash']);
            foreach ($member['arms'] as $arm) $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $arm['prospective_recipe_hash']);
        }
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $hash = app(LabImmutableEvidenceService::class)->codeHash();
        $registered = app(ScopedResearchCertificateService::class)->register('selector', $members[0][0], [
            'authority_policy' => ScopedResearchCertificateService::AUTHORITY_POLICY,
            'validation_start' => $period['start_inclusive'], 'validation_end' => $period['end_exclusive'], 'validation_windows' => [$period],
            'evaluator_hash' => $hash, 'owner_source_hash' => $hash, 'context_hash' => hash('sha256', 'synthetic-selector-context'),
            'execution_hash' => $execution['execution_hash'], 'data_manifest_hash' => null,
            'metric' => $panel['metric'], 'stopping_rule' => $panel['stopping_rule'], 'subject' => $panel['subject'],
            'exposure_policy' => ['protocol' => 'prospective_scoped_exposure_policy_v1', 'holding_fence_seconds' => $undersized ? 72000 : 600,
                'execution_timeframe' => 'M5', 'context_timeframes' => ['H4', 'H1', 'M15'],
                'warmup_policy' => 'all_original_closed_source_rows_inside_registered_window', 'selection_policy' => 'frozen_before_first_event'],
        ]);
        $this->assertTrue($registered['valid'], json_encode($registered));
        $registration = app(ScopedResearchCertificateService::class)->verifiedRegistration($registered['certificate_id']);
        $wait = app(CausalFoldExecutionService::class)->run($members[0][0]->id, 1);
        $this->assertSame('awaiting_original_selector_data', $wait['status']);
        $this->assertDatabaseCount('causal_fold_receipts', 0);
        $this->travelTo(CarbonImmutable::parse('2028-01-01T00:00:00Z'));
        // Both real runtimes use the canonical project data-root guard. Only this unique CSV namespace is written there.
        app()->useStoragePath(base_path('storage'));
        $streams = []; $start = CarbonImmutable::parse('2027-01-10T00:00:00Z');
        $this->canonicalFixtureRoot = base_path('storage/app/lab-datasets/scoped-selector-software-'.bin2hex(random_bytes(6)));
        foreach (['M5' => 300, 'H4' => 14400, 'H1' => 3600, 'M15' => 900] as $timeframe => $seconds) {
            $count = $timeframe === 'M5' ? ($undersized ? 360 : 208) : 2; $rows = [];
            foreach (range(0, $count - 1) as $index) $rows[] = $start->addSeconds($index * $seconds)->toIso8601ZuluString().',100,101,99,100,10';
            $path = $this->canonicalFixtureRoot.'/'.$timeframe.'.csv'; File::ensureDirectoryExists(dirname($path));
            File::put($path, "time,open,high,low,close,volume\n".implode("\n", $rows)."\n");
            $streams[$timeframe] = ['path' => $path, 'sha256' => hash_file('sha256', $path), 'rows' => $count,
                'first_candle_at' => $start->toIso8601ZuluString(), 'last_candle_at' => $start->addSeconds(($count - 1) * $seconds)->toIso8601ZuluString()];
        }
        $manifest = ['protocol' => MultiTimeframeSnapshotService::PROTOCOL, 'streams' => $streams,
            'bundle_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($streams)];
        config()->set('services.instrument_policy.authorized_research_windows', [[...$record,
            'dataset_sha256' => $manifest['bundle_hash'], 'mtf_bundle_manifest' => $manifest]]);
        $manifest = app(\App\Services\InstrumentResearchWindowService::class)->canonicalScopedManifest(
            app(\App\Services\InstrumentResearchWindowService::class)->seal($record['authorization_id'], $manifest['bundle_hash']), $manifest);
        if ($undersized) {
            $scope = $owner->prospectiveExecutionScope($members[0][0]->fresh());
            $this->assertSame('SELECTOR_PANEL_ORIGINAL_SOURCE_ROWS_INSUFFICIENT_FOR_SEALED_FOLDS', $scope['reason_code']);
            $this->assertSame(360, $scope['actual_source_rows']); $this->assertSame(3978, $scope['minimum_source_rows']);
            $this->assertSame(9, $scope['sealed_fold_universe_count']);
            $this->assertSame('awaiting_original_selector_data', app(CausalFoldExecutionService::class)->run($members[0][0]->id, 1)['status']);
            $this->assertDatabaseCount('causal_fold_receipts', 0); $this->assertDatabaseCount('lab_evaluation_runs', 0);
            $this->assertDatabaseCount('lab_evolution_credit_events', 0);
            return;
        }
        $requests = [];
        foreach ($members as [$experiment]) {
            $envelope = app(LabAgentEvaluationService::class)->causalFoldEnvelope($experiment->fresh(), 1); $request = $envelope['request'];
            $this->assertSame('full', $request['evaluation_mode']);
            $this->assertSame($manifest['bundle_hash'], $request['replay_dataset_hash']);
            $this->assertSame($streams['M5']['path'], $request['dataset_path']);
            $this->assertSame($manifest, $request['mtf_snapshot_manifest']);
            $this->assertCount(4, $request['mtf_dataset_paths']);
            $this->assertArrayNotHasKey('foundation_dataset_path', $request);
            $this->assertArrayNotHasKey('data_boundary', $request['policy_context']);
            $this->assertArrayNotHasKey('paper', $envelope['manifest']);
            $this->assertSame('independent_scoped_selector_research', data_get($request, 'policy_context.scoped_research_certificate.purpose'));
            $this->assertSame('authorized_research_transport_v1', data_get($request, 'policy_context.authorized_research_transport.protocol'));
            $this->assertSame(600, data_get($request, 'policy_context.scoped_position_maturity_fence.holding_fence_seconds'));
            $this->assertSame([45, 45, 45], array_column($request['policy_context']['learning_confirmation_contracts'], 'per_fold_budget_seconds'));
            foreach ($request['strategies'] as $strategy) {
                if ($compound) {
                    $this->assertSame('xauusd_composition_runtime_contract_v3', data_get($strategy, 'composition_runtime_contract.protocol'));
                    $this->assertNotEmpty($strategy['instrument_research_assignment']);
                    $this->assertSame($manifest['bundle_hash'], data_get($strategy, 'composition_runtime_contract.execution_authority.dataset.replay_dataset_hash'));
                } else {
                    $this->assertEmpty((array) $strategy['composition_runtime_contract']);
                    $this->assertEmpty((array) $strategy['instrument_research_assignment']);
                }
            }
            $benchmark = app(TypedInstrumentFoundryService::class)->registerCausalBenchmark($experiment->fresh(), $request);
            $this->assertSame('planned', $benchmark['status'], json_encode($benchmark)); $requests[] = $request;
        }
        $this->assertFalse($owner->executionReadiness($members[0][0]->fresh())['ready'] ?? false);
        $bound = app(TypedInstrumentFoundryService::class)->bindSelectorPanelOriginalData($panel['panel_key']);
        $this->assertSame('selector_panel_data_bound', $bound['status'], json_encode($bound));
        $this->assertSame($bound, $owner->bindOriginalData($panel['panel_key']));
        $this->assertTrue($owner->executionReadiness($members[0][0]->fresh())['ready']);
        $this->assertTrue($owner->prospectiveExecutionScope($members[0][0]->fresh())['executable']);
        $this->assertSame(app(ResearchPaperEpochContractService::class)->parameterHash($requests[0]),
            app(ResearchPaperEpochContractService::class)->parameterHash(app(LabAgentEvaluationService::class)->causalFoldEnvelope($members[0][0]->fresh(), 1)['request']));
        $this->assertSame($registration, app(ScopedResearchCertificateService::class)->verifiedRegistration($registered['certificate_id']));
        $this->assertPythonOriginalAdmission($requests[0], $compound ? 3 : 0);
        foreach ($originalModels as $id => $raw) $this->assertSame($raw, ModelVersion::findOrFail($id)->getRawOriginal());
        $this->assertDatabaseCount('causal_fold_receipts', 0);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $completedFoldCount = $compound ? 1 : count($members);
        foreach (array_slice($members, 0, $completedFoldCount) as $index => [$experiment]) {
            $this->publishActualOriginalFold($experiment->fresh(), $registered['certificate_id'], $index + 1);
        }
        if (! $compound) {
            $observed = $owner->assessOriginalPanel($registered['certificate_id']);
            $this->assertTrue($observed['valid'], json_encode($observed));
            $this->assertTrue($observed['terminal']);
            $this->assertContains($observed['verdict'], ['negative', 'inconclusive']);
            $this->assertSame(0, $observed['powered_question_count']);
            $this->assertFalse($observed['checks']['all_questions_non_target_metrics_proven']);
            $this->assertCount(5, $observed['original_products']);
            foreach ($observed['original_products'] as $product) {
                foreach (['guided', 'blinded', 'control'] as $role) {
                    $this->assertNotEmpty($product[$role.'_run_id']);
                    $this->assertGreaterThan(0, $product[$role.'_run_record_id']);
                }
                $this->assertCount(1, $product['fold_receipt_ids']);
            }
            $this->assertSame('selector_panel_'.$observed['verdict'],
                DB::table('research_compounding_benchmarks')->where('benchmark_key', $panel['panel_key'])->value('status'));
            // The existing aggregate callback, not a new scheduler/caller truth flag, already invoked the issuer.
            if (DB::table('scoped_research_certificates')->count() !== 2) {
                $issuance = app(ScopedResearchAuthorityService::class)->assess($registration, ['selector_panel_key' => $panel['panel_key']]);
                fwrite(STDERR, 'Original selector terminal issuance diagnostic: '.json_encode([
                    'panel_verdict' => $observed['verdict'], 'published_original_run_count' => LabEvaluationRun::count(),
                    'certificate_row_count' => DB::table('scoped_research_certificates')->count(),
                    'issuance_status' => $issuance['status'] ?? null, 'issuance_reason_code' => $issuance['reason_code'] ?? null])."\n");
            }
            $this->assertDatabaseCount('scoped_research_certificates', 2);
            $issued = app(ScopedResearchCertificateService::class)->issueIndependent($registered['certificate_id'],
                ['selector_panel_key' => $panel['panel_key']]);
            $this->assertSame('independent_negative_or_inconclusive', $issued['status'], json_encode($issued));
            $this->assertFalse($issued['scope_authority_confirmed']);
            $this->assertSame($issued['authority_record_id'], app(ScopedResearchCertificateService::class)->issueIndependent(
                $registered['certificate_id'], ['selector_panel_key' => $panel['panel_key']])['authority_record_id']);
            $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        }
        $record['end_exclusive'] = '2027-03-01T00:00:00+00:00';
        config()->set('services.instrument_policy.authorized_research_windows', [[...$record, 'dataset_sha256' => $manifest['bundle_hash'], 'mtf_bundle_manifest' => $manifest]]);
        $this->assertSame('SELECTOR_PANEL_PLANNED_AUTHORIZATION_CHANGED', $owner->prospectiveExecutionScope($members[0][0]->fresh())['reason_code']);
        $corrupted = json_decode($row->sealed_contract, true); $corrupted['seal'] = str_repeat('0', 64);
        DB::table('research_compounding_benchmarks')->where('benchmark_key', $panel['panel_key'])->update([
            'status' => 'selector_panel_negative', 'sealed_contract' => json_encode($corrupted, JSON_THROW_ON_ERROR)]);
        $this->assertTrue($owner->reservesOrdinaryEvaluation($reservedAgent));
        $this->assertSame('SELECTOR_PANEL_ORIGINAL_SEAL_INVALID', $owner->executionReadiness($members[0][0]->fresh())['reason_code']);
        $this->assertSame('SELECTOR_PANEL_ORIGINAL_SEAL_INVALID', $owner->prospectiveExecutionScope($members[0][0]->fresh())['reason_code']);
        $this->assertSame('awaiting_original_selector_data', app(CausalFoldExecutionService::class)->run($members[0][0]->id, 1)['status']);
        $this->assertDatabaseCount('causal_fold_receipts', $completedFoldCount);
        $this->assertDatabaseCount('lab_evaluation_runs', $completedFoldCount * 3);
    }

    private function assertPythonOriginalAdmission(array $request, int $programmeCount): void
    {
        $proof = $this->pythonOriginalProof($request);
        $this->assertTrue($proof['schema']); $this->assertTrue($proof['transport']); $this->assertTrue($proof['maturity_fence']);
        $this->assertTrue($proof['closed_mtf_context']);
        $this->assertSame(3, $proof['original_arm_count']); $this->assertFalse($proof['promotion_evidence']);
        $this->assertSame($programmeCount, $proof['compiled_programme_count']);
    }

    private function pythonOriginalProof(array $request, bool $replay = false, ?string $wireJson = null): array
    {
        $export = $this->fixtureStorage.'/app/scoped-selector-original-'.app(ResearchPaperEpochContractService::class)->parameterHash($request).'.json';
        File::ensureDirectoryExists(dirname($export));
        File::put($export, $wireJson ?? json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $script = base_path('tests/Fixtures/scoped_selector_python_admission.py');
        $command = [getenv('SCOPED_PYTHON_EXECUTABLE') ?: 'python', $script, $export, now()->utc()->toIso8601String()];
        if ($replay) $command[] = '--native-replay';
        $process = new Process($command,
            dirname(base_path()).'/ai-service-python', ['PYTHONPATH' => dirname(base_path()).'/ai-service-python',
                'PYTHONDONTWRITEBYTECODE' => '1', 'SCOPED_SELECTOR_FIXTURE_INTERNAL_KEY' => config('services.internal_api.token'),
                'INTERNAL_API_TOKEN' => config('services.internal_api.token'), 'INTERNAL_API_TOKEN_FILE' => '',
                'AI_REPLAY_IMMUTABLE_CACHE_DIR' => $this->fixtureStorage.'/native-replay-cache']);
        $process->setTimeout(180); $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $lines = preg_split('/\r?\n/', trim($process->getOutput()));
        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }

    private function publishActualOriginalFold(AgentLearningCausalExperiment $experiment, int $certificateId, int $completedFoldCount = 1): void
    {
        $products = null;
        // Operational in-process transport only; the real Python evaluator produces every returned field.
        Http::fake([
            '*/api/backtest/run-all' => function ($request) use (&$products, $certificateId) {
                $capture = ResearchExposureCaptureRecord::where('certificate_id', $certificateId)
                    ->where('record_type', 'causal_fold_ingress')->latest('id')->firstOrFail();
                // Http fake's data() may retain pre-serialization options: inspect the real HTTP body instead.
                $sent = (new ResearchExposureCaptureRecord)->fromJson($request->body());
                $this->assertSame($capture->payload['request_hash'], app(ResearchPaperEpochContractService::class)->parameterHash($sent));
                $products = $this->pythonOriginalProof($sent, true, $request->body());
                $this->assertTrue($products['native_durable_fold']); $this->assertSame(3, $products['actual_resource_arm_count']);
                return Http::response(File::get($products['original_response_path']));
            },
            '*/api/backtest/aggregate-causal-folds' => function ($request) use (&$products) {
                $this->assertNotNull($products);
                $raw = json_decode(File::get($products['original_response_path']), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(app(ResearchPaperEpochContractService::class)->parameterHash($raw),
                    app(ResearchPaperEpochContractService::class)->parameterHash(json_decode($request->body(), true,
                        flags: JSON_THROW_ON_ERROR)['fold_receipts'][0]));
                return Http::response(File::get($products['original_aggregate_path']));
            },
        ]);
        $result = app(CausalFoldExecutionService::class)->run($experiment->id, 1);
        $this->assertSame('completed', $result['status'], json_encode($result));
        $this->assertSame('completed', $result['settlement']['status'], json_encode($result));
        $fold = CausalFoldReceipt::findOrFail($result['receipt_id']);
        $this->assertSame(1, (int) $fold->attempt_count); $this->assertNotNull($fold->request_hash); $this->assertNotNull($fold->response_hash);
        foreach ([$experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id] as $agentId) {
            $trace = app(LabImmutableEvidenceService::class)->decisionTraceCompletenessForOriginalFold($fold, (int) $agentId);
            $this->assertTrue($trace['complete'], json_encode($trace));
        }
        foreach (LabEvaluationRun::whereIn('lab_agent_id', [$experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id])->get() as $run) {
            $this->assertSame('completed', $run->status);
            $this->assertTrue(app(LabImmutableEvidenceService::class)->learningEligibility($run)['complete']);
            $this->assertNotNull(app(LabImmutableEvidenceService::class)->verifiedModelRuntimeIdentity($run));
        }
        $this->assertDatabaseCount('lab_evaluation_runs', $completedFoldCount * 3);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    private function members(string $prefix = 'fixture', bool $future = false, bool $compound = false): array
    {
        $members = []; $epochs = app(ResearchPaperEpochContractService::class); $hashes = app(ExecutionContractService::class);
        $compound = $future && $compound;
        $family = $compound ? 'differential_router' : 'breakout';
        $strategy = $compound ? 'differential_router_v1' : 'breakout';
        $gene = $compound ? 'minimum_confidence' : 'lookback';
        $schema = app(StrategyParameterSchemaService::class);
        $baseline = $compound ? $schema->validate($strategy, [...array_intersect_key($schema->defaults($family),
            $schema->schema($family)), 'time_stop_candles' => 1]) : ['lookback' => 20];
        $values = $compound ? [1.1, 1.2, $baseline[$gene]] : [25, 30, 20];
        foreach (range(0, 4) as $index) {
            $key = $prefix.'-'.$index;
            $lab = AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD', 'timeframe' => 'M5'], ['name' => $key, 'strategy_families' => [$family]]);
            $release = ['protocol' => ResearchReleaseSealService::PROTOCOL, 'release_hash' => hash('sha256', 'synthetic-release')];
            $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => LabGeneration::count() + 1, 'trigger_type' => 'synthetic_test',
                'status' => 'draft', 'population_size' => 3, 'trigger_context' => $future ? [] : ['research_release' => $release]]);
            $receipt = ['protocol' => 'causal_selector_observation_v1', 'registered_at' => now()->utc()->toIso8601String(),
                'question_key' => hash('sha256', 'synthetic-question-'.$key), 'seed' => $key,
                'baseline_parameter_hash' => $hashes->hashParameters($baseline),
                'legal_mutation_space_hash' => $hashes->hashParameters(['schema' => $schema->schema($family),
                    'baseline' => $baseline, 'target' => 'profit_factor']),
                'minimum_distinct_questions' => 5, 'per_selector_admission_seconds_limit' => 30,
                'timing_scope' => 'synthetic_original_selector_only', 'blinded_guided_treatment_exclusion' => false,
                'arms' => ['memory_enabled' => ['wall_seconds' => .2, 'within_admission_budget' => true,
                    'selected_gene' => $gene, 'old_value' => $baseline[$gene], 'value' => $values[0], 'memory_input_ids' => ['lesson_id' => 1]],
                    'memory_blinded' => ['wall_seconds' => .3, 'within_admission_budget' => true,
                        'selected_gene' => $gene, 'old_value' => $baseline[$gene], 'value' => $values[1], 'memory_input_ids' => []]]];
            $receipt['receipt_hash'] = $hashes->hashParameters($receipt);
            $agents = [];
            foreach ($values as $armIndex => $value) {
                $parameters = [...$baseline, $gene => $value];
                $model = ModelVersion::create(['name' => $key.'-'.$armIndex, 'strategy' => $strategy, 'version' => $key.'-'.$armIndex,
                    'status' => 'testing', 'parameters' => $parameters,
                    'metadata' => [...($compound ? $this->compoundModelMetadata($parameters) : []),
                        'portfolio_council_lane' => ['causal_learning_cohort' => ['memory_search_receipt' => $receipt]],
                        ...($future ? ['execution_contract' => app(ExecutionContractService::class)->for('XAUUSD', 'M5'),
                            'learning_receipt' => ['causal_influence' => ['memory_guided', 'blinded_counterfactual', 'frozen_control'][$armIndex], 'integrity' => ['valid' => true]],
                            'causal_learning_cohort' => ['role' => ['memory_guided', 'blinded', 'frozen_control'][$armIndex]]] : [])]]);
                $agents[] = LabAgent::withoutEvents(fn () => LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                    'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy_family' => $family, 'origin' => 'synthetic_test', 'lifecycle_status' => 'draft']));
            }
            $experiment = AgentLearningCausalExperiment::create(['experiment_key' => $key, 'lab_generation_id' => $generation->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy_family' => $family, 'target' => 'profit_factor', 'gene_key' => $gene,
                'guided_agent_id' => $agents[0]->id, 'blinded_agent_id' => $agents[1]->id, 'control_agent_id' => $agents[2]->id,
                'status' => 'planned', 'evidence' => ['source_context_scope' => ['session' => 'fixture-'.$index]]]);
            $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'replay_dataset_hash' => hash('sha256', 'synthetic-dataset-'.$prefix),
                'execution_contract' => ['execution_hash' => hash('sha256', 'synthetic-execution')], 'research_release' => $release,
                'strategies' => array_map(fn ($agent) => ['lab_agent_id' => $agent->id, 'parameters' => $agent->modelVersion->parameters], $agents),
                'policy_context' => ['learning_confirmation_contracts' => []]];
            foreach ($agents as $agent) $request['policy_context']['learning_confirmation_contracts'][(string) $agent->id] = [
                'fold_universe_count' => 1, 'per_fold_budget_seconds' => 10, 'max_rows_per_fold' => 4096];
            if (! $future) {
                $result = app(TypedInstrumentFoundryService::class)->registerCausalBenchmark($experiment, $request);
                $this->assertSame('planned', $result['status'], json_encode($result));
            }
            $members[] = [$experiment, $request];
        }
        return $members;
    }

    private function compoundModelMetadata(array $parameters): array
    {
        $passport = app(CompositionAuthorityKernelService::class)->freeze(['symbol' => 'XAUUSD',
            'strategy_id' => 'mix_011_differential_router', 'tactic_id' => 'frozen_parent_differential_router',
            'risk_id' => 'atr_risk_envelope', 'management_id' => 'parameter_preserving_research',
            'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('c', 64),
            'data_contract' => ['m5_canonical' => true, 'closed_at_available_at' => true, 'backward_only_alignment' => true],
            'horizon_mode' => 'day_structure', 'horizon_contract' => ['overnight_allowed' => false],
            'confirmation_families' => ['price_reaction', 'market_structure']]);
        $hashMethod = new \ReflectionMethod(LabInstrumentResearchService::class, 'hash');
        $instrumentOwner = app(LabInstrumentResearchService::class);
        $assignment = ['protocol' => LabInstrumentResearchService::PROTOCOL, 'hash_protocol' => LabInstrumentResearchService::HASH_PROTOCOL,
            'status' => 'assigned', 'parameter_hash' => $hashMethod->invoke($instrumentOwner, $parameters),
            'selected_keys' => ['atr_risk_envelope'], 'selected' => [['instrument_key' => 'atr_risk_envelope',
                'role' => 'frozen_support', 'activation_contract' => ['closed_candle_only' => true, 'maximum_risk_percent' => .5]]],
            'source_components' => ['strategy_library_id' => 'mix_011_differential_router',
                'tactic_library_key' => 'frozen_parent_differential_router', 'risk_library_id' => 'atr_risk_envelope',
                'management_id' => 'parameter_preserving_research', 'composition_id' => $passport['composition_id']]];
        $assignment['assignment_hash'] = $hashMethod->invoke($instrumentOwner, $assignment);
        return ['base_strategy' => 'differential_router_v1', 'strategy_family' => 'differential_router',
            'strategy_architecture' => 'frozen_parent_differential_router',
            'tactic_contract' => app(TacticCatalogueService::class)->for('differential_router', 'frozen_parent_differential_router'),
            'smart_composition' => ['composition_passport' => $passport], 'instrument_research_assignment' => $assignment];
    }

    private function complete(AgentLearningCausalExperiment $experiment, array $request, float $delta, bool $missingEffort = false, float $candidateDrawdown = 5): void
    {
        $start = CarbonImmutable::parse('2027-01-02T00:00:00Z');
        $this->travelTo(CarbonImmutable::now()->greaterThanOrEqualTo($start) ? CarbonImmutable::now()->addSecond() : $start);
        $results = []; $items = [];
        foreach ([$experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id] as $index => $id) {
            $ledger = array_map(fn ($trade) => ['id' => $trade, 'entry_time' => CarbonImmutable::parse('2025-01-01T00:00:00Z')->addMinutes(($trade - 1) * 5)->toIso8601String(),
                'exit_time' => CarbonImmutable::parse('2025-01-01T00:00:00Z')->addMinutes(($trade - 1) * 5 + 1)->toIso8601String(), 'exit_reason' => 'take_profit'], range(1, 8));
            $result = ['profit_factor' => $index === 0 ? 1.2 + $delta : ($index === 1 ? 1.2 : 1.0),
                'total_trades' => 8, 'trade_ledger' => $ledger, 'max_drawdown_percent' => $index === 0 ? $candidateDrawdown : 5,
                'monte_carlo' => ['risk_of_ruin_percent' => 2],
                'pf_attribution' => ['summary' => ['cost_to_gross_profit_percent' => 10], 'stress_cost' => ['profit_factor' => 1],
                    'by_volatility' => [['trades' => 8, 'net_pf' => 1]], 'by_session' => [['trades' => 8, 'net_pf' => 1]]],
                'statistical_evidence' => ['original_position_maturity' => ['protocol' => 'original_position_maturity_v1',
                    'closed_trade_count' => count($ledger), 'open_position_count' => 0, 'censored_trade_count' => 0,
                    'unknown_maturity_count' => 0, 'forced_terminal_close_applied' => false],
                    'censored_trade_count' => 0, 'edge_quality' => ['worst_fold_profit_factor' => 1, 'worst_regime_pf' => 1,
                    'confidence_calibration' => ['calibration_score' => .8]]], 'opportunity_recall' => ['abstention_precision' => .8],
                'learning_confirmation' => ['status' => 'completed', 'execution_mode' => 'durable_single_fold_job', 'fold_count' => 1, 'fold_offset' => 0, 'fold_universe_count' => 1],
                'trade_ledger_hash' => hash('sha256', 'synthetic-ledger'), 'displayed_trade_count' => 8,
                'decision_trace' => [['candle_index' => 200, 'candle_time' => '2025-01-01T00:35:00Z', 'action' => 'WAIT', 'event_type' => 'signal_evaluation']],
                'data_quality' => ['research_release_receipt' => [], 'decision_trace' => ['protocol' => 'candle_decision_trace_v1',
                    'requested' => true, 'complete' => true, 'event_count' => 1, 'evaluated_candle_count' => 1, 'input_candle_count' => 201,
                    'audit_slice' => true, 'economic_score_input' => false], 'dataset_attestation' => ['consumed_rows' => 208],
                    'replay_executed_clock' => $this->clock($request)],
                'benchmark' => ['arm_replay_resources' => ['protocol' => 'arm_replay_resources_v1',
                    'scope' => 'economic_replay_only_excludes_shared_features_and_audit', 'wall_seconds' => 2, 'cpu_seconds' => 1,
                    'measured_segments' => 1, 'promotion_evidence' => false]]];
            if ($missingEffort && $index === 0) unset($result['benchmark']);
            $results[$id] = $result; $items[] = ['lab_agent_id' => $id, 'rolling_windows_count' => 1, 'result' => $result];
        }
        $response = ['leaderboard' => $items];
        $fold = CausalFoldReceipt::create(['receipt_key' => $experiment->experiment_key.'-fold',
            'agent_learning_causal_experiment_id' => $experiment->id, 'lab_generation_id' => $experiment->lab_generation_id,
            'fold_index' => 1, 'fold_count' => 1, 'status' => 'completed', 'attempt_count' => 1,
            'started_at' => now(), 'completed_at' => now(), 'observed_at' => now(),
            'request_payload' => $request, 'response_payload' => $response,
            'request_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($request),
            'response_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($response),
            'dataset_hash' => $request['replay_dataset_hash'], 'execution_hash' => $request['execution_contract']['execution_hash']]);
        data_set($request, 'policy_context.causal_fold_aggregate', ['protocol' => 'causal_fold_aggregate_receipt_v1',
            'experiment_id' => (int) $experiment->id, 'receipt_hashes' => [$fold->response_hash]]);
        $request['candles'] = array_map(fn ($index) => ['time' => CarbonImmutable::parse('2025-01-01T00:00:00Z')
            ->addMinutes(($index - 200) * 5)->toIso8601ZuluString(), 'close' => 100, 'bid' => 100, 'ask' => 101], range(0, 207));
        $immutable = app(LabImmutableEvidenceService::class);
        foreach ($results as $id => $result) {
            $agent = LabAgent::with('modelVersion', 'generation')->findOrFail($id);
            $run = $immutable->beginRun($agent, 'full_validation', 'synthetic_test',
                ['code_hash' => hash('sha256', 'synthetic-source'), 'source' => 'causal_fold_aggregate']);
            $immutable->attachRequest($run, [...$request, 'parameters' => $agent->modelVersion->parameters], ['data_hash' => $request['replay_dataset_hash']]);
            $immutable->finishRun($run, 'completed', $result);
            $this->assertTrue($immutable->learningEligibility($run->fresh())['complete'], json_encode($immutable->learningEligibility($run->fresh())));
        }
    }

    private function clock(array $request): array
    {
        $indices = "replay-executed-clock-v1:indices\n"; $schedule = "replay-executed-clock-v1:schedule\n";
        foreach (range(200, 207) as $index) {
            $indices .= $index."\n"; $execution = CarbonImmutable::parse('2025-01-01T00:00:00Z')->addMinutes(($index - 200) * 5);
            $schedule .= json_encode([$index, $execution->subMinutes(5)->toIso8601String(), $execution->toIso8601String()], JSON_UNESCAPED_SLASHES)."\n";
        }
        $body = ['protocol' => 'replay_executed_clock_v1', 'owner' => 'ordinary_single_position_v1',
            'semantics' => 'previous_closed_candle_next_open_v1', 'index_basis' => 'evaluated_frame_zero_based_v1',
            'input_rows' => 208, 'evaluation_offset_rows' => 0, 'execution_timeframe' => 'M5', 'duration_seconds' => 300,
            'dataset_hash' => $request['replay_dataset_hash'], 'execution_hash' => $request['execution_contract']['execution_hash'],
            'policy_hash' => null, 'probe_contract_hash' => null, 'complete' => true, 'decision_rows' => 8,
            'first_evaluation_index' => 200, 'last_evaluation_index' => 207, 'signal_start' => '2024-12-31T23:55:00+00:00',
            'signal_end' => '2025-01-01T00:30:00+00:00', 'execution_start' => '2025-01-01T00:00:00+00:00',
            'execution_end' => '2025-01-01T00:35:00+00:00', 'index_set_hash' => hash('sha256', $indices),
            'schedule_hash' => hash('sha256', $schedule), 'promotion_evidence' => false];
        ksort($body); $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        return [...$body, 'receipt_json' => $json, 'receipt_hash' => hash('sha256', $json)];
    }

    private function ids(array $members): array { return array_map(fn ($member) => (int) $member[0]->id, $members); }
    private function benchmarkRow(AgentLearningCausalExperiment $experiment): object
    {
        $key = app(ResearchPaperEpochContractService::class)->parameterHash(['causal_equal_budget_observation_v1', $experiment->id, hash('sha256', 'synthetic-release')]);
        return DB::table('research_compounding_benchmarks')->where('benchmark_key', $key)->first();
    }
}
