<?php

namespace Tests\Feature;

use App\Models\ModelVersion;
use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\ResearchExperimentReceipt;
use App\Models\ResearchExperimentWorkItem;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabInstrumentResearchService;
use App\Services\InstrumentPolicyConsumptionService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\ResearchReleaseSealService;
use App\Services\SpecialistCouncilLifecycleService;
use App\Services\SpecialistCouncilContractService;
use App\Services\SpecialistCouncilPreparationService;
use App\Services\SpecialistCouncilResearchFeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

require_once __DIR__.'/ResearchSourceArtifactTest.php';

/** Synthetic source facts isolate readiness; no test fixture claims a market qualification. */
class SpecialistCouncilFollowupReadinessTest extends TestCase
{
    use RefreshDatabase;
    private array $observedSourceFixtureRoots = [];
    private ?string $observedOriginalStorage = null;

    protected function tearDown(): void
    {
        if ($this->observedOriginalStorage !== null) app()->useStoragePath($this->observedOriginalStorage);
        foreach ($this->observedSourceFixtureRoots as $root) {
            $resolved = realpath($root); $prefix = str_replace('\\', '/', realpath(sys_get_temp_dir())).'/observed-source-snapshot-';
            if ($resolved && str_starts_with(str_replace('\\', '/', $resolved), $prefix)) File::deleteDirectory($resolved);
        }
        parent::tearDown();
    }

    public function test_missing_server_resolution_stays_blocked_despite_caller_executable_flag(): void
    {
        [$work] = $this->fixture();
        $work->update(['payload' => [...$work->payload, 'executable' => true]]);
        $result = app(SpecialistCouncilResearchFeedbackService::class)->inspectFollowupReadiness($work->fresh());
        $this->assertFalse($result['executable']);
        $this->assertSame('COUNCIL_FOLLOWUP_PREREGISTRATION_REQUIRED', $result['reason']);
    }

    public function test_one_prospective_research_resolution_freezes_real_old_new_vectors_and_is_idempotent(): void
    {
        [$work, $proposal] = $this->fixture();
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $one = $service->registerFollowupProof($work->id, $proposal, 'first-operator');
        $two = $service->registerFollowupProof($work->id, $proposal, 'different-retry-operator');
        $this->assertTrue($one['executable']);
        $this->assertSame($one['resolution_hash'], $two['resolution_hash']);
        $this->assertSame('first-operator', $two['registered_by']);
        $this->assertSame(5, $one['native_source_models']['hour']['parameters']['ema_fast']);
        $this->assertSame([['gene' => 'ema_fast', 'old' => 4, 'new' => 5]], $one['native_source_models']['hour']['parameter_deltas']);
        $this->assertSame($work->id, $one['native_intent']['followup_work_item_id']);
        $this->assertSame('research', $one['native_intent']['purpose']);
        $this->assertSame(6, $one['native_intent']['population_size']);
        $this->assertFalse($one['independent_evidence_claimed']);
        $this->assertFalse($work->fresh()->payload['executable']);
        $this->assertDatabaseCount('research_experiment_work_items', 1);
        $this->assertDatabaseCount('lab_generations', 0);
    }

    public function test_previously_sealed_question_cannot_be_tuned_after_registration(): void
    {
        [$work, $proposal] = $this->fixture();
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $service->registerFollowupProof($work->id, $proposal);
        $proposal['parameter_deltas']['hour']['ema_fast'] = 6;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_FOLLOWUP_PREREGISTRATION_ALREADY_SEALED');
        $service->registerFollowupProof($work->id, $proposal);
    }

    public function test_original_server_registration_claims_bounded_discovery_lease_without_caller_authority(): void
    {
        $this->freezeTime();
        [$work, $proposal] = $this->fixture();
        $original = app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal, 'original-creator');
        $kernel = app(\App\Services\ResearchExperimentConversionKernelService::class);
        $lease = $kernel->claimForOwner(\App\Services\ResearchLoopArbiterService::class, 1)[0];
        $this->assertSame($work->id, $lease->id);
        $this->assertSame(2700, $lease->lease_expires_at->timestamp - now()->timestamp);
        $this->assertSame($original['resolution_hash'], data_get($lease->payload, 'followup_resolution.resolution_hash'));
        $this->assertFalse(data_get($lease->payload, 'promotion_evidence'));
        $this->assertDatabaseCount('lab_generations', 0);
        $this->assertTrue($kernel->defer($lease, 'ORIGINAL_FIXTURE_WAIT', true));
        // A modified rehashed projection cannot inherit the longer lease: the
        // original server signature and all original evidence are rechecked.
        $payload = $work->fresh()->payload;
        $payload['followup_resolution']['authority'] = 'economic_parent';
        $work->fresh()->update(['payload' => $payload]);
        $this->assertSame([], $kernel->claimForOwner(\App\Services\ResearchLoopArbiterService::class, 1));
        $this->assertSame(1, $work->fresh()->attempts);
        $this->assertNull($work->fresh()->lease_token);
    }

    public function test_original_assessment_redelivery_and_stale_normalization_preserve_registered_resolution(): void
    {
        [$work, $proposal, $version] = $this->fixture();
        $stale = $work->fresh();
        // A legacy projection captured before the registrar commits must
        // never replace the registrar's original current proof.
        $legacy = $stale->payload;
        unset($legacy['executor']);
        $stale->forceFill(['payload' => $legacy]);
        $feedback = app(SpecialistCouncilResearchFeedbackService::class);
        $registered = $feedback->registerFollowupProof($work->id, $proposal, 'original-registrar');
        $this->assertTrue($registered['executable']);
        $seal = $work->fresh()->payload['followup_resolution'];
        $kernel = app(\App\Services\ResearchExperimentConversionKernelService::class);
        $normalize = new \ReflectionMethod($kernel, 'normalizePersistedWork');
        $normalize->invoke($kernel, $stale);
        $this->assertSame($seal, $work->fresh()->payload['followup_resolution']);
        $this->assertSame($seal, $stale->payload['followup_resolution']);

        $redelivered = $feedback->recordAssessment($version->fresh());
        $this->assertSame($work->id, $redelivered['work_id']);
        $kernel->reconcileOwnershipAndDependencies();
        $current = $work->fresh();
        $this->assertSame($seal, $current->payload['followup_resolution']);
        $ready = $feedback->inspectFollowupReadiness($current);
        $this->assertTrue($ready['executable']);
        $this->assertSame($registered['resolution_hash'], $ready['resolution_hash']);
        $this->assertSame('original-registrar', $ready['registered_by']);
        $this->assertSame(0, $current->attempts);
        $this->assertNull($current->lease_token);
        $this->assertSame([], (array) $current->result);
        $this->assertDatabaseCount('research_experiment_work_items', 1);
        $this->assertDatabaseCount('lab_generations', 0);
    }

    public function test_rehashed_forged_work_proof_does_not_replace_server_registration(): void
    {
        [$work, $proposal] = $this->fixture();
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $service->registerFollowupProof($work->id, $proposal);
        $body = $work->fresh()->payload['followup_resolution'];
        $body['native_source_models']['hour']['parameters']['ema_fast'] = 6;
        $body['resolution_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash(
            array_diff_key($body, ['resolution_hash' => true, 'server_seal' => true]));
        $work->update(['payload' => [...$work->fresh()->payload, 'executable' => true, 'followup_resolution' => $body]]);
        $result = $service->inspectFollowupReadiness($work->fresh());
        $this->assertFalse($result['executable']);
        $this->assertSame('COUNCIL_FOLLOWUP_ORIGINAL_RESOLUTION_DRIFT', $result['reason']);
    }

    public function test_changed_original_native_model_invalidates_even_the_server_signed_resolution(): void
    {
        [$work, $proposal] = $this->fixture();
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $ready = $service->registerFollowupProof($work->id, $proposal);
        ModelVersion::findOrFail($ready['native_source_models']['hour']['model_version_id'])
            ->update(['parameters' => ['ema_fast' => 7, 'ema_slow' => 10]]);
        $result = $service->inspectFollowupReadiness($work->fresh());
        $this->assertFalse($result['executable']);
        $this->assertSame('COUNCIL_FOLLOWUP_ORIGINAL_NATIVE_MODEL_DRIFT', $result['reason']);
    }

    public function test_new_source_after_registration_cannot_mix_followup_evaluator_versions(): void
    {
        [$work, $proposal] = $this->fixture();
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $service->registerFollowupProof($work->id, $proposal);
        $this->partialMock(LabImmutableEvidenceService::class)->shouldReceive('codeHash')->andReturn(str_repeat('0', 64));
        $result = $service->inspectFollowupReadiness($work->fresh());
        $this->assertFalse($result['executable']);
        $this->assertSame('COUNCIL_FOLLOWUP_PREREGISTERED_SOURCE_CHANGED', $result['reason']);
    }

    public function test_actual_copied_source_byte_drift_refuses_original_signed_work_without_hash_mocks(): void
    {
        [$work, $proposal] = $this->fixture(realRuntimeHashes: true);
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $ready = $service->registerFollowupProof($work->id, $proposal);
        $evidence = app(LabImmutableEvidenceService::class);
        $releases = app(ResearchReleaseSealService::class);
        $originalBackend = base_path();
        $originalStorage = storage_path();
        $originalProject = dirname($originalBackend);
        $mirror = sys_get_temp_dir().'/council-source-guard-'.bin2hex(random_bytes(8));
        $directories = ['backend-laravel/app', 'backend-laravel/config', 'ai-service-python/app'];
        try {
            foreach ($directories as $directory) {
                File::ensureDirectoryExists($mirror.'/'.$directory);
                $this->assertTrue(File::copyDirectory($originalProject.'/'.$directory, $mirror.'/'.$directory));
            }
            foreach (['backend-laravel/composer.lock', 'backend-laravel/package-lock.json',
                'ai-service-python/requirements.txt', 'ai-service-python/pyproject.toml', 'ai-service-python/poetry.lock'] as $file) {
                if (is_file($originalProject.'/'.$file)) $this->assertTrue(File::copy($originalProject.'/'.$file, $mirror.'/'.$file));
            }
            // Only the source lookup moves. The original evidence storage and
            // SQLite app stay in place; shared App source is never changed.
            app()->setBasePath($mirror.'/backend-laravel');
            app()->useStoragePath($originalStorage);
            $this->assertSame($ready['current_source_hash'], $evidence->codeHash());
            $this->assertSame($ready['current_python_source_hash'], $releases->pythonHash());
            File::append($mirror.'/backend-laravel/app/Services/SpecialistCouncilResearchFeedbackService.php',
                "\n// isolated source-byte guard fixture\n");
            $this->assertNotSame($ready['current_source_hash'], $evidence->codeHash());
            $result = $service->inspectFollowupReadiness($work->fresh());
            $this->assertFalse($result['executable']);
            $this->assertSame('COUNCIL_FOLLOWUP_PREREGISTERED_SOURCE_CHANGED', $result['reason']);
            $this->assertSame($ready['resolution_hash'], data_get($work->fresh()->payload, 'followup_resolution.resolution_hash'));
        } finally {
            app()->setBasePath($originalBackend);
            app()->useStoragePath($originalStorage);
            $resolved = realpath($mirror);
            $allowed = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())).'/council-source-guard-';
            if ($resolved && str_starts_with(str_replace('\\', '/', $resolved), $allowed)) File::deleteDirectory($resolved);
        }
    }

    public function test_data_readiness_is_rechecked_after_successful_registration(): void
    {
        [$work, $proposal] = $this->fixture();
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $service->registerFollowupProof($work->id, $proposal);
        $this->mock(SpecialistCouncilPreparationService::class)->shouldReceive('assertProspectiveDiscoveryPlan')
            ->andThrow(new \LogicException('CANONICAL_COUNCIL_DISCOVERY_BUNDLE_NOT_READY:changed'));
        $result = $service->inspectFollowupReadiness($work->fresh());
        $this->assertFalse($result['executable']);
        $this->assertSame('CANONICAL_COUNCIL_DISCOVERY_BUNDLE_NOT_READY:changed', $result['reason']);
    }

    public function test_completed_and_exhausted_work_does_not_reopen_one_scientific_attempt(): void
    {
        [$work, $proposal] = $this->fixture();
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $service->registerFollowupProof($work->id, $proposal);
        $work->update(['attempts' => 8]);
        $this->assertSame('COUNCIL_FOLLOWUP_OPERATIONAL_LEASE_BUDGET_EXHAUSTED', $service->inspectFollowupReadiness($work->fresh())['reason']);
        $work->update(['status' => 'settled', 'completed_at' => now()]);
        $this->assertSame('COUNCIL_FOLLOWUP_ALREADY_COMPLETED', $service->inspectFollowupReadiness($work->fresh())['reason']);
    }

    public function test_caller_executable_or_authority_fields_are_not_accepted_as_preregistration(): void
    {
        [$work, $proposal] = $this->fixture();
        $proposal['executable'] = true;
        $proposal['independent_evidence_claimed'] = true;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_FOLLOWUP_PROSPECTIVE_CONTRACT_INVALID');
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal);
    }

    public function test_prospective_identity_limits_match_actual_native_constructor(): void
    {
        [$work, $proposal] = $this->fixture();
        foreach ([['research_question', str_repeat('x', 501)], ['creator_id', str_repeat('x', 121)],
            ['evaluator_id', ' padded-examiner '], ['research_question', ' padded question ']] as [$key, $value]) {
            try {
                app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, [...$proposal, $key => $value]);
                $this->fail('A contract the native owner cannot execute was accepted.');
            } catch (\LogicException $error) {
                $this->assertSame('COUNCIL_FOLLOWUP_PROSPECTIVE_CONTRACT_INVALID', $error->getMessage());
            }
        }
        $this->assertNull(data_get($work->fresh()->payload, 'followup_resolution'));
    }

    public function test_eighth_current_lease_can_finish_but_expired_or_ninth_lease_cannot_start_again(): void
    {
        [$work, $proposal] = $this->fixture();
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $service->registerFollowupProof($work->id, $proposal);
        $work->update(['status' => 'leased', 'attempts' => 8, 'lease_token' => 'current-token', 'lease_expires_at' => now()->addMinute()]);
        $this->assertTrue($service->inspectFollowupReadiness($work->fresh())['executable']);
        $work->update(['lease_expires_at' => now()->subMinute()]);
        $this->assertSame('COUNCIL_FOLLOWUP_OPERATIONAL_LEASE_BUDGET_EXHAUSTED', $service->inspectFollowupReadiness($work->fresh())['reason']);
        $work->update(['attempts' => 9, 'lease_expires_at' => now()->addMinute()]);
        $this->assertFalse($service->inspectFollowupReadiness($work->fresh())['executable']);
    }

    public function test_fresh_native_vector_cannot_bypass_original_edge_risk_mutation_firewall(): void
    {
        [$work, $proposal] = $this->fixture(true, 'data_missing', ['atr_stop_multiplier' => 1.0]);
        $proposal['parameter_deltas'] = ['hour' => ['atr_stop_multiplier' => 1.2]];
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('RISK_MUTATION_BEFORE_EDGE_CONFIRMATION');
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal);
    }

    public function test_exact_instrument_delta_veto_is_consulted_not_a_council_wide_harmful_ban(): void
    {
        [$work, $proposal] = $this->fixture();
        $this->mock(InstrumentPolicyConsumptionService::class)->shouldReceive('forbiddenDelta')->andReturnUsing(
            fn (array $policy, array $diff, array $parameters): bool => isset($diff['ema_fast']) && $parameters['ema_fast'] === 5);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('INSTRUMENT_EXACT_DELTA_FORBIDDEN');
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal);
    }

    public function test_unobserved_technical_repair_retains_same_question_and_requires_changed_source(): void
    {
        [$work, $proposal] = $this->fixture(true, 'technical_unassessable');
        $proposal['parameter_deltas'] = [];
        $ready = app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal);
        $this->assertTrue($ready['executable']);
        $this->assertSame('unobserved_same_question_source_repair', $ready['scientific_question_kind']);
        $this->assertFalse($ready['independent_evidence_claimed']);
    }

    public function test_new_technical_discovery_is_not_created_by_changing_only_question_text(): void
    {
        [$work, $proposal] = $this->fixture(true, 'technical_unassessable');
        $proposal['continuation_kind'] = 'new_discovery';
        $proposal['parameter_deltas'] = [];
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_FOLLOWUP_NEW_DISCOVERY_REQUIRES_NEW_PHYSICAL_QUESTION');
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal);
    }

    public function test_original_null_contract_schema_rejection_can_receive_one_unobserved_source_repair(): void
    {
        [$work, $proposal, $version] = $this->fixture(true, 'technical_unassessable', [], true);
        $proposal['parameter_deltas'] = [];
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $one = $service->registerFollowupProof($work->id, $proposal);
        $two = $service->registerFollowupProof($work->id, $proposal);
        $this->assertTrue($one['executable']);
        $this->assertSame($one['resolution_hash'], $two['resolution_hash']);
        $original = $one['original_unobserved_technical_proof'];
        $this->assertCount(1, $original['schema_rejected_arms']);
        $this->assertSame($version->assessment['original_run_ids'], $original['original_run_ids']);
        $this->assertCount(2, $original['undispatched_arm_keys']);
        $this->assertFalse($original['http_status_inferred']);
        $this->assertFalse($original['scientific_outcomes_observed']);
        $this->assertFalse($one['independent_evidence_claimed']);
        $this->assertSame('technical_error', LabEvaluationRun::sole()->status);
    }

    public function test_generic_transport_timeout_cannot_be_reclassified_as_unobserved_schema_failure(): void
    {
        [$work, $proposal] = $this->fixture(true, 'technical_unassessable', [], true, 'Replay timed out after 900 seconds');
        $proposal['parameter_deltas'] = [];
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_TECHNICAL_REPAIR_ORIGINAL_SCHEMA_REJECTION_NOT_PROVEN');
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal);
    }

    public function test_real_assignment_materialization_is_proven_only_for_one_new_unobserved_same_question_repair(): void
    {
        [$work, $proposal, $version, $models] = $this->fixture(true, 'technical_unassessable', [], true, null, true);
        $proposal['parameter_deltas'] = [];
        $originalManifest = $version->manifest_hash; $run = LabEvaluationRun::sole(); $responseHash = $run->response_hash;
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $ready = $service->registerFollowupProof($work->id, $proposal);
        $this->assertTrue($ready['executable'], $ready['reason'] ?? '');
        $proof = $ready['original_unobserved_technical_proof']['descriptor_materializations']['hour'];
        $this->assertSame('specialist_council_original_descriptor_materialization_v1', $proof['protocol']);
        $this->assertSame(app(SpecialistCouncilContractService::class)->modelHash($models['hour']->fresh()), $proof['current_model_hash']);
        $this->assertNotSame($proof['original_member_hash'], $proof['current_model_hash']);
        $this->assertSame($proof, $ready['native_source_models']['hour']['original_descriptor_materialization']);
        $this->assertSame($proof['current_model_hash'], $ready['native_source_models']['hour']['model_hash']);
        $this->assertSame($run->run_id, $proof['original_run_id']);
        $this->assertTrue($proof['original_preparation_remains_invalid']);
        $this->assertFalse($proof['scientific_outcomes_observed']);
        $this->assertFalse($ready['independent_evidence_claimed']);
        $this->assertSame($originalManifest, $version->fresh()->manifest_hash);
        $this->assertSame($responseHash, $run->fresh()->response_hash);
        $this->assertTrue($service->inspectFollowupReadiness($work->fresh())['executable']);
        $this->assertDatabaseCount('lab_evaluation_runs', 1);
    }

    public function test_assignment_materialization_numeric_projection_is_equivalent_but_never_an_excluded_hash_field(): void
    {
        [$work, $proposal, , $models] = $this->fixture(true, 'technical_unassessable', [], true, null, true);
        $proposal['parameter_deltas'] = [];
        $model = $models['hour']->fresh(); $metadata = $model->metadata;
        $metadata['instrument_research_assignment']['prior_value']['observations'] = 0.0;
        $model->update(['metadata' => $metadata]);
        $ready = app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal);
        $this->assertTrue($ready['executable'], $ready['reason'] ?? '');
        $proof = $ready['native_source_models']['hour']['original_descriptor_materialization'];
        $this->assertSame('verified_numerical_json_value', $proof['equivalence']);
        $this->assertSame(app(SpecialistCouncilContractService::class)->modelHash($model->fresh()), $ready['native_source_models']['hour']['model_hash']);
        $this->assertTrue($proof['original_preparation_remains_invalid']);
    }

    public function test_changed_assignment_after_materialization_proof_is_not_resealed_or_treated_as_original(): void
    {
        [$work, $proposal, , $models] = $this->fixture(true, 'technical_unassessable', [], true, null, true);
        $proposal['parameter_deltas'] = [];
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $ready = $service->registerFollowupProof($work->id, $proposal);
        $model = $models['hour']->fresh(); $metadata = $model->metadata;
        $metadata['instrument_research_assignment']['prior_value']['observations'] = 1;
        $model->update(['metadata' => $metadata]);
        $result = $service->inspectFollowupReadiness($work->fresh());
        $this->assertFalse($result['executable']);
        $this->assertSame('COUNCIL_TECHNICAL_REPAIR_ORIGINAL_DESCRIPTOR_MATERIALIZATION_NOT_PROVEN', $result['reason']);
        $this->assertSame($ready['resolution_hash'], data_get($work->fresh()->payload, 'followup_resolution.resolution_hash'));
    }

    public function test_assignment_without_original_request_witness_cannot_repair_member_model_drift(): void
    {
        [$work, $proposal, , $models] = $this->fixture(true, 'technical_unassessable', [], true);
        $proposal['parameter_deltas'] = [];
        app(LabInstrumentResearchService::class)->assignment(LabEvaluationRun::sole()->agent);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_TECHNICAL_REPAIR_ORIGINAL_DESCRIPTOR_MATERIALIZATION_NOT_PROVEN');
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal);
    }

    public function test_proven_assignment_does_not_hide_an_undispatched_original_carrier_change(): void
    {
        [$work, $proposal, , $models] = $this->fixture(true, 'technical_unassessable', [], true, null, true);
        $proposal['parameter_deltas'] = [];
        $models['ablation']->update(['parameters' => ['ema_fast' => 7, 'ema_slow' => 10]]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_TECHNICAL_REPAIR_ORIGINAL_NATIVE_MODEL_DRIFT');
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal);
    }

    public function test_proven_assignment_materialization_is_not_a_general_new_discovery_model_drift_exception(): void
    {
        [$work, $proposal] = $this->fixture(true, 'technical_unassessable', [], true, null, true);
        $proposal['continuation_kind'] = 'new_discovery';
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_FOLLOWUP_SOURCE_MUST_BE_ORIGINAL_NATIVE_MEMBER');
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal);
    }

    public function test_later_native_outcome_invalidates_preexecution_repair_without_rewriting_original(): void
    {
        [$work, $proposal, , $models] = $this->fixture(true, 'technical_unassessable', [], true);
        $proposal['parameter_deltas'] = [];
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $ready = $service->registerFollowupProof($work->id, $proposal);
        $failed = LabEvaluationRun::sole(); $failedHash = $failed->response_hash;
        LabEvaluationRun::create(['run_id' => 'later-source-scientific-outcome', 'model_version_id' => $models['scalp']->id,
            'phase' => 'screening', 'mode' => 'incremental', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now(),
            'request_hash' => str_repeat('a', 64), 'response_hash' => str_repeat('b', 64), 'code_hash' => str_repeat('e', 64)]);
        $result = $service->inspectFollowupReadiness($work->fresh());
        $this->assertFalse($result['executable']);
        $this->assertSame('COUNCIL_TECHNICAL_REPAIR_ORIGINAL_PREEXECUTION_PROOF_REQUIRED', $result['reason']);
        $this->assertSame($failedHash, $failed->fresh()->response_hash);
        $this->assertSame($ready['resolution_hash'], data_get($work->fresh()->payload, 'followup_resolution.resolution_hash'));
    }

    public function test_data_repair_requires_actual_changed_verified_data_not_renamed_question(): void
    {
        [$work, $proposal] = $this->fixture();
        $proposal['evaluation_plan']['windows'][0]['dataset_sha256'] = str_repeat('c', 64);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_DATA_REPAIR_REQUIRES_CHANGED_VERIFIED_DATA');
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal);
    }

    public function test_underpowered_extension_cannot_relabel_same_physical_event_scope(): void
    {
        [$work, $proposal] = $this->fixture(true, 'underpowered');
        $proposal['evaluation_plan']['windows'][0]['window_key'] = 'new-label-only';
        foreach ($proposal['evaluation_plan']['arms'] as &$arm) $arm['window_key'] = 'new-label-only';
        unset($arm);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_POWER_EXTENSION_REQUIRES_NEW_PROSPECTIVE_EVENT_SCOPE');
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal);
    }

    public function test_one_work_cannot_retry_a_failed_generation_or_own_a_second_cohort(): void
    {
        [$work, $proposal] = $this->fixture();
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $ready = $service->registerFollowupProof($work->id, $proposal);
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'name' => 'bounded original work',
            'strategy_families' => ['ema_rsi'], 'is_active' => false]);
        $fields = ['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'historical_research',
            'status' => 'failed', 'population_size' => 6, 'trigger_context' => ['native_specialist_council_intent' => [
                'followup_work_item_id' => $work->id, 'followup_resolution_hash' => $ready['resolution_hash']]]];
        $one = LabGeneration::create($fields);
        $this->assertSame('COUNCIL_FOLLOWUP_NEEDS_NEW_PREREGISTERED_ATTEMPT', $service->inspectFollowupReadiness($work->fresh())['reason']);
        $one->update(['status' => 'draft']);
        $this->assertSame($one->id, $service->inspectFollowupReadiness($work->fresh())['owned_generation_id']);
        LabGeneration::create([...$fields, 'generation' => 2]);
        $this->assertSame('COUNCIL_FOLLOWUP_MULTIPLE_COHORT_OWNERS', $service->inspectFollowupReadiness($work->fresh())['reason']);
    }

    public function test_out_of_range_or_coupled_invalid_parameter_delta_is_not_clamped(): void
    {
        [$work, $proposal] = $this->fixture();
        $proposal['parameter_deltas']['hour']['ema_fast'] = 15;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_FOLLOWUP_DELTA_REQUIRES_UNDECLARED_NORMALIZATION');
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal);
    }

    public function test_external_risk_and_capital_cannot_change_under_same_followup_proof(): void
    {
        [$work, $proposal] = $this->fixture();
        $proposal['evaluation_plan']['initial_capital'] = 100000;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_FOLLOWUP_EXTERNAL_COST_RISK_OR_CAPITAL_CHANGED');
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal);
    }

    public function test_data_owner_failure_rolls_back_registration_instead_of_waking_work(): void
    {
        [$work, $proposal] = $this->fixture(false);
        $this->mock(SpecialistCouncilPreparationService::class)->shouldReceive('assertProspectiveDiscoveryPlan')
            ->andThrow(new \LogicException('CANONICAL_COUNCIL_DISCOVERY_BUNDLE_NOT_READY:fixture'));
        try { app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal); }
        catch (\LogicException $error) { $this->assertStringContainsString('DISCOVERY_BUNDLE_NOT_READY', $error->getMessage()); }
        $this->assertNull(data_get($work->fresh()->payload, 'followup_resolution'));
        $this->assertFalse($work->fresh()->payload['executable']);
    }

    public function test_independent_and_descendant_claims_do_not_become_discovery_executable(): void
    {
        [$work] = $this->fixture();
        $work->update(['work_type' => 'specialist_council_independent_validation']);
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $this->assertSame('NO_COMPLETED_AUTHORIZED_INDEPENDENT_WINDOW', $service->inspectFollowupReadiness($work->fresh())['reason']);
        $work->update(['work_type' => 'specialist_council_descendant_transfer']);
        $this->assertSame('NO_COMPLETED_AUTHORIZED_INDEPENDENT_WINDOW', $service->inspectFollowupReadiness($work->fresh())['reason']);
        $this->assertNull(data_get($work->fresh()->payload, 'followup_resolution'));
    }

    public function test_source_amendment_appends_original_artifact_without_changing_resolution_or_cohort(): void
    {
        [$work, $generation, $body] = $this->sourceAmendmentFixture();
        $owner = app(SpecialistCouncilResearchFeedbackService::class);
        $beforeIds = $generation->agents()->pluck('id')->all();
        $one = $owner->amendUnobservedFollowupSource($work->id, $generation->id, 'repair-operator', 'Bounded original lease repair');
        $two = $owner->amendUnobservedFollowupSource($work->id, $generation->id, 'different-operator', 'Redelivery');
        $this->assertSame('registered', $one['status']);
        $this->assertSame('already_registered', $two['status']);
        $this->assertSame($one['amendment_hash'], $two['amendment_hash']);
        $this->assertSame($body, data_get($work->fresh()->payload, 'followup_resolution'));
        $this->assertSame($body['resolution_hash'], $one['resolution_hash']);
        $this->assertSame($beforeIds, $generation->agents()->pluck('id')->all());
        $this->assertSame(1, \App\Models\LabEvidenceArtifact::where('artifact_type', SpecialistCouncilResearchFeedbackService::SOURCE_AMENDMENT_PROTOCOL)->count());
        $ready = $owner->inspectFollowupReadiness($work->fresh());
        $this->assertTrue($ready['executable'], json_encode($ready));
        $this->assertSame(str_repeat('0', 64), $ready['effective_source_hash']);
        $this->assertSame(str_repeat('f', 64), $ready['current_source_hash']);
        $this->assertSame($one['amendment_hash'], $ready['operational_source_amendment_hash']);
        $this->assertFalse($ready['promotion_evidence']);
        $this->assertSame(6, data_get($generation->fresh()->trigger_context, 'native_specialist_council_intent.population_size'));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_source_amendment_public_cli_uses_original_owner_and_retains_checkpoint(): void
    {
        [$work, $generation, $body] = $this->sourceAmendmentFixture();
        $before = $work->result;
        $this->artisan('trading:specialist-council', ['action' => 'amend-followup-source', '--work-id' => $work->id,
            '--generation-id' => $generation->id, '--actor' => 'original-operator', '--reason' => 'Unobserved constructor lease repair'])
            ->assertExitCode(0);
        $this->assertSame($body, data_get($work->fresh()->payload, 'followup_resolution'));
        $this->assertSame($before, $work->fresh()->result);
        $this->assertNull($work->fresh()->lease_token);
        $this->assertSame(3, $work->fresh()->attempts);
    }

    public function test_source_amendment_refuses_any_original_replay_or_preparation(): void
    {
        [$work, $generation] = $this->sourceAmendmentFixture();
        LabEvaluationRun::create(['run_id' => 'original-first-replay', 'lab_generation_id' => $generation->id,
            'lab_agent_id' => $generation->agents()->first()->id, 'model_version_id' => $generation->agents()->first()->model_version_id,
            'phase' => 'screening', 'mode' => 'incremental', 'status' => 'started', 'attempt' => 1, 'started_at' => now()]);
        $this->expectExceptionMessage('COUNCIL_SOURCE_AMENDMENT_COHORT_ALREADY_OBSERVED');
        app(SpecialistCouncilResearchFeedbackService::class)->amendUnobservedFollowupSource($work->id, $generation->id, 'operator', 'Repair');
    }

    public function test_source_amendment_refuses_frozen_plan_and_constructed_vector_drift(): void
    {
        [$work, $generation] = $this->sourceAmendmentFixture();
        $context = $generation->trigger_context;
        data_set($context, 'generation_plan.4.niche.native_council_followup_source.parameters.ema_fast', 100);
        $generation->update(['trigger_context' => $context]);
        $this->expectExceptionMessage('COUNCIL_SOURCE_AMENDMENT_FROZEN_PLAN_DRIFT');
        app(SpecialistCouncilResearchFeedbackService::class)->amendUnobservedFollowupSource($work->id, $generation->id, 'operator', 'Repair');
    }

    public function test_source_amendment_refuses_constructed_physical_vector_drift(): void
    {
        [$work, $generation] = $this->sourceAmendmentFixture();
        $generation->agents()->first()->modelVersion->update(['parameters' => ['ema_fast' => 100, 'ema_slow' => 10]]);
        $this->expectExceptionMessage('COUNCIL_SOURCE_AMENDMENT_CONSTRUCTED_NATIVE_VECTOR_DRIFT');
        app(SpecialistCouncilResearchFeedbackService::class)->amendUnobservedFollowupSource($work->id, $generation->id, 'operator', 'Repair');
    }

    public function test_source_amendment_tampered_projection_or_archive_cannot_grant_readiness(): void
    {
        [$work, $generation] = $this->sourceAmendmentFixture();
        $owner = app(SpecialistCouncilResearchFeedbackService::class);
        $owner->amendUnobservedFollowupSource($work->id, $generation->id, 'operator', 'Repair');
        $payload = $work->fresh()->payload;
        $payload['followup_source_amendments'][0]['source_hash'] = str_repeat('9', 64);
        $work->update(['payload' => $payload]);
        $ready = $owner->inspectFollowupReadiness($work->fresh());
        $this->assertFalse($ready['executable']);
        $this->assertSame('COUNCIL_SOURCE_AMENDMENT_ORIGINAL_PROOF_INVALID', $ready['reason']);
    }

    public function test_source_amendment_live_lease_does_not_authorize_source_rewrite(): void
    {
        [$work, $generation] = $this->sourceAmendmentFixture();
        $work->update(['status' => 'leased', 'lease_token' => 'original-owner', 'lease_expires_at' => now()->addMinutes(45)]);
        $this->expectExceptionMessage('COUNCIL_SOURCE_AMENDMENT_REQUIRES_UNLEASED_OPERATIONAL_ATTEMPT');
        app(SpecialistCouncilResearchFeedbackService::class)->amendUnobservedFollowupSource($work->id, $generation->id, 'operator', 'Repair');
    }

    private function sourceAmendmentFixture(): array
    {
        [$work, $proposal] = $this->fixture();
        $owner = app(SpecialistCouncilResearchFeedbackService::class);
        $ready = $owner->registerFollowupProof($work->id, $proposal, 'original-registrar');
        $body = data_get($work->fresh()->payload, 'followup_resolution');
        $epochs = app(ResearchPaperEpochContractService::class);
        $intent = [...$ready['native_intent'], 'authority' => 'research_only', 'requires_atomic_preparation' => true,
            'independent_evidence_claimed' => false, 'promotion_evidence' => false];
        $intent['intent_hash'] = $epochs->parameterHash($intent);
        $roles = ['source_scalp', 'source_hour', 'source_day', 'source_swing', 'candidate_carrier', 'ablation_carrier'];
        $plan = [];
        foreach ($roles as $slot => $role) {
            $sourceRole = ['scalp', 'hour', 'day', 'swing', 'day', 'day'][$slot];
            $plan[] = ['family' => $body['native_source_models'][$sourceRole]['family'], 'origin' => 'native_council_root', 'target' => 'portfolio_router',
                'niche' => ['native_specialist_council_seed' => ['protocol' => \App\Services\LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL,
                    'intent_hash' => $intent['intent_hash'], 'slot_role' => $role, 'followup_work_item_id' => $work->id,
                    'followup_resolution_hash' => $body['resolution_hash']], 'native_council_followup_source' => $body['native_source_models'][$sourceRole]]];
        }
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'name' => 'unobserved native repair fixture',
            'strategy_families' => ['ema_rsi'], 'is_active' => true]);
        $generation = $lab->generations()->create(['generation' => 259, 'trigger_type' => 'historical_research',
            'status' => 'technical_quarantine', 'population_size' => 4,
            'trigger_context' => ['native_specialist_council_intent' => $intent, 'generation_plan' => $plan]]);
        foreach (array_slice($plan, 0, 4) as $slot => $definition) {
            $spec = $definition['niche']['native_council_followup_source'];
            $model = ModelVersion::create(['name' => 'native-unobserved-'.$slot, 'strategy' => $spec['strategy'], 'version' => 'v259',
                'generation' => 259, 'status' => 'testing', 'parameters' => $spec['parameters'],
                'metadata' => ['base_strategy' => $spec['base_strategy'], 'strategy_architecture' => $spec['strategy_architecture'],
                    'native_specialist_council_seed' => [...$definition['niche']['native_specialist_council_seed'], 'lab_generation_id' => $generation->id]]]);
            $generation->agents()->create(['model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'strategy_family' => $spec['family'], 'origin' => 'native_council_root', 'lifecycle_status' => 'technical_quarantine']);
        }
        $work->update(['status' => 'blocked', 'attempts' => 3, 'last_error' => 'COUNCIL_FOLLOWUP_LEASE_NOT_CURRENT',
            'result' => ['protocol' => \App\Services\SpecialistCouncilFollowupExecutionService::PROTOCOL, 'stage' => 'constructed',
                'generation_id' => $generation->id, 'resolution_hash' => $body['resolution_hash']]]);
        $this->partialMock(LabImmutableEvidenceService::class)->shouldReceive('codeHash')->andReturn(str_repeat('0', 64));
        $releases = app(ResearchReleaseSealService::class);
        $releases->shouldReceive('currentSourceArtifact')->andReturn(['protocol' => 'source_archive_fixture',
            'source_hash' => str_repeat('0', 64), 'python_source_hash' => str_repeat('a', 64)]);
        $releases->shouldReceive('verifySourceArtifact')->andReturn(['status' => 'verified']);
        return [$work->fresh(), $generation, $body];
    }

    /** Conditional original-owner unit: no actual worker/market/qualification is claimed. */
    public function test_observed_execution_snapshot_copies_only_the_original_declaration_to_a_new_discovery(): void
    {
        [$work, $proposal, $version, $models, $run, $declared, $observed] = $this->observedExecutionCopyFixture();
        $oldManifest = $version->manifest_hash; $oldResponse = $run->response_hash;
        $service = app(SpecialistCouncilResearchFeedbackService::class);
        $ready = $service->registerFollowupProof($work->id, $proposal);
        $this->assertTrue($ready['executable'], json_encode($ready));
        $this->assertSame('source_repair_completion_after_observed_auxiliary_source', $ready['scientific_question_kind']);
        $this->assertTrue($ready['same_physical_question_acknowledged']); $this->assertFalse($ready['scientific_novelty_claimed']);
        $proof = $ready['original_observed_source_proof']; $snapshot = $proof['descriptor_materializations']['scalp'];
        $this->assertTrue($proof['scientific_outcomes_observed']); $this->assertTrue($proof['observed_auxiliary_only']);
        $this->assertSame([], $proof['comparative_run_ids']); $this->assertFalse($ready['independent_evidence_claimed']);
        $this->assertNull($ready['original_unobserved_technical_proof']);
        $this->assertSame($run->run_id, $snapshot['original_run_id']);
        $this->assertSame($snapshot, $ready['native_source_models']['scalp']['original_descriptor_materialization']);
        $this->assertTrue(app(LabImmutableEvidenceService::class)->equivalentJsonValue($declared, $snapshot['original_value']));
        $this->assertNotSame($snapshot['original_member_hash'], $snapshot['current_model_hash']);
        $this->assertNull(app(LabImmutableEvidenceService::class)->verifiedModelRuntimeIdentity($run->fresh()));
        $population = (new \ReflectionClass(\App\Services\LabPopulationService::class))->newInstanceWithoutConstructor();
        $copy = (new \ReflectionMethod(\App\Services\LabPopulationService::class, 'nativeFollowupExecutionContract'))
            ->invoke($population, $ready['native_source_models']['scalp'], 'XAUUSD', 'M5');
        $this->assertSame($snapshot['original_value'], $copy);
        $bad = $ready['native_source_models']['scalp'];
        $bad['original_descriptor_materialization']['original_value']['counterfeit'] = true;
        try { (new \ReflectionMethod(\App\Services\LabPopulationService::class, 'nativeFollowupExecutionContract'))->invoke($population, $bad, 'XAUUSD', 'M5');
            $this->fail('Poisoned new-model copy value was accepted.'); }
        catch (\LogicException $error) { $this->assertSame('NATIVE_COUNCIL_OBSERVED_SOURCE_COPY_PROOF_INVALID', $error->getMessage()); }
        $this->assertTrue($service->inspectFollowupReadiness($work->fresh())['executable']);
        $this->assertSame($observed, data_get($models['scalp']->fresh()->metadata, 'execution_contract'));
        $this->assertSame($oldManifest, $version->fresh()->manifest_hash);
        $this->assertSame($oldResponse, $run->fresh()->response_hash);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('lab_evaluation_runs', 1); $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    private function unbuiltSourceAmendmentFixture(): array
    {
        [$work, $proposal, , $models, $run] = $this->observedExecutionCopyFixture(true);
        app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal);
        $body = data_get($work->fresh()->payload, 'followup_resolution');
        $oldArchive = app(ResearchReleaseSealService::class)->currentSourceArtifact();
        File::put($this->observedSourceFixtureRoots[0].'/backend-laravel/app/Example.php', '<?php return "cold constructor repair source";');
        $newArchive = app(ResearchReleaseSealService::class)->buildSourceArtifact()['reference'];
        $this->partialMock(LabImmutableEvidenceService::class)->shouldReceive('codeHash')->andReturn($newArchive['source_hash']);
        $hold = ['dependency_hold' => ['reason' => 'COUNCIL_SOURCE_AMENDMENT_REQUIRES_EXACT_UNOBSERVED_SIX_SEAT_INTENT',
            'prerequisite_hash' => str_repeat('9', 64), 'promotion_evidence' => false]];
        $work->update(['status' => 'blocked', 'attempts' => 1, 'last_error' => $hold['dependency_hold']['reason'], 'result' => $hold]);
        return [$work->fresh(), $body, $oldArchive, $newArchive, $models, $run];
    }

    public function test_unbuilt_source_amendment_verifies_actual_old_new_zips_and_preserves_the_original_question_and_hold(): void
    {
        [$work, $body, $oldArchive, $newArchive, $models, $run] = $this->unbuiltSourceAmendmentFixture();
        $before = $work->getAttributes(); $oldModel = $models['scalp']->fresh()->getAttributes(); $oldRun = $run->getAttributes();
        $owner = app(SpecialistCouncilResearchFeedbackService::class);
        $one = $owner->amendUnbuiltFollowupSource($work->id, 'cold-repair-operator', 'Repair the pristine target constructor owner');
        $this->assertSame('registered', $one['status']); $this->assertNull($one['generation_id']);
        $current = $work->fresh(); $entry = data_get($current->payload, 'followup_source_amendments.0');
        $this->assertSame($body, data_get($current->payload, 'followup_resolution'));
        $this->assertSame($oldArchive, $entry['original_source_artifact']); $this->assertSame($newArchive, $entry['source_artifact']);
        $this->assertSame('bounded_pristine_unbuilt_native_source_repair', $entry['operation']);
        $this->assertNull($entry['generation_id']); $this->assertTrue($entry['pristine_target_snapshot']['target_unbuilt']);
        $this->assertTrue($entry['pristine_target_snapshot']['original_auxiliary_outcome_still_observed']);
        foreach (['result', 'attempts', 'last_error', 'work_key', 'status', 'research_experiment_receipt_id'] as $key) {
            $this->assertSame($before[$key], $current->getAttributes()[$key], $key);
        }
        $ready = $owner->inspectFollowupReadiness($current);
        $this->assertTrue($ready['executable'], json_encode($ready));
        $this->assertSame($newArchive['source_hash'], $ready['effective_source_hash']);
        $this->assertSame($body['current_source_hash'], $ready['current_source_hash']);
        $this->assertSame('already_registered', $owner->amendUnbuiltFollowupSource($work->id, 'another-operator', 'Redelivery')['status']);
        $this->assertCount(1, data_get($work->fresh()->payload, 'followup_source_amendments'));
        $this->assertSame($oldModel, $models['scalp']->fresh()->getAttributes()); $this->assertSame($oldRun, $run->fresh()->getAttributes());
        $this->assertDatabaseCount('lab_generations', 1); $this->assertDatabaseCount('lab_evaluation_runs', 1);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0); $this->assertFalse($entry['new_scientific_attempt']);
        $this->assertFalse($entry['promotion_evidence']); $this->assertFalse($entry['independent_evidence_claimed']);
    }

    public static function unbuiltAmendmentPoisons(): array
    {
        return array_map(fn ($value) => [$value], ['owned_generation', 'model_marker', 'resolution_generation', 'resolution_model_marker',
            'target_artifact', 'target_followup_run', 'target_panel_run', 'contradictory_checkpoint',
            'gene_rewrite', 'body_rewrite', 'original_source_drift', 'missing_old_archive', 'tampered_old_archive', 'same_source', 'duplicate_old_reference', 'live_lease']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unbuiltAmendmentPoisons')]
    public function test_unbuilt_source_amendment_refuses_rewritten_question_owned_targets_and_unverified_archives(string $poison): void
    {
        [$work, $body, $oldArchive] = $this->unbuiltSourceAmendmentFixture();
        $expected = 'COUNCIL_SOURCE_AMENDMENT_REQUIRES_PRISTINE_UNBUILT_TARGET';
        if ($poison === 'owned_generation') {
            LabGeneration::create(['ai_laboratory_id' => AiLaboratory::sole()->id, 'generation' => 2, 'status' => 'draft',
                'trigger_context' => ['native_specialist_council_intent' => ['followup_work_item_id' => $work->id]]]);
        } elseif ($poison === 'resolution_generation') {
            LabGeneration::create(['ai_laboratory_id' => AiLaboratory::sole()->id, 'generation' => 2, 'status' => 'draft',
                'trigger_context' => ['native_specialist_council_intent' => ['followup_resolution_hash' => $body['resolution_hash']]]]);
        } elseif ($poison === 'model_marker') {
            $model = ModelVersion::first(); $model->update(['metadata' => [...$model->metadata, 'native_specialist_council_seed' => ['followup_work_item_id' => $work->id]]]);
        } elseif ($poison === 'resolution_model_marker') {
            $model = ModelVersion::first(); $model->update(['metadata' => [...$model->metadata, 'native_specialist_council_seed' => ['followup_resolution_hash' => $body['resolution_hash']]]]);
        } elseif ($poison === 'target_artifact') {
            app(LabImmutableEvidenceService::class)->recordArtifact(null, 'constructor_target_marker', ['diagnostic_only' => true], ['work_item_id' => $work->id]);
        } elseif (in_array($poison, ['target_followup_run', 'target_panel_run'], true)) {
            LabEvaluationRun::create(['run_id' => 'poison-target-'.bin2hex(random_bytes(4)), 'phase' => 'screening', 'mode' => 'incremental',
                'status' => 'started', 'metadata' => $poison === 'target_followup_run'
                    ? ['followup_work_item_id' => $work->id] : ['council_panel' => ['work_item_id' => $work->id]]]);
        } elseif ($poison === 'contradictory_checkpoint') $work->update(['result' => [...$work->result, 'generation_id' => 999]]);
        elseif (in_array($poison, ['gene_rewrite', 'body_rewrite'], true)) {
            $payload = $work->payload;
            if ($poison === 'gene_rewrite') data_set($payload, 'followup_resolution.native_source_models.hour.parameters.ema_fast', 5);
            else data_set($payload, 'followup_resolution.research_question', 'Caller retuned question');
            $work->update(['payload' => $payload]); $expected = 'COUNCIL_FOLLOWUP_ORIGINAL_RESOLUTION_DRIFT';
        } elseif ($poison === 'original_source_drift') {
            $model = ModelVersion::first(); $model->update(['parameters' => [...$model->parameters, 'ema_fast' => 5]]);
            $expected = 'COUNCIL_SOURCE_AMENDMENT_ORIGINAL_NATIVE_SOURCE_DRIFT';
        } elseif (in_array($poison, ['missing_old_archive', 'tampered_old_archive'], true)) {
            $path = $this->observedSourceFixtureRoots[0].'/'.$oldArchive['archive_path'];
            if ($poison === 'missing_old_archive') File::delete($path); else File::put($path, 'counterfeit zip');
            $expected = $poison === 'missing_old_archive' ? 'SOURCE_ARTIFACT_ARCHIVE_MISSING' : 'SOURCE_ARTIFACT_ARCHIVE_HASH_MISMATCH';
        } elseif ($poison === 'same_source') {
            $this->partialMock(LabImmutableEvidenceService::class)->shouldReceive('codeHash')->andReturn($body['current_source_hash']);
            $expected = 'COUNCIL_SOURCE_AMENDMENT_REQUIRES_BOUNDED_CHANGED_VERIFIED_SOURCE';
        } elseif ($poison === 'duplicate_old_reference') {
            $root = $this->observedSourceFixtureRoots[0];
            File::put($root.'/backend-laravel/app/Example.php', '<?php return "registered cold source";');
            config(['services.research_paper_epochs.authorized_paper_epochs' => [['authorization_id' => 'archive metadata only']]]);
            app(ResearchReleaseSealService::class)->buildSourceArtifact();
            $expected = 'COUNCIL_SOURCE_AMENDMENT_ORIGINAL_ARCHIVE_AMBIGUOUS';
        } elseif ($poison === 'live_lease') {
            $work->update(['status' => 'leased', 'lease_token' => 'original-owner', 'lease_expires_at' => now()->addMinutes(45)]);
            $expected = 'COUNCIL_SOURCE_AMENDMENT_REQUIRES_UNLEASED_OPERATIONAL_ATTEMPT';
        }
        $this->expectExceptionMessage($expected);
        app(SpecialistCouncilResearchFeedbackService::class)->amendUnbuiltFollowupSource($work->id, 'operator', 'Repair');
    }

    public function test_unbuilt_source_amendment_retains_the_three_entry_bound_without_renewing_work_budget(): void
    {
        [$work, $body] = $this->unbuiltSourceAmendmentFixture();
        $owner = app(SpecialistCouncilResearchFeedbackService::class);
        for ($entry = 1; $entry <= 4; $entry++) {
            if ($entry > 1) {
                File::put($this->observedSourceFixtureRoots[0].'/backend-laravel/app/Example.php', '<?php return "cold repair '.$entry.'";');
                $reference = app(ResearchReleaseSealService::class)->buildSourceArtifact()['reference'];
                $this->partialMock(LabImmutableEvidenceService::class)->shouldReceive('codeHash')->andReturn($reference['source_hash']);
            }
            if ($entry === 4) {
                $this->expectExceptionMessage('COUNCIL_SOURCE_AMENDMENT_REQUIRES_BOUNDED_CHANGED_VERIFIED_SOURCE');
                $this->assertCount(3, data_get($work->fresh()->payload, 'followup_source_amendments'));
                $this->assertSame(1, $work->fresh()->attempts);
                $this->assertSame($body, data_get($work->fresh()->payload, 'followup_resolution'));
            }
            $owner->amendUnbuiltFollowupSource($work->id, 'operator', 'Bounded cold source repair '.$entry);
        }
    }

    public function test_unbuilt_source_amendment_poisoned_pristine_snapshot_cannot_grant_fresh_binding(): void
    {
        [$work] = $this->unbuiltSourceAmendmentFixture();
        $owner = app(SpecialistCouncilResearchFeedbackService::class);
        $owner->amendUnbuiltFollowupSource($work->id, 'operator', 'Pristine cold source repair');
        $payload = $work->fresh()->payload;
        $payload['followup_source_amendments'][0]['pristine_target_snapshot']['owned_generations'] = 1;
        $work->update(['payload' => $payload]);
        $this->expectExceptionMessage('COUNCIL_SOURCE_AMENDMENT_ORIGINAL_PROOF_INVALID');
        $owner->inspectFollowupSourceBinding($work->fresh());
    }

    public static function coldFollowupStarts(): array { return [[false], [true]]; }

    /** Real public constructor; original worker evidence is explicitly conditional, not market proof. */
    #[\PHPUnit\Framework\Attributes\DataProvider('coldFollowupStarts')]
    public function test_public_cold_followup_build_warms_proof_before_fresh_row_and_retains_nonconstructive_hold(bool $amended): void
    {
        $owner = app(SpecialistCouncilResearchFeedbackService::class);
        if ($amended) {
            [$work, $body, , , $models, $run] = $this->unbuiltSourceAmendmentFixture();
            $owner->amendUnbuiltFollowupSource($work->id, 'operator', 'Pristine cold constructor source repair');
            $ready = $owner->inspectFollowupReadiness($work->fresh());
            $declared = data_get($body, 'native_source_models.scalp.original_descriptor_materialization.original_value');
            $observed = data_get($models['scalp']->fresh()->metadata, 'execution_contract');
        } else {
            [$work, $proposal, , $models, $run, $declared, $observed] = $this->observedExecutionCopyFixture();
            $ready = $owner->registerFollowupProof($work->id, $proposal);
        }
        $oldModel = $models['scalp']->fresh()->getAttributes(); $oldRun = $run->fresh()->getAttributes();
        $hold = ['dependency_hold' => ['reason' => 'COUNCIL_SOURCE_AMENDMENT_REQUIRES_EXACT_UNOBSERVED_SIX_SEAT_INTENT',
            'prerequisite_hash' => str_repeat('9', 64), 'promotion_evidence' => false]];
        $work->update(['status' => 'blocked', 'attempts' => 1, 'last_error' => $hold['dependency_hold']['reason'], 'result' => $hold]);
        $lab = $run->agent->generation->laboratory;
        $lab->update(['is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $run->agent->generation->update(['status' => 'completed', 'completed_at' => now()]);
        config(['services.xauusd_organism.historical_research_until_champion' => true,
            'services.market_data.provider' => 'csv', 'services.lab_selection.constructor_initial_seat_budget' => 4]);
        app(\App\Services\AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'Conditional cold constructor admission');
        $this->mock(\App\Services\LearningVelocityGateService::class)->shouldReceive('inspect')->andReturn(['status' => 'healthy', 'allowed' => true]);
        $this->mock(\App\Services\LabDatasetExportService::class)->shouldReceive('ensureFoundationDataset')->andReturn([
            'sha256' => str_repeat('a', 64), 'path' => 'conditional-foundation-fixture.csv',
            'manifest' => ['row_count' => 123000, 'first_candle_at' => '2005-01-03T00:00:00Z', 'last_candle_at' => '2025-12-31T23:00:00Z']]);
        $lease = app(\App\Services\ResearchExperimentConversionKernelService::class)
            ->claimForOwner(\App\Services\ResearchLoopArbiterService::class, 1)[0] ?? null;
        $this->assertNotNull($lease); $this->assertSame($work->id, $lease->id);
        $createdRows = [];
        ModelVersion::creating(function ($model) use ($work) {
            if (data_get($model->metadata, 'native_specialist_council_seed.followup_work_item_id') === $work->id) {
                $generation = LabGeneration::find(data_get($model->metadata, 'native_specialist_council_seed.lab_generation_id'));
                $this->assertCount(6, data_get($generation->trigger_context, 'generation_plan'), 'Full frozen plan must precede every model persistence.');
            }
        });
        LabAgent::created(function ($agent) use (&$createdRows) {
            $model = $agent->modelVersion;
            $createdRows[] = ['agent' => $agent->getAttributes(), 'parameters' => $model->parameters,
                'seed' => data_get($model->metadata, 'native_specialist_council_seed'),
                'base_strategy' => data_get($model->metadata, 'base_strategy'), 'architecture' => data_get($model->metadata, 'strategy_architecture')];
        });
        $population = app(\App\Services\LabPopulationService::class);
        $generation = $population->build('XAUUSD', 'historical_research', false, 'H1', [], false,
            false, null, null, false, null, $ready['native_intent']);
        $this->assertNotNull($generation, json_encode($population->lastBuildOutcome()));
        $this->assertCount(6, data_get($generation->trigger_context, 'generation_plan'));
        $this->assertSame(4, $generation->agents()->count(), json_encode(['audit' => data_get($generation->fresh()->trigger_context, 'constructor_audit'), 'created' => $createdRows]));
        $firstIds = $generation->agents()->orderBy('id')->pluck('id')->all();
        $copy = $generation->agents()->with('modelVersion')->first()->modelVersion;
        $this->assertTrue(app(LabImmutableEvidenceService::class)->equivalentJsonValue($declared, data_get($copy->metadata, 'execution_contract')));
        $this->assertSame(data_get($ready, 'native_source_models.scalp.original_descriptor_materialization.original_declared_execution_hash'),
            app(ResearchPaperEpochContractService::class)->parameterHash(data_get($copy->metadata, 'execution_contract')));
        $this->assertSame(['source_scalp', 'source_hour', 'source_day', 'source_swing'], $generation->agents()->with('modelVersion')->orderBy('id')->get()
            ->map(fn ($agent) => data_get($agent->modelVersion->metadata, 'native_specialist_council_seed.slot_role'))->all());
        $this->assertNotSame($models['scalp']->id, $copy->id);
        $this->assertSame($hold, $work->fresh()->result);
        // Invoke the existing fenced checkpoint owner, never synthesize a scientific result.
        (new \ReflectionMethod(\App\Services\SpecialistCouncilFollowupExecutionService::class, 'checkpoint'))
            ->invoke(app(\App\Services\SpecialistCouncilFollowupExecutionService::class), $lease, $generation, $ready, 'constructed');
        $continuation = $population->continueInterruptedConstruction($generation->id, 2);
        $this->assertSame([], $continuation['failures'] ?? null, json_encode($continuation));
        $this->assertCount(2, $continuation['created_slots'] ?? []);
        $this->assertSame(6, $generation->agents()->count());
        $this->assertSame($firstIds, $generation->agents()->orderBy('id')->limit(4)->pluck('id')->all());
        $this->assertSame($hold['dependency_hold'], data_get($work->fresh()->result, 'dependency_hold'));
        $this->assertSame($oldModel, $models['scalp']->fresh()->getAttributes());
        $this->assertSame($oldRun, $run->fresh()->getAttributes());
        $this->assertSame($observed, data_get($models['scalp']->fresh()->metadata, 'execution_contract'));
        $this->assertDatabaseCount('lab_evaluation_runs', 1); $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertFalse($ready['promotion_evidence']); $this->assertFalse($ready['independent_evidence_claimed']);
    }

    public static function coldPlanPoisons(): array { return [['empty'], ['lost_slot'], ['contradictory_checkpoint']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('coldPlanPoisons')]
    public function test_cold_start_witness_never_waives_an_empty_lost_plan_or_contradictory_checkpoint(string $poison): void
    {
        [$work, $generation] = $this->sourceAmendmentFixture();
        // This existing constructed target cannot obtain a pristine-start witness.
        try { app(SpecialistCouncilResearchFeedbackService::class)->pristineUnbuiltFollowupSnapshot($work);
            $this->fail('An owned constructor was treated as pristine.'); }
        catch (\LogicException $error) { $this->assertSame('COUNCIL_SOURCE_AMENDMENT_REQUIRES_PRISTINE_UNBUILT_TARGET', $error->getMessage()); }
        if ($poison === 'contradictory_checkpoint') $work->update(['result' => [...$work->result, 'generation_id' => 999]]);
        else {
            $context = $generation->trigger_context;
            $context['generation_plan'] = $poison === 'empty' ? [] : array_slice($context['generation_plan'], 0, 5);
            $generation->update(['trigger_context' => $context]);
        }
        $this->expectExceptionMessage('COUNCIL_SOURCE_AMENDMENT_REQUIRES_EXACT_UNOBSERVED_SIX_SEAT_INTENT');
        app(SpecialistCouncilResearchFeedbackService::class)->assertUnobservedConstructorBinding($work->fresh(), $generation->id);
    }

    /** Conditional constructor checkpoint owner; never a completed native cohort/worker claim. */
    public function test_observed_execution_snapshot_continuation_refuses_a_changed_new_model_without_rewriting_old_source(): void
    {
        [$work, $proposal, , $models, $run] = $this->observedExecutionCopyFixture();
        $owner = app(SpecialistCouncilResearchFeedbackService::class); $ready = $owner->registerFollowupProof($work->id, $proposal);
        $snapshot = $ready['native_source_models']['scalp']['original_descriptor_materialization'];
        $old = $models['scalp']->fresh()->getAttributes(); $originalResponse = $run->response_hash;
        $originalGeneration = $run->agent->generation;
        $generation = LabGeneration::create(['ai_laboratory_id' => $originalGeneration->ai_laboratory_id, 'generation' => 2, 'status' => 'creating',
            'trigger_context' => ['native_specialist_council_intent' => ['followup_work_item_id' => $work->id, 'followup_resolution_hash' => $ready['resolution_hash']]]]);
        $copy = ModelVersion::create(['name' => 'conditional copied slot checkpoint', 'strategy' => $models['scalp']->strategy, 'version' => 'v2',
            'parameters' => $ready['native_source_models']['scalp']['parameters'], 'status' => 'testing', 'metadata' => [
                'execution_contract' => $snapshot['original_value'], 'original_source_execution_snapshot' => $snapshot,
                'native_specialist_council_seed' => ['slot_role' => 'source_scalp']]]);
        LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $copy->id, 'origin' => 'native_council_root',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi', 'lifecycle_status' => 'draft']);
        $this->assertTrue($owner->inspectFollowupReadiness($work->fresh())['executable']);
        $copy->update(['metadata' => [...$copy->metadata, 'execution_contract' => ['changed' => 'not-original-declaration']]]);
        $blocked = $owner->inspectFollowupReadiness($work->fresh());
        $this->assertFalse($blocked['executable']); $this->assertSame('COUNCIL_OBSERVED_SOURCE_CONSTRUCTED_DECLARATION_DRIFT', $blocked['reason']);
        $this->assertSame($ready['resolution_hash'], data_get($work->fresh()->payload, 'followup_resolution.resolution_hash'));
        $this->assertSame($old, $models['scalp']->fresh()->getAttributes()); $this->assertSame($originalResponse, $run->fresh()->response_hash);
        $this->assertDatabaseCount('lab_evaluation_runs', 1); $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public static function observedExecutionCopyPoisons(): array
    {
        return array_map(fn ($value) => [$value], ['missing_identity', 'poisoned_identity', 'wrong_owner', 'changed_parameter',
            'extra_basis_drift', 'wrong_observation', 'wrong_request_declaration', 'wrong_original_hash', 'missing_archive', 'caller_retuning',
            'unchanged_source', 'comparative_run']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('observedExecutionCopyPoisons')]
    public function test_observed_execution_snapshot_refuses_missing_poisoned_or_retuned_originals(string $poison): void
    {
        [$work, $proposal, , $models, $run] = $this->observedExecutionCopyFixture();
        $model = $models['scalp']->fresh(); $before = $model->getAttributes();
        if ($poison === 'missing_identity') \App\Models\LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'model_runtime_identity')->delete();
        elseif ($poison === 'poisoned_identity') {
            $artifact = \App\Models\LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'model_runtime_identity')->sole();
            $artifact->update(['sha256' => str_repeat('0', 64)]);
        } elseif ($poison === 'wrong_owner') {
            $original = $run->agent->generation;
            $other = LabGeneration::create(['ai_laboratory_id' => $original->ai_laboratory_id, 'generation' => 2, 'status' => 'completed']);
            $run->update(['lab_generation_id' => $other->id]);
        }
        elseif ($poison === 'changed_parameter') $model->update(['parameters' => [...$model->parameters, 'ema_fast' => 5]]);
        elseif ($poison === 'extra_basis_drift') $model->update(['metadata' => [...$model->metadata, 'risk_governor' => ['extra' => 'not-original']]]);
        elseif ($poison === 'wrong_observation') $model->update(['metadata' => [...$model->metadata, 'execution_contract' => ['counterfeit' => 'not-original-response']]]);
        elseif ($poison === 'wrong_request_declaration') {
            $artifact = \App\Models\LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')->sole();
            $artifact->update(['metadata' => [...$artifact->metadata, 'request_hash' => str_repeat('0', 64)]]);
        } elseif ($poison === 'wrong_original_hash') {
            $identity = \App\Models\LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'model_runtime_identity')->sole();
            $payload = app(LabImmutableEvidenceService::class)->readArtifactPayload($identity);
            $payload['runtime_basis']['components']['execution_contract'] = ['wrong' => 'old-declaration'];
            $path = storage_path('app/'.$identity->storage_path); $bytes = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            File::put($path, gzencode($bytes)); $identity->update(['sha256' => hash('sha256', $bytes)]);
        } elseif ($poison === 'missing_archive') {
            $request = app(LabImmutableEvidenceService::class)->latestArtifactPayload($run, 'evaluation_request');
            $archive = $this->observedSourceFixtureRoots[0].'/'.$request['research_release']['source_artifact']['archive_path'];
            File::delete($archive);
        } elseif ($poison === 'caller_retuning') $proposal['parameter_deltas'] = ['hour' => ['ema_fast' => 5]];
        elseif ($poison === 'unchanged_source') $this->partialMock(LabImmutableEvidenceService::class)->shouldReceive('codeHash')->andReturn($run->code_hash);
        elseif ($poison === 'comparative_run') {
            LabEvaluationRun::create(['run_id' => 'conditional-forbidden-comparative-run', 'model_version_id' => $models['candidate']->id,
                'phase' => 'full_validation', 'mode' => 'full', 'status' => 'started', 'started_at' => now(),
                'code_hash' => $run->code_hash, 'data_hash' => $run->data_hash]);
        }
        try {
            app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal);
            $this->fail('Missing, poisoned, additional-drift or caller-retuned source was accepted.');
        } catch (\LogicException|\RuntimeException $error) { $this->assertNotSame('', $error->getMessage()); }
        $this->assertNull(data_get($work->fresh()->payload, 'followup_resolution'));
        $this->assertDatabaseCount('lab_evaluation_runs', $poison === 'comparative_run' ? 2 : 1); $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        if (! in_array($poison, ['changed_parameter', 'extra_basis_drift', 'wrong_observation'])) $this->assertSame($before, $model->fresh()->getAttributes());
    }

    /** Actual file/archive/owner validators; worker observation is explicitly conditional unit input. */
    private function observedExecutionCopyFixture(bool $archiveProspectiveSource = false): array
    {
        $root = sys_get_temp_dir().'/observed-source-snapshot-'.bin2hex(random_bytes(8)); $this->observedSourceFixtureRoots[] = $root;
        $this->observedOriginalStorage = storage_path(); app()->useStoragePath($root.'/storage');
        foreach (['backend-laravel/app/Example.php' => '<?php return "original snapshot";', 'backend-laravel/config/example.php' => '<?php return [];',
            'backend-laravel/composer.json' => '{}', 'backend-laravel/composer.lock' => '{}', 'backend-laravel/package-lock.json' => '{}',
            'ai-service-python/requirements.txt' => 'pandas==2.2.3', 'ai-service-python/app/main.py' => '# conditional snapshot archive unit'] as $path => $bytes) {
            File::ensureDirectoryExists(dirname($root.'/'.$path)); File::put($root.'/'.$path, $bytes);
        }
        $archiveOwner = new FixtureResearchSourceArtifactOwner($root); $reference = $archiveOwner->buildSourceArtifact()['reference'];
        $declared = app(\App\Services\ExecutionContractService::class)->for('XAUUSD', 'M5');
        [$work, $proposal, $version, $models] = $this->fixture(true, 'technical_unassessable', [], false, null, false, false,
            $reference['source_hash'], ['scalp' => $declared], true);
        app()->instance(ResearchReleaseSealService::class, $archiveOwner);
        $proposal['continuation_kind'] = 'new_discovery'; $proposal['parameter_deltas'] = [];
        $lab = AiLaboratory::create(['name' => 'conditional observed source owner unit', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['ema_rsi']]);
        $intentHash = hash('sha256', 'conditional actual constructor owner identity');
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'screening',
            'trigger_context' => ['native_specialist_council_intent' => ['intent_hash' => $intentHash]]]);
        $model = $models['scalp']->fresh();
        $model->update(['metadata' => [...$model->metadata, 'native_specialist_council_seed' => [
            'protocol' => \App\Services\LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL, 'slot_role' => 'source_scalp',
            'lab_generation_id' => (int) $generation->id, 'intent_hash' => $intentHash]]]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi', 'origin' => 'native_council_root', 'lifecycle_status' => 'screened']);
        $model->refresh(); $declared = data_get($model->metadata, 'execution_contract');
        $evidence = app(LabImmutableEvidenceService::class);
        $run = LabEvaluationRun::create(['run_id' => 'conditional-observed-'.bin2hex(random_bytes(8)), 'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id, 'model_version_id' => $model->id, 'phase' => 'screening', 'mode' => 'incremental',
            'status' => 'started', 'started_at' => now(), 'code_hash' => $reference['source_hash'], 'data_hash' => str_repeat('c', 64),
            'parameter_hash' => $evidence->parameterHash($agent), 'metadata' => ['source' => 'bounded_screening_batch']]);
        $identity = ['protocol' => ResearchReleaseSealService::PROTOCOL, 'source_hash' => $reference['source_hash'], 'python_source_hash' => $reference['python_source_hash'],
            'php_version' => PHP_VERSION, 'dataset_hash' => $run->data_hash, 'execution_hash' => str_repeat('d', 64), 'cost_model_protocol' => 'conditional-original-unit',
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'agent_execution_hashes' => [], 'source_artifact' => $reference];
        $release = [...$identity, 'release_hash' => app(\App\Services\ExecutionContractService::class)->hashParameters($identity),
            'sealed_at' => now()->toIso8601String(), 'promotion_evidence' => false];
        $request = ['research_release' => $release, 'replay_dataset_hash' => $run->data_hash, 'execution_contract' => $declared,
            'strategies' => [['lab_agent_id' => (int) $agent->id, 'strategy' => $model->strategy, 'version' => $model->version, 'parameters' => $model->parameters]]];
        $evidence->attachRequest($run, $request);
        $observed = [...$declared, 'conditional_observation_not_market_proof' => true];
        $response = ['execution_contract' => $observed, 'total_trades' => 0, 'data_quality' => ['research_release_receipt' => [
            'protocol' => 'research_worker_release_receipt_v1', 'loaded_code_attested' => true, 'release_hash' => $release['release_hash'],
            'source_hash' => $release['python_source_hash'], 'boot_source_hash' => $release['python_source_hash']]]];
        $evidence->finishRun($run, 'completed', $response, [], ['source_snapshot_unit_not_worker_or_market_proof' => true]);
        $model->update(['metadata' => [...$model->fresh()->metadata, 'execution_contract' => $observed]]);
        if ($archiveProspectiveSource) {
            File::put($root.'/backend-laravel/app/Example.php', '<?php return "registered cold source";');
            $prospective = $archiveOwner->buildSourceArtifact()['reference'];
            $this->partialMock(LabImmutableEvidenceService::class)->shouldReceive('codeHash')->andReturn($prospective['source_hash']);
        }
        return [$work, $proposal, $version, $models, $run->fresh(), $declared, $observed];
    }

    private function fixture(bool $ready = true, string $observation = 'data_missing', array $extraParameters = [],
        bool $schemaFailure = false, ?string $errorMessage = null, bool $materializeAssignment = false,
        bool $realRuntimeHashes = false, ?string $originalSourceHash = null, array $sourceExecutionDeclarations = [], bool $canonicalBaseStrategy = false): array
    {
        config(['services.internal_api.token' => str_repeat('fixture-key-', 4)]);
        if (! $realRuntimeHashes) {
            $this->partialMock(LabImmutableEvidenceService::class)->shouldReceive('codeHash')->andReturn(str_repeat('f', 64));
            $this->partialMock(ResearchReleaseSealService::class)->shouldReceive('pythonHash')->andReturn(str_repeat('a', 64));
        }
        if ($ready) $this->mock(SpecialistCouncilPreparationService::class)->shouldReceive('assertProspectiveDiscoveryPlan')->andReturnNull();
        $models = [];
        foreach (['scalp', 'hour', 'day', 'swing', 'candidate', 'ablation'] as $role) {
            $models[$role] = ModelVersion::create(['name' => 'Synthetic '.$role, 'strategy' => 'ema_rsi_v1', 'version' => 'v1',
                'generation' => 1, 'status' => 'testing', 'parameters' => ['ema_fast' => 4, 'ema_slow' => 10, ...$extraParameters],
                'metadata' => ['base_strategy' => $canonicalBaseStrategy ? 'ema_rsi_v1' : 'ema_rsi', 'strategy_architecture' => 'ema_rsi',
                    ...(isset($sourceExecutionDeclarations[$role]) ? ['execution_contract' => $sourceExecutionDeclarations[$role]] : [])]]);
        }
        $members = [];
        foreach (['scalp', 'hour', 'day', 'swing'] as $role) {
            $members[] = ['specialist_id' => $role.'-owner', 'role' => $role, 'version' => 'v1', 'as_of' => '2025-01-01T00:00:00Z',
                'inputs' => ['as_of_closed_candles'], 'scope' => ['symbols' => ['XAUUSD'], 'contexts' => ['trend']],
                'known_limits' => ['synthetic_readiness_fixture_not_market_proof'],
                'resources' => ['max_compute_ms' => 100, 'max_memory_mb' => 32, 'max_lookback_bars' => 512],
                'horizon' => ['kind' => $role, 'decision_interval_seconds' => 300, 'reevaluation_interval_seconds' => 300,
                    'max_holding_seconds' => $role === 'swing' ? 259200 : 3600, 'execution_precision' => 'candle'],
                'data_requirements' => $role === 'scalp' ? ['bid_ask', 'spread', 'slippage', 'quote_age', 'intrabar_ambiguity']
                    : ($role === 'swing' ? ['gap', 'carry', 'rollover', 'mature_holding_outcomes'] : ['sessions', 'costs']),
                'model_version_id' => $models[$role]->id, 'strategy_version' => 'v1', 'tactic_version' => 'v1',
                'management_version' => 'v1', 'capital_weight' => .2, 'risk_per_trade_percent' => .5, 'sensor_timeframes' => ['H4', 'H1', 'M15', 'M5']];
        }
        $lifecycle = app(SpecialistCouncilLifecycleService::class);
        $version = $lifecycle->registerDraft(['council_id' => 'followup-fixture', 'version' => 'v1', 'members' => $members,
            'components' => [], 'routing' => ['id' => 'router', 'version' => '1'], 'allocation' => ['id' => 'allocation', 'version' => '1'],
            'risk' => ['id' => 'risk', 'version' => '1'], 'execution' => ['id' => 'native', 'version' => '1', 'broker_position_mode' => 'hedging',
                'opposite_position_policy' => 'hedge', 'max_open_positions' => 8, 'max_reserved_capital_percent' => 100,
                'max_gross_exposure_percent' => 100, 'max_total_risk_percent' => 2, 'max_drawdown_percent' => 10,
                'max_daily_loss_percent' => 3, 'max_expected_cost_percent' => 1],
            'evaluation_policy' => ['objective' => 'net_return_at_equal_risk', 'champion_model_version_id' => $models['hour']->id,
                'solo_model_version_id' => $models['hour']->id]], 'original-creator');
        $plan = ['purpose' => 'research', 'preparation_source_hash' => $originalSourceHash ?? str_repeat('e', 64), 'execution_hash' => str_repeat('d', 64),
            'execution_timeframe' => 'M5', 'initial_capital' => 10000, 'cost_model' => ['commission_percent' => .1],
            'risk_policy' => ['max_risk' => 2, 'risk_per_trade_percent' => .5],
            'windows' => [['window_key' => 'original', 'start_inclusive' => '2025-01-01T00:00:00Z',
                'end_exclusive' => '2025-03-01T00:00:00Z', 'dataset_sha256' => str_repeat('c', 64)]],
            'arms' => [['arm_key' => 'candidate', 'kind' => 'candidate', 'window_key' => 'original', 'model_version_id' => $models['candidate']->id],
                ['arm_key' => 'solo', 'kind' => 'solo', 'window_key' => 'original', 'model_version_id' => $models['hour']->id],
                ['arm_key' => 'ablation', 'kind' => 'ablation', 'window_key' => 'original', 'model_version_id' => $models['ablation']->id, 'removed_id' => 'scalp-owner']]];
        $sealed = $lifecycle->sealEvaluationPlan($version, 'original-examiner', $plan);
        $originalRunIds = [];
        if ($schemaFailure) {
            $originalRunIds = [$this->recordOriginalSchemaFailure($version, $sealed, $models['hour'], $errorMessage, $materializeAssignment)->run_id];
        }
        $assessment = ['protocol' => SpecialistCouncilLifecycleService::ASSESSMENT_PROTOCOL, 'version_id' => $version->id,
            'manifest_hash' => $version->manifest_hash, 'plan_hash' => $sealed['plan_hash'], 'original_run_ids' => $originalRunIds, 'original_sources' => [],
            'research_observation_status' => $observation, 'comparisons' => [], 'qualified' => false, 'positive_independent_windows' => 0,
            'reason_codes' => ['SYNTHETIC_ORIGINAL_DATA_DEPENDENCY']];
        $hash = app(ResearchPaperEpochContractService::class)->parameterHash($assessment);
        DB::table('specialist_council_evaluations')->insert(['specialist_council_version_id' => $version->id, 'evaluator_id' => 'original-examiner',
            'original_run_ids' => json_encode($originalRunIds), 'assessment' => json_encode($assessment), 'assessment_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
        $version->forceFill(['state' => 'evaluated', 'assessment' => $assessment, 'assessment_hash' => $hash])->save();
        app(SpecialistCouncilResearchFeedbackService::class)->recordAssessment($version->fresh());
        $work = ResearchExperimentWorkItem::sole();
        $proposal = ['protocol' => SpecialistCouncilResearchFeedbackService::FOLLOWUP_PROTOCOL,
            'research_question' => 'Does a preregistered hour fast-EMA change alter original native behavior after actual data dependency is verified?',
            'creator_id' => 'prospective-creator', 'evaluator_id' => 'prospective-examiner',
            'native_source_model_ids' => array_map(fn ($role): int => $models[$role]->id, array_combine(['scalp', 'hour', 'day', 'swing'], ['scalp', 'hour', 'day', 'swing'])),
            'parameter_deltas' => ['hour' => ['ema_fast' => 5]],
            'discovery_bundle_manifest' => ['bundle_hash' => str_repeat('c', 64), 'data_role' => 'pre_2026_discovery_only'], 'evaluation_plan' => $plan];
        if ($observation === 'data_missing') $proposal['evaluation_plan']['windows'][0]['dataset_sha256'] = str_repeat('b', 64);
        return [$work, $proposal, $version, $models];
    }

    private function recordOriginalSchemaFailure($version, array $sealed, ModelVersion $model, ?string $errorMessage,
        bool $materializeAssignment = false): LabEvaluationRun
    {
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'name' => 'original schema publisher fixture',
            'strategy_families' => ['ema_rsi'], 'is_active' => false]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'historical_research',
            'population_size' => 6, 'status' => 'technical_quarantine', 'trigger_context' => []]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi', 'origin' => 'native_fixture',
            'lifecycle_status' => 'evaluation_error', 'parameter_diff' => []]);
        $model->refresh();
        $assignment = $materializeAssignment ? app(LabInstrumentResearchService::class)->assignment($agent) : null;
        $model->refresh();
        $evidence = app(LabImmutableEvidenceService::class);
        $run = LabEvaluationRun::create(['run_id' => 'original-null-schema-refusal', 'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id, 'model_version_id' => $model->id, 'phase' => 'screening', 'mode' => 'incremental',
            'status' => 'started', 'started_at' => now(), 'code_hash' => str_repeat('e', 64), 'data_hash' => str_repeat('c', 64),
            'parameter_hash' => $evidence->parameterHash($agent), 'metadata' => ['protocol' => 'lab_immutable_evidence_v1', 'source' => 'bounded_screening_batch']]);
        $request = ['replay_dataset_hash' => str_repeat('c', 64), 'execution_hash' => $sealed['execution_hash'],
            'initial_balance' => $sealed['initial_capital'], 'cost_model' => $sealed['cost_model'], 'risk_policy' => $sealed['risk_policy'],
            'strategies' => [['lab_agent_id' => $agent->id, 'strategy' => $model->strategy, 'parameters' => $model->parameters,
                'specialist_council_contract' => null, 'specialist_council_evaluation' => ['version_id' => $version->id,
                    'manifest_hash' => $version->manifest_hash, 'plan_hash' => $sealed['plan_hash'], 'arm_key' => 'solo']]]];
        if ($assignment !== null) $request['strategies'][0]['instrument_research_assignment'] = $assignment;
        $evidence->attachRequest($run, $request);
        $errorMessage ??= json_encode(['detail' => [['type' => 'dict_type', 'loc' => ['body', 'strategies', 0, 'specialist_council_contract'],
            'msg' => 'Input should be a valid dictionary', 'input' => null]]], JSON_THROW_ON_ERROR);
        $evidence->finishRun($run, 'technical_error', null, [], ['reason_code' => 'BATCH_REPLAY_TRANSPORT_FAILURE',
            'batch_protocol' => 'bounded_screening_batch_v1', 'quality_verdict' => 'withheld', 'promotion_evidence' => false],
            new \RuntimeException($errorMessage));
        return $run->fresh();
    }
}
