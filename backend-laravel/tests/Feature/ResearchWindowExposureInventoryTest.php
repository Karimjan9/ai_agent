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
use App\Models\ResearchOriginalModelOrigin;
use App\Services\AutonomousModeService;
use App\Services\InstrumentResearchWindowService;
use App\Services\CausalFoldExecutionService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabGenerationReportService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\ResearchWindowExposureInventoryService;
use App\Services\ResearchWindowProvenanceAuditService;
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

/** Synthetic bytes test admission contracts; they are not original market evidence. */
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
        $this->travelTo(CarbonImmutable::parse('2011-10-09T00:00:00Z'));
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
            'event_start' => '2012-01-01 00:00:00', 'event_end' => '2012-01-01 00:05:00',
            'available_at' => '2012-01-01 00:05:00', 'matured_at' => '2012-01-01 00:05:00',
            'provenance' => json_encode(['synthetic_guard_only' => true]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('specialist_council_data_uses')->insert([
            'usage_key' => hash('sha256', 'synthetic-other-label-use'), 'specialist_council_version_id' => 999,
            'council_id' => 'synthetic-other-owner-label', 'event_id' => $event, 'use' => 'selection',
            'consumer_id' => 'synthetic', 'as_of' => now(), 'policy_hash' => hash('sha256', 'synthetic-policy'),
            'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('CANDIDATE_INTERSECTS_ORIGINAL_PHYSICAL_EXPOSURE',
            app(ResearchWindowExposureInventoryService::class)->assessForCertificate($certificate, $window, $manifest, [])['reason_code']);
    }

    public function test_historical_question_can_register_at_today_clock_before_model_exposure(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11T18:00:00Z'));
        [$certificate] = $this->question(firstExposure: true);
        $registration = app(ScopedResearchCertificateService::class)->verifiedRegistration($certificate);
        $capture = ResearchExposureCaptureRecord::where('certificate_id', $certificate)->where('record_type', 'capture_start')->sole();

        $this->assertSame('2026-10-11T18:00:00+00:00', $registration['preregistered_at']);
        $this->assertSame('2012-01-01T00:00:00+00:00', $registration['design']['validation_start']);
        $this->assertSame(ScopedResearchCertificateService::FIRST_EXPOSURE_TIME_POLICY, $capture->payload['registration_time_policy']);
        $this->assertFalse($capture->payload['historical_training_selection_inventory_complete']);
        $this->assertFalse(app(ScopedResearchCertificateService::class)->inspect($certificate)['authority']);
        $readiness = app(ResearchWindowExposureInventoryService::class)->assessForCertificate(
            $certificate, $registration['design']['validation_windows'][0], [], []);
        $this->assertFalse($readiness['ready']);
        $this->assertFalse($readiness['candidate_unused_demonstrated']);
        $this->assertSame('HISTORICAL_FIRST_EXPOSURE_PROVENANCE_NOT_ATTESTED', $readiness['reason_code']);
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

    public function test_finite_server_origin_and_real_immutable_ingress_complete_scoped_historical_history_only(): void
    {
        [$window, $manifest, $request] = $this->inputs();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        [$certificate, $agents] = $this->question(firstExposure: true, original: true);
        $evidence = app(LabImmutableEvidenceService::class);
        $runIds = [];
        foreach ($agents as $agent) {
            $run = $evidence->beginRun($agent, 'full_validation', 'replay');
            $evidence->attachRequest($run, $request, ['data_hash' => $manifest['bundle_hash']]);
            $runIds[] = (int) $run->id;
        }
        $history = app(ResearchWindowExposureInventoryService::class)->originalHistoryAttestation(
            $certificate, $window['start_inclusive'], $window['end_exclusive'], $runIds);
        $this->assertTrue($history['ready'], json_encode($history));
        $ready = app(ResearchWindowExposureInventoryService::class)->assessForCertificate(
            $certificate, $window, $manifest, $runIds);
        $this->assertTrue($ready['ready'], json_encode($ready));
        $this->assertSame('FINITE_ORIGINAL_HISTORY_AND_INPUT_BYTES_ATTESTED',
            $ready['original_history_attestation']['reason_code']);
        $this->assertCount(3, $ready['complete_original_ingress_receipts']);
        $this->assertTrue($ready['original_history_attestation']['historical_training_selection_inventory_complete']);
        $this->assertFalse($ready['independent_evidence']);
        $this->assertFalse(app(ResearchWindowProvenanceAuditService::class)->audit(
            $window['start_inclusive'], $window['end_exclusive'])['candidate_unused_demonstrated']);
        $this->assertDatabaseCount('research_original_model_origins', 1);
        $this->assertDatabaseCount('research_exposure_capture_records', 4);
        $this->assertDatabaseCount('research_experiment_work_items', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->travelTo(now()->addSecond());
        LabEvaluationRun::create(['run_id' => (string) \Illuminate\Support\Str::uuid(), 'phase' => 'screening',
            'mode' => 'diagnostic', 'status' => 'technical_error', 'attempt' => 1, 'started_at' => now(),
            'request_meta' => ['dataset_manifest' => ['first_candle_at' => '2015-01-01T00:00:00Z',
                'last_candle_at' => '2015-02-01T00:00:00Z']]]);
        $later = app(ResearchWindowExposureInventoryService::class)->assessForCertificate(
            $certificate, $window, $manifest, $runIds);
        $this->assertSame($ready['readiness_hash'], $later['readiness_hash'], json_encode($later));
        // Existing source-owned terminal projection must not rewrite the frozen
        // parameter choice or make its original input history unverifiable.
        $source = AgentLearningCausalExperiment::sole();
        app(CausalFoldExecutionService::class)->terminalFailure((int) $source->id, 0,
            new LogicException('SYNTHETIC_OWNER_TERMINAL_PROJECTION'));
        $agents['guided']->update(['lifecycle_status' => 'technical_quarantine']);
        $after = app(ResearchWindowExposureInventoryService::class)->assessForCertificate(
            $certificate, $window, $manifest, $runIds);
        $this->assertTrue($after['ready'], json_encode($after));
        $this->assertFalse($after['independent_evidence']);
    }

    public function test_finite_origin_does_not_turn_an_unknown_global_legacy_run_into_unused_proof(): void
    {
        [$window, $manifest] = $this->inputs();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        LabEvaluationRun::create(['run_id' => (string) \Illuminate\Support\Str::uuid(), 'phase' => 'screening',
            'mode' => 'diagnostic', 'status' => 'technical_error', 'attempt' => 1, 'started_at' => now()]);
        [$certificate] = $this->question(firstExposure: true, original: true);
        $audit = app(ResearchWindowProvenanceAuditService::class)->auditForCertificate($certificate,
            $window['start_inclusive'], $window['end_exclusive']);
        $this->assertFalse($audit['candidate_unused_demonstrated']);
        $this->assertSame('ORIGINAL_GLOBAL_RUN_INPUT_HISTORY_UNATTESTED', $audit['reason_code']);
    }

    public function test_original_birth_does_not_hide_a_new_failed_or_partial_attempt_without_bytes(): void
    {
        [$window, $manifest] = $this->inputs();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        [$certificate, $agents] = $this->question(firstExposure: true, original: true);
        $evidence = app(LabImmutableEvidenceService::class);
        $evidence->beginRun($agents['guided'], 'screening', 'screen');
        $audit = app(ResearchWindowProvenanceAuditService::class)->auditForCertificate($certificate,
            $window['start_inclusive'], $window['end_exclusive']);
        $this->assertFalse($audit['candidate_unused_demonstrated']);
        $this->assertSame('ORIGINAL_GLOBAL_RUN_INPUT_HISTORY_UNATTESTED', $audit['reason_code']);
    }

    public function test_late_owned_attempt_after_named_products_is_not_omitted_before_first_issuance(): void
    {
        [$window, $manifest, $request] = $this->inputs();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        [$certificate, $agents] = $this->question(firstExposure: true, original: true);
        $evidence = app(LabImmutableEvidenceService::class);
        $original = $evidence->beginRun($agents['guided'], 'full_validation', 'replay');
        $evidence->attachRequest($original, $request, ['data_hash' => $manifest['bundle_hash']]);
        $late = $evidence->beginRun($agents['guided'], 'screening', 'screen');
        $this->assertGreaterThan($original->id, $late->id);
        $proof = app(ResearchWindowExposureInventoryService::class)->originalHistoryAttestation(
            $certificate, $window['start_inclusive'], $window['end_exclusive'], [(int) $original->id]);
        $this->assertFalse($proof['ready']);
        $this->assertSame('ORIGINAL_GLOBAL_RUN_INPUT_HISTORY_UNATTESTED', $proof['reason_code']);
    }

    public function test_finite_origin_rejects_parent_binding_and_new_tuned_metadata(): void
    {
        [$window] = $this->inputs();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        [$certificate, $agents] = $this->question(firstExposure: true, original: true);
        DB::table('lab_agents')->where('id', $agents['guided']->id)
            ->update(['parent_a_model_version_id' => $agents['control']->model_version_id]);
        $proof = app(ResearchWindowExposureInventoryService::class)->originalHistoryAttestation(
            $certificate, $window['start_inclusive'], $window['end_exclusive']);
        $this->assertFalse($proof['ready']);
        $this->assertSame('ORIGINAL_ORIGIN_ANCESTRAL_SELECTION_FORBIDDEN', $proof['reason_code']);
        DB::table('lab_agents')->where('id', $agents['guided']->id)->update(['parent_a_model_version_id' => null]);
        $model = $agents['guided']->modelVersion;
        $model->update(['metadata' => [...(array) $model->metadata, 'learned_policy' => ['arbitrary_source' => 9]]]);
        $this->assertFalse(app(ResearchWindowExposureInventoryService::class)->originalHistoryAttestation(
            $certificate, $window['start_inclusive'], $window['end_exclusive'])['ready']);
    }

    public function test_origin_cannot_be_rewritten_and_pause_denies_a_new_triplet_atomically(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        [$certificate] = $this->question(firstExposure: true, original: true);
        try {
            ResearchOriginalModelOrigin::sole()->update(['payload_hash' => str_repeat('a', 64)]);
            $this->fail('Original origin was mutable.');
        } catch (LogicException $error) {
            $this->assertSame('ORIGINAL_MODEL_ORIGIN_APPEND_ONLY', $error->getMessage());
        }
        $generation = LabGeneration::sole();
        $fresh = LabGeneration::create(['ai_laboratory_id' => $generation->ai_laboratory_id,
            'generation' => 2, 'trigger_type' => 'test', 'population_size' => 3, 'status' => 'draft']);
        app(AutonomousModeService::class)->pause('XAUUSD', 'M15', 'synthetic-test', 'test-pause');
        $before = ModelVersion::count();
        try {
            app(ResearchWindowExposureInventoryService::class)->createOriginalUntrainedTriplet((int) $fresh->id);
            $this->fail('PAUSE admitted new original agents.');
        } catch (LogicException $error) {
            $this->assertSame('ORIGINAL_COMPONENT_AUTONOMY_COMMIT_DENIED', $error->getMessage());
        }
        $this->assertSame($before, ModelVersion::count());
        $this->assertSame(0, $fresh->agents()->count());
    }

    public function test_origin_source_byte_and_same_physical_time_relabel_are_not_unused_data(): void
    {
        [$window, $manifest, $request] = $this->inputs();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        [$certificate, $agents] = $this->question(firstExposure: true, original: true);
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($agents['guided'], 'screening', 'screen');
        $evidence->attachRequest($run, $request, ['data_hash' => $manifest['bundle_hash']]);
        $proof = app(ResearchWindowExposureInventoryService::class)->originalHistoryAttestation(
            $certificate, $window['start_inclusive'], $window['end_exclusive']);
        $this->assertSame('CANDIDATE_INTERSECTS_ORIGINAL_PHYSICAL_EXPOSURE', $proof['reason_code']);
        File::append($manifest['streams']['H4']['path'], 'source-mutated');
        $this->assertFalse(app(ResearchWindowExposureInventoryService::class)->originalHistoryAttestation(
            $certificate, $window['start_inclusive'], $window['end_exclusive'])['ready']);
    }

    public function test_caller_product_ids_and_window_relabels_cannot_manufacture_unused_history(): void
    {
        [$window] = $this->inputs();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        [$certificate] = $this->question(firstExposure: true, original: true);
        $owner = app(ResearchWindowExposureInventoryService::class);
        $bad = $owner->originalHistoryAttestation($certificate, $window['start_inclusive'], $window['end_exclusive'], [999]);
        $this->assertSame('ORIGINAL_HISTORY_PRODUCT_ROSTER_NOT_ORIGINAL', $bad['reason_code']);
        $different = $owner->originalHistoryAttestation($certificate, '2013-01-01T00:00:00Z', '2013-02-01T00:00:00Z');
        $this->assertSame('ORIGINAL_HISTORY_EXACT_REGISTERED_WINDOW_REQUIRED', $different['reason_code']);
        $paper = $owner->originalHistoryAttestation($certificate, '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z');
        $this->assertSame('ORIGINAL_HISTORY_RESEARCH_INTERVAL_REQUIRED', $paper['reason_code']);
        $origin = ResearchOriginalModelOrigin::sole();
        $payload = $origin->payload;
        $payload['historical_training_selection_inventory_complete'] = true;
        DB::table('research_original_model_origins')->where('id', $origin->id)->update(['payload' => json_encode($payload)]);
        $this->assertSame('ORIGINAL_MODEL_ORIGIN_SEAL_DRIFT', $owner->originalHistoryAttestation(
            $certificate, $window['start_inclusive'], $window['end_exclusive'])['reason_code']);
    }

    public function test_unproven_market_alias_never_excludes_shared_physical_bytes(): void
    {
        $owner = app(ResearchWindowExposureInventoryService::class);
        $guard = new \ReflectionMethod($owner, 'assertOriginalHistoryDisjoint');
        $this->expectExceptionMessage('ORIGINAL_GLOBAL_PHYSICAL_MARKET_IDENTITY_UNATTESTED');
        $guard->invoke($owner, ['intervals' => [['symbol' => 'EURUSD',
            'start_inclusive' => '2012-01-01T00:00:00Z', 'end_exclusive' => '2012-01-02T00:00:00Z']]],
            CarbonImmutable::parse('2012-01-01T00:00:00Z'), CarbonImmutable::parse('2012-02-01T00:00:00Z'), false);
    }

    public function test_birth_prefix_streams_beyond_256_rows_without_asserting_unknown_history_complete(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        $rows = [];
        for ($index = 0; $index < 300; $index++) {
            $rows[] = ['run_id' => (string) \Illuminate\Support\Str::uuid(), 'phase' => 'screening',
                'mode' => 'diagnostic', 'status' => 'technical_error', 'attempt' => 1, 'started_at' => now()];
        }
        LabEvaluationRun::insert($rows);
        [$certificate] = $this->question(firstExposure: true, original: true);
        $origin = ResearchOriginalModelOrigin::sole();
        $this->assertSame(300, $origin->payload['prior_run_roster']['count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $origin->payload['prior_run_roster']['sha256']);
        $proof = app(ResearchWindowExposureInventoryService::class)->originalHistoryAttestation(
            $certificate, '2012-01-01T00:00:00Z', '2012-02-01T00:00:00Z');
        $this->assertFalse($proof['ready']);
        $this->assertSame('ORIGINAL_GLOBAL_RUN_INPUT_HISTORY_UNATTESTED', $proof['reason_code']);
    }

    public function test_mysql_zero_fraction_timestamp_representation_preserves_original_seals_but_changed_fraction_is_refused(): void
    {
        [$window, $manifest] = $this->inputs();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        [$certificate] = $this->question(firstExposure: true, original: true);
        $stamp = '2026-10-19 10:00:00';
        DB::table('research_original_model_origins')->update(['recorded_at' => $stamp.'.000000']);
        DB::table('research_exposure_capture_records')->update(['recorded_at' => $stamp.'.000000']);
        DB::table('scoped_research_certificates')->update(['recorded_at' => $stamp.'.000000', 'preregistered_at' => $stamp.'.000000']);
        $owner = app(ResearchWindowExposureInventoryService::class);
        $this->assertTrue($owner->assessForCertificate($certificate, $window, $manifest, [])['ready']);
        DB::table('research_original_model_origins')->update(['recorded_at' => $stamp.'.000001']);
        $this->assertSame('ORIGINAL_MODEL_ORIGIN_SEAL_DRIFT', $owner->originalHistoryAttestation(
            $certificate, $window['start_inclusive'], $window['end_exclusive'])['reason_code']);
        DB::table('research_original_model_origins')->update(['recorded_at' => $stamp.'.000000']);
        DB::table('research_exposure_capture_records')->update(['recorded_at' => $stamp.'.000001']);
        $this->assertSame('EXPOSURE_CAPTURE_SEAL_OR_PAYLOAD_DRIFT', $owner->originalHistoryAttestation(
            $certificate, $window['start_inclusive'], $window['end_exclusive'])['reason_code']);
        DB::table('research_exposure_capture_records')->update(['recorded_at' => $stamp.'.000000']);
        DB::table('scoped_research_certificates')->update(['preregistered_at' => $stamp.'.000001']);
        $this->assertSame('SCOPED_CERTIFICATE_SEAL_OR_PAYLOAD_DRIFT', $owner->originalHistoryAttestation(
            $certificate, $window['start_inclusive'], $window['end_exclusive'])['reason_code']);
    }

    public function test_signed_other_scope_ingress_with_deleted_owner_before_birth_remains_an_unknown_global_exposure(): void
    {
        [$olderCertificate, $olderAgents] = $this->question();
        [$window, $manifest, $request] = $this->inputs();
        $evidence = app(LabImmutableEvidenceService::class);
        $older = $evidence->beginRun($olderAgents['guided'], 'screening', 'screen');
        $evidence->attachRequest($older, $request, ['data_hash' => $manifest['bundle_hash']]);
        $signed = ResearchExposureCaptureRecord::where('record_type', 'request_ingress')->sole();
        $this->assertSame($olderCertificate, (int) $signed->certificate_id);
        DB::table('lab_evaluation_runs')->where('id', $older->id)->delete();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        [$certificate] = $this->question(firstExposure: true, original: true);
        $proof = app(ResearchWindowExposureInventoryService::class)->originalHistoryAttestation(
            $certificate, $window['start_inclusive'], $window['end_exclusive']);
        $this->assertFalse($proof['ready']);
        $this->assertSame('ORIGINAL_GLOBAL_INGRESS_ORPHANED', $proof['reason_code']);
    }

    public function test_two_distinct_cold_births_have_unique_model_names_and_separate_original_rosters_without_dispatch(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
        $lab = AiLaboratory::create(['name' => 'Synthetic two-origin uniqueness', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'is_active' => true]);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'synthetic-test', 'isolated-two-origin-fixture');
        $firstGeneration = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'test', 'population_size' => 3, 'status' => 'draft']);
        $owner = app(ResearchWindowExposureInventoryService::class);
        $first = $owner->createOriginalUntrainedTriplet((int) $firstGeneration->id);
        $firstGeneration->agents()->update(['lifecycle_status' => 'abandoned',
            'decision_reason' => 'Synthetic source-only reference; no replay or scientific outcome.']);
        $firstGeneration->update(['status' => 'abandoned', 'completed_at' => now()]);
        $secondGeneration = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 2,
            'trigger_type' => 'test', 'population_size' => 3, 'status' => 'draft']);
        $second = $owner->createOriginalUntrainedTriplet((int) $secondGeneration->id);

        $this->assertDatabaseCount('research_original_model_origins', 2);
        $this->assertDatabaseCount('model_versions', 6);
        $this->assertSame(6, ModelVersion::query()->distinct()->count('name'));
        $this->assertNotSame($first['origin_id'], $second['origin_id']);
        $this->assertSame([], array_intersect($first['model_ids'], $second['model_ids']));
        $this->assertSame([], array_intersect($first['agent_ids'], $second['agent_ids']));
        foreach ([[$firstGeneration, $first], [$secondGeneration, $second]] as [$generation, $birth]) {
            $identity = $owner->originalFiniteOriginIdentity((int) $generation->id, $birth['experiment_id']);
            $actual = $identity['model_ids']; sort($actual);
            $expected = array_values($birth['model_ids']); sort($expected);
            $this->assertSame($expected, $actual);
            $this->assertSame($birth['origin_id'], $identity['origin_id']);
            $this->assertFalse($birth['authority']);
            $this->assertFalse($birth['dispatched']);
        }
        foreach (ModelVersion::all() as $model) $this->assertLessThanOrEqual(96, strlen($model->name));
        foreach (LabAgent::all() as $agent) $this->assertLessThanOrEqual(24, strlen($agent->origin));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('scoped_research_certificates', 0);
        $this->assertDatabaseCount('research_experiment_work_items', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertDatabaseCount('paper_orders', 0);
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
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

    private function question(int $holding = 600, bool $firstExposure = false, bool $original = false): array
    {
        $lab = AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD'], ['name' => 'Synthetic exposure fixture',
            'timeframe' => 'M15', 'strategy_families' => ['hybrid'], 'is_active' => true]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id,
            'generation' => (int) $lab->generations()->max('generation') + 1,
            'trigger_type' => 'test', 'population_size' => 3, 'status' => $original ? 'draft' : 'queued']);
        $agents = []; $arms = [];
        if ($original) {
            app(AutonomousModeService::class)->start('XAUUSD', 'M15', 'synthetic-test', 'isolated-original-fixture');
            $birth = app(ResearchWindowExposureInventoryService::class)->createOriginalUntrainedTriplet((int) $generation->id);
            foreach ($birth['agent_ids'] as $role => $id) {
                $agents[$role] = LabAgent::findOrFail($id);
                $model = $agents[$role]->modelVersion;
                $arms[$role] = ['model_version_id' => (int) $model->id,
                    'parameter_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($model->parameters)];
            }
            $source = AgentLearningCausalExperiment::findOrFail($birth['experiment_id']);
        } else {
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
        }
        $design = ['authority_policy' => ResearchWindowExposureInventoryService::AUTHORITY_POLICY,
            'validation_start' => '2012-01-01T00:00:00+00:00', 'validation_end' => '2012-02-01T00:00:00+00:00',
            'validation_windows' => [['start_inclusive' => '2012-01-01T00:00:00+00:00',
                'end_exclusive' => '2012-02-01T00:00:00+00:00']],
            'evaluator_hash' => hash('sha256', 'synthetic-evaluator'), 'context_hash' => hash('sha256', 'synthetic-context'),
            'execution_hash' => hash('sha256', 'synthetic-execution'), 'owner_source_hash' => hash('sha256', 'synthetic-owner'),
            'data_manifest_hash' => null, 'metric' => 'profit_factor', 'stopping_rule' => 'complete_sealed_window',
            'subject' => ['arm_models' => $arms], 'exposure_policy' => [
                'protocol' => 'prospective_scoped_exposure_policy_v1', 'holding_fence_seconds' => $holding,
                'execution_timeframe' => 'M5', 'context_timeframes' => ['H4', 'H1', 'M15'],
                'warmup_policy' => 'all_original_closed_source_rows_inside_registered_window',
                'selection_policy' => 'frozen_before_first_event']];
        if ($firstExposure) {
            $design['registration_time_policy'] = ScopedResearchCertificateService::FIRST_EXPOSURE_TIME_POLICY;
            $design['exposure_policy']['selection_policy'] = 'frozen_before_first_exposure';
        }
        $registered = app(ScopedResearchCertificateService::class)->register('component', $source, $design);
        return [$registered['certificate_id'], $agents];
    }

    private function inputs(): array
    {
        $this->travelTo(CarbonImmutable::parse('2013-01-01T00:00:00Z'));
        $streams = [];
        foreach (['M5' => 300, 'H4' => 14400, 'H1' => 3600, 'M15' => 900] as $stream => $seconds) {
            $from = '2012-01-01T00:00:00Z';
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
            'start_inclusive' => '2012-01-01T00:00:00Z', 'end_exclusive' => '2012-02-01T00:00:00Z',
            'mtf_bundle_manifest' => $manifest]]);
        $window = app(InstrumentResearchWindowService::class)->seal('synthetic-exposure-input', $manifest['bundle_hash']);
        $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'dataset_path' => $streams['M5']['path'],
            'replay_dataset_hash' => $manifest['bundle_hash'], 'mtf_snapshot_manifest' => $manifest,
            'mtf_dataset_paths' => array_map(fn ($file) => $file['path'], $streams), 'candles' => [],
            'parameters' => ['warmup_candles' => 200, 'lookback_candles' => 512]];
        return [$window, $manifest, $request];
    }
}
