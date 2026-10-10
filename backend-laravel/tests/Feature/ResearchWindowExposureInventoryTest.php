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
use App\Services\InstrumentResearchWindowService;
use App\Services\CausalFoldExecutionService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabGenerationReportService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\ResearchWindowExposureInventoryService;
use App\Services\ResearchKnowledgePortfolioService;
use App\Services\ResearchReleaseSealService;
use App\Services\ScopedResearchCertificateService;
use App\Services\TypedInstrumentFoundryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

/** Explicit synthetic bytes and a 2028 test clock validate guards, not actual future evidence. */
class ResearchWindowExposureInventoryTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureRoot;
    private string $previousStorage;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        config()->set('services.instrument_policy.authorized_research_windows', []);
        config()->set('services.research_paper_epochs.authorized_paper_epochs', []);
        $this->travelTo(CarbonImmutable::parse('2026-10-09T00:00:00Z'));
        $this->fixtureRoot = sys_get_temp_dir().'/research-exposure-synthetic-'.bin2hex(random_bytes(8));
        $this->previousStorage = storage_path();
        app()->useStoragePath($this->fixtureRoot.'/storage');
    }

    protected function tearDown(): void
    {
        app()->useStoragePath($this->previousStorage);
        $resolved = realpath($this->fixtureRoot);
        $prefix = str_replace('\\', '/', realpath(sys_get_temp_dir())).'/research-exposure-synthetic-';
        if ($resolved && str_starts_with(str_replace('\\', '/', $resolved), $prefix)) File::deleteDirectory($resolved);
        parent::tearDown();
    }

    public function test_future_frozen_capture_can_verify_only_its_actual_candidate_scope(): void
    {
        [$certificate] = $this->question();
        [$window, $manifest] = $this->inputs();
        $ready = app(ResearchWindowExposureInventoryService::class)->assessForCertificate($certificate, $window, $manifest, []);

        $this->assertTrue($ready['ready'], json_encode($ready));
        $this->assertTrue($ready['prospective_scope_inventory_attested']);
        $this->assertTrue($ready['candidate_unused_demonstrated']);
        $this->assertFalse($ready['historical_training_selection_inventory_complete']);
        $this->assertFalse($ready['absence_of_recorded_use_proves_unused']);
        $this->assertFalse($ready['independent_evidence']);
        $this->assertCount(4, $ready['actual_physical_inventory']['intervals']);
        foreach ($ready['actual_physical_inventory']['files'] as $file) {
            $this->assertSame(2, $file['rows']);
            $this->assertTrue($file['all_rows_captured']);
        }
        $strict = app(InstrumentResearchWindowService::class)->originalValidationReadiness($window, $manifest,
            ['owner' => ResearchWindowExposureInventoryService::class, 'certificate_id' => $certificate, 'run_ids' => []]);
        $this->assertSame($ready['readiness_hash'], $strict['readiness_hash']);
        $legacy = app(InstrumentResearchWindowService::class)->originalValidationReadiness($window, $manifest,
            ['ready' => true, 'inventory_complete' => true]);
        $this->assertFalse($legacy['ready']);
        $event = DB::table('specialist_council_data_events')->insertGetId([
            'event_key' => hash('sha256', 'synthetic-shared-selection'), 'symbol' => 'XAUUSD', 'market' => 'spot',
            'event_start' => '2027-01-01 00:00:00', 'event_end' => '2027-01-01 00:05:00',
            'available_at' => '2027-01-01 00:05:00', 'matured_at' => '2027-01-01 00:05:00',
            'provenance' => json_encode(['synthetic_guard_only' => true]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('specialist_council_data_uses')->insert([
            'usage_key' => hash('sha256', 'synthetic-other-label-use'), 'specialist_council_version_id' => 999,
            'council_id' => 'synthetic-other-owner-label', 'event_id' => $event, 'use' => 'selection',
            'consumer_id' => 'synthetic', 'as_of' => now(), 'policy_hash' => hash('sha256', 'synthetic-policy'),
            'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('CANDIDATE_INTERSECTS_ORIGINAL_PHYSICAL_EXPOSURE',
            app(ResearchWindowExposureInventoryService::class)->assessForCertificate($certificate, $window, $manifest, [])['reason_code']);
    }

    public function test_real_immutable_request_ingress_captures_every_stream_and_omitted_original_blocks(): void
    {
        [$certificate, $agents] = $this->question();
        [$window, $manifest, $request] = $this->inputs();
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($agents['guided'], 'full_validation', 'replay');
        $evidence->attachRequest($run, $request, ['data_hash' => $manifest['bundle_hash']]);
        $capture = ResearchExposureCaptureRecord::where('record_type', 'request_ingress')->sole();
        $this->assertSame((int) $run->id, $capture->evaluation_run_id);
        $this->assertCount(4, $capture->payload['inventory']['files']);

        $owner = app(ResearchWindowExposureInventoryService::class);
        $this->assertSame('CANDIDATE_INTERSECTS_ORIGINAL_PHYSICAL_EXPOSURE',
            $owner->assessForCertificate($certificate, $window, $manifest, [])['reason_code']);
        $ready = $owner->assessForCertificate($certificate, $window, $manifest, [(int) $run->id]);
        $this->assertTrue($ready['ready'], json_encode($ready));
        $this->assertCount(1, $ready['complete_original_ingress_receipts']);
        $this->assertSame([(int) $run->id], $ready['original_run_ids']);
    }

    public function test_partial_or_directly_inserted_original_can_never_be_completed_by_absence(): void
    {
        [$certificate, $agents] = $this->question();
        [$window, $manifest] = $this->inputs();
        $run = app(LabImmutableEvidenceService::class)->beginRun($agents['guided'], 'full_validation', 'replay');
        $result = app(ResearchWindowExposureInventoryService::class)->assessForCertificate($certificate, $window, $manifest, [(int) $run->id]);
        $this->assertSame('EXPOSURE_ORIGINAL_INGRESS_CAPTURE_INCOMPLETE', $result['reason_code']);
        $this->assertFalse($result['candidate_unused_demonstrated']);
    }

    public function test_caller_run_list_cannot_relabel_screening_selection_as_original_validation(): void
    {
        [$certificate, $agents] = $this->question();
        [$window, $manifest, $request] = $this->inputs();
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($agents['guided'], 'screening', 'incremental');
        $evidence->attachRequest($run, $request, ['data_hash' => $manifest['bundle_hash']]);
        $result = app(ResearchWindowExposureInventoryService::class)->assessForCertificate($certificate, $window, $manifest, [(int) $run->id]);
        $this->assertSame('EXPOSURE_TRAINING_OR_SELECTION_CANNOT_BE_VALIDATION_ORIGINAL', $result['reason_code']);
        $this->assertFalse($result['candidate_unused_demonstrated']);
    }

    public function test_existing_partial_original_prevents_a_new_capture_start(): void
    {
        [$certificate, $agents] = $this->question();
        DB::table('research_exposure_capture_records')->where('certificate_id', $certificate)->delete();
        app(LabImmutableEvidenceService::class)->beginRun($agents['guided'], 'screening', 'replay');
        $this->expectExceptionMessage('EXPOSURE_CAPTURE_CANNOT_COMPLETE_PREEXISTING_PARTIAL_HISTORY');
        app(ResearchWindowExposureInventoryService::class)->registerCapture($certificate);
    }

    public function test_unrecognized_auxiliary_ingress_is_refused_before_delivery_and_remains_incomplete(): void
    {
        [$certificate, $agents] = $this->question();
        [$window, $manifest, $request] = $this->inputs();
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($agents['guided'], 'full_validation', 'replay');
        try {
            $evidence->attachRequest($run, [...$request, 'related_mtf_dataset_paths' => ['H1' => $request['dataset_path']]], ['data_hash' => $manifest['bundle_hash']]);
            $this->fail('An unknown source bypassed original ingress capture.');
        } catch (LogicException $error) {
            $this->assertSame('EXPOSURE_UNRECOGNIZED_SOURCE:related_mtf_dataset_paths', $error->getMessage());
        }
        $this->assertDatabaseCount('research_exposure_capture_records', 1);
        $this->assertSame('EXPOSURE_ORIGINAL_INGRESS_CAPTURE_INCOMPLETE',
            app(ResearchWindowExposureInventoryService::class)->assessForCertificate($certificate, $window, $manifest, [(int) $run->id])['reason_code']);
    }

    public function test_lost_declared_capture_start_cannot_silently_fall_back_to_legacy_ingress(): void
    {
        [$certificate, $agents] = $this->question();
        [$window, $manifest, $request] = $this->inputs();
        DB::table('research_exposure_capture_records')->where('certificate_id', $certificate)->delete();
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($agents['guided'], 'full_validation', 'replay');
        $this->expectExceptionMessage('EXPOSURE_DECLARED_SCOPE_CAPTURE_BOUNDARY_MISSING');
        $evidence->attachRequest($run, $request, ['data_hash' => $manifest['bundle_hash']]);
    }

    public function test_actual_source_and_capture_seal_drift_block_readiness(): void
    {
        [$certificate, $agents] = $this->question();
        [$window, $manifest, $request] = $this->inputs();
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($agents['guided'], 'full_validation', 'replay');
        $evidence->attachRequest($run, $request, ['data_hash' => $manifest['bundle_hash']]);
        $row = ResearchExposureCaptureRecord::where('record_type', 'request_ingress')->sole();
        $payload = $row->payload;
        $payload['inventory']['files']['H4']['rows'] = 999;
        DB::table('research_exposure_capture_records')->where('id', $row->id)->update(['payload' => json_encode($payload)]);
        $this->assertSame('EXPOSURE_CAPTURE_SEAL_OR_PAYLOAD_DRIFT',
            app(ResearchWindowExposureInventoryService::class)->assessForCertificate($certificate, $window, $manifest, [(int) $run->id])['reason_code']);
        DB::table('research_exposure_capture_records')->where('id', $row->id)->update(['payload' => json_encode($row->payload)]);
        File::append($manifest['streams']['M15']['path'], 'source-mutated');
        $this->assertFalse(app(ResearchWindowExposureInventoryService::class)->assessForCertificate($certificate, $window, $manifest, [(int) $run->id])['ready']);
    }

    public function test_context_holding_must_fit_the_registered_physical_window(): void
    {
        [$certificate] = $this->question(31536000);
        [$window, $manifest] = $this->inputs();
        $result = app(ResearchWindowExposureInventoryService::class)->assessForCertificate($certificate, $window, $manifest, []);
        $this->assertSame('EXPOSURE_WARMUP_CONTEXT_OR_HOLDING_OUTSIDE_CAPTURE_SCOPE', $result['reason_code']);
    }

    public function test_caller_run_identity_and_duplicate_ingress_do_not_replace_original_receipts(): void
    {
        [$certificate, $agents] = $this->question();
        [$window, $manifest, $request] = $this->inputs();
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($agents['guided'], 'full_validation', 'replay');
        $evidence->attachRequest($run, $request, ['data_hash' => $manifest['bundle_hash']]);
        $this->assertSame('EXPOSURE_ORIGINAL_INGRESS_CAPTURE_INCOMPLETE',
            app(ResearchWindowExposureInventoryService::class)->assessForCertificate($certificate, $window, $manifest, [(int) $run->id + 99])['reason_code']);
        try {
            $evidence->attachRequest($run->fresh(), $request, ['data_hash' => $manifest['bundle_hash']]);
            $this->fail('A second request replaced the original ingress receipt.');
        } catch (LogicException $error) {
            $this->assertSame('EXPOSURE_ORIGINAL_INGRESS_IMMUTABLE', $error->getMessage());
        }
        $this->assertTrue(app(ResearchWindowExposureInventoryService::class)->assessForCertificate($certificate, $window, $manifest, [(int) $run->id])['ready']);
    }

    public function test_direct_fold_http_has_a_real_original_capture_before_any_response(): void
    {
        [$certificate, $agents] = $this->question();
        [$window, $manifest, $request] = $this->inputs();
        [$experiment, $request, $response] = $this->foldTransport($certificate, $agents, $manifest, $request);
        Http::fake(function () use ($response) {
            $record = ResearchExposureCaptureRecord::where('record_type', 'causal_fold_ingress')->sole();
            $this->assertTrue($record->payload['captured_before_evaluator_delivery']);
            $this->assertSame('running', CausalFoldReceipt::findOrFail($record->payload['causal_fold_receipt_id'])->status);
            return Http::response($response, 200);
        });
        $result = app(CausalFoldExecutionService::class)->run((int) $experiment->id, 1);
        $this->assertSame('completed', $result['status']);
        $fold = CausalFoldReceipt::findOrFail($result['receipt_id']);
        $ready = app(ResearchWindowExposureInventoryService::class)->assessForCertificate($certificate, $window, $manifest, [], [(int) $fold->id]);
        $this->assertTrue($ready['ready'], json_encode($ready));
        $this->assertCount(1, $ready['complete_original_fold_ingress_receipts']);
        $this->assertSame('CANDIDATE_INTERSECTS_ORIGINAL_FOLD_PHYSICAL_EXPOSURE',
            app(ResearchWindowExposureInventoryService::class)->assessForCertificate($certificate, $window, $manifest, [])['reason_code']);
        Http::assertSentCount(1);
    }

    public function test_lost_pre_http_fold_capture_quarantines_actual_raw_response_without_late_attestation(): void
    {
        [$certificate, $agents] = $this->question();
        [$window, $manifest, $request] = $this->inputs();
        [$experiment, $request, $response] = $this->foldTransport($certificate, $agents, $manifest, $request);
        $this->mock(LabGenerationReportService::class, fn ($mock) => $mock->shouldReceive('record')->once()->andReturn([]));
        Http::fake(function () use ($response) {
            DB::table('research_exposure_capture_records')->where('record_type', 'causal_fold_ingress')->delete();
            return Http::response($response, 200);
        });
        try {
            app(CausalFoldExecutionService::class)->run((int) $experiment->id, 1);
            $this->fail('A post-response call reconstructed a missing original capture.');
        } catch (LogicException $error) {
            $this->assertSame('EXPOSURE_ORIGINAL_FOLD_PREPUBLICATION_PROOF_REQUIRED', $error->getMessage());
        }
        $fold = CausalFoldReceipt::where('agent_learning_causal_experiment_id', $experiment->id)->sole();
        $this->assertSame('technical_error', $fold->status);
        $this->assertSame($response, $fold->response_payload);
        $this->assertSame('technical_quarantine', $experiment->fresh()->status);
        $this->assertDatabaseCount('research_exposure_capture_records', 1);
        $this->assertFalse(app(ResearchWindowExposureInventoryService::class)->assessForCertificate($certificate, $window, $manifest, [], [(int) $fold->id])['ready']);
    }

    private function foldTransport(int $certificate, array $agents, array $manifest, array $request): array
    {
        $registration = app(ScopedResearchCertificateService::class)->verifiedRegistration($certificate);
        $experiment = AgentLearningCausalExperiment::findOrFail($registration['source_id']);
        config()->set('services.learning_lane.causal_fold_count', 9);
        config()->set('services.ai_service.url', 'http://synthetic-exposure.test');
        $contracts = [];
        foreach ($agents as $agent) $contracts[(string) $agent->id] = ['maximum_holding_bars' => 1];
        $request['policy_context'] = ['learning_confirmation_contracts' => $contracts,
            'causal_fold_job' => ['experiment_id' => (int) $experiment->id, 'fold_index' => 1]];
        $request['strategies'] = array_map(fn ($agent) => ['lab_agent_id' => (int) $agent->id], array_values($agents));
        $this->mock(LabAgentEvaluationService::class, fn ($mock) => $mock->shouldReceive('causalFoldEnvelope')->once()->andReturn([
            'request' => $request, 'dataset_hash' => $manifest['bundle_hash'],
            'execution_hash' => hash('sha256', 'synthetic-fold-execution'), 'fold_count' => 9]));
        $this->mock(ResearchReleaseSealService::class, function ($mock) use ($request): void {
            $mock->shouldReceive('bindGenerationRequest')->once()->andReturn($request);
            $mock->shouldReceive('responseValid')->times(3)->andReturn(true);
        });
        $this->mock(TypedInstrumentFoundryService::class, fn ($mock) => $mock->shouldReceive('registerCausalBenchmark')->once()->andReturn([]));
        $this->mock(ResearchKnowledgePortfolioService::class, fn ($mock) => $mock->shouldReceive('preregisterExperiment')->once()->andReturn([]));
        $response = ['leaderboard' => array_map(fn ($agent) => ['lab_agent_id' => (int) $agent->id, 'rolling_windows_count' => 1,
            'result' => ['learning_confirmation' => ['execution_mode' => 'durable_single_fold_job',
                'fold_count' => 1, 'fold_offset' => 0, 'fold_universe_count' => 9]]], array_values($agents))];
        return [$experiment, $request, $response];
    }

    private function question(int $holding = 600): array
    {
        $lab = AiLaboratory::create(['name' => 'Synthetic exposure fixture', 'symbol' => 'XAUUSD',
            'timeframe' => 'M15', 'strategy_families' => ['hybrid'], 'is_active' => true]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'test', 'population_size' => 3, 'status' => 'queued']);
        $agents = []; $arms = [];
        foreach (['guided', 'blinded', 'control'] as $index => $role) {
            $model = ModelVersion::create(['name' => $role, 'strategy' => 'hybrid', 'version' => 'exposure-'.$role,
                'status' => 'testing', 'parameters' => ['threshold' => $index + 1]]);
            $agents[$role] = LabAgent::withoutEvents(fn () => LabAgent::create([
                'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'strategy_family' => 'hybrid',
                'origin' => 'test', 'lifecycle_status' => 'screening_queued', 'parameter_diff' => [],
            ]));
            $arms[$role] = ['model_version_id' => (int) $model->id,
                'parameter_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($model->parameters)];
        }
        $source = AgentLearningCausalExperiment::create([
            'experiment_key' => 'synthetic-exposure-'.$generation->id, 'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'gene_key' => 'threshold', 'status' => 'ready_for_replay',
            'guided_agent_id' => $agents['guided']->id, 'blinded_agent_id' => $agents['blinded']->id,
            'control_agent_id' => $agents['control']->id, 'evidence' => ['experiment_kind' => 'memory_confirmation'],
        ]);
        $design = ['authority_policy' => ResearchWindowExposureInventoryService::AUTHORITY_POLICY,
            'validation_start' => '2027-01-01T00:00:00+00:00', 'validation_end' => '2027-02-01T00:00:00+00:00',
            'validation_windows' => [['start_inclusive' => '2027-01-01T00:00:00+00:00',
                'end_exclusive' => '2027-02-01T00:00:00+00:00']],
            'evaluator_hash' => hash('sha256', 'synthetic-evaluator'), 'context_hash' => hash('sha256', 'synthetic-context'),
            'execution_hash' => hash('sha256', 'synthetic-execution'), 'owner_source_hash' => hash('sha256', 'synthetic-owner'),
            'data_manifest_hash' => null, 'metric' => 'profit_factor', 'stopping_rule' => 'complete_sealed_window',
            'subject' => ['arm_models' => $arms], 'exposure_policy' => [
                'protocol' => 'prospective_scoped_exposure_policy_v1', 'holding_fence_seconds' => $holding,
                'execution_timeframe' => 'M5', 'context_timeframes' => ['H4', 'H1', 'M15'],
                'warmup_policy' => 'all_original_closed_source_rows_inside_registered_window',
                'selection_policy' => 'frozen_before_first_event']];
        $registered = app(ScopedResearchCertificateService::class)->register('component', $source, $design);
        return [$registered['certificate_id'], $agents];
    }

    private function inputs(): array
    {
        $this->travelTo(CarbonImmutable::parse('2028-01-01T00:00:00Z'));
        $streams = [];
        foreach (['M5' => 300, 'H4' => 14400, 'H1' => 3600, 'M15' => 900] as $stream => $seconds) {
            $from = '2027-01-01T00:00:00Z';
            $last = CarbonImmutable::parse($from)->addSeconds($seconds)->format('Y-m-d\TH:i:s\Z');
            $path = storage_path('app/lab-datasets/'.$stream.'.csv');
            File::ensureDirectoryExists(dirname($path));
            File::put($path, "time,open,high,low,close,volume\n{$from},100,101,99,100,10\n{$last},100,102,99,101,11\n");
            $streams[$stream] = ['path' => $path, 'sha256' => hash_file('sha256', $path),
                'first_candle_at' => $from, 'last_candle_at' => $last, 'rows' => 2];
        }
        $manifest = ['streams' => $streams, 'bundle_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($streams)];
        config()->set('services.instrument_policy.authorized_research_windows', [[
            'authorization_id' => 'synthetic-exposure-input', 'research_epoch_id' => 'synthetic-only',
            'purpose' => 'instrument_independent_validation', 'dataset_sha256' => $manifest['bundle_hash'],
            'start_inclusive' => '2027-01-01T00:00:00Z', 'end_exclusive' => '2027-02-01T00:00:00Z',
            'mtf_bundle_manifest' => $manifest]]);
        $window = app(InstrumentResearchWindowService::class)->seal('synthetic-exposure-input', $manifest['bundle_hash']);
        $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'dataset_path' => $streams['M5']['path'],
            'replay_dataset_hash' => $manifest['bundle_hash'], 'mtf_snapshot_manifest' => $manifest,
            'mtf_dataset_paths' => array_map(fn ($file) => $file['path'], $streams), 'candles' => [],
            'parameters' => ['warmup_candles' => 200, 'lookback_candles' => 512]];
        return [$window, $manifest, $request];
    }
}
