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
use Tests\TestCase;

/** Synthetic source facts isolate readiness; no test fixture claims a market qualification. */
class SpecialistCouncilFollowupReadinessTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertSame('AUTHORIZED_UNUSED_POST_PAPER_COUNCIL_EXECUTOR_REQUIRED', $service->inspectFollowupReadiness($work->fresh())['reason']);
        $work->update(['work_type' => 'specialist_council_descendant_transfer']);
        $this->assertSame('QUALIFIED_PARENT_AND_DESCENDANT_EXECUTOR_REQUIRED', $service->inspectFollowupReadiness($work->fresh())['reason']);
        $this->assertNull(data_get($work->fresh()->payload, 'followup_resolution'));
    }

    private function fixture(bool $ready = true, string $observation = 'data_missing', array $extraParameters = [],
        bool $schemaFailure = false, ?string $errorMessage = null, bool $materializeAssignment = false): array
    {
        config(['services.internal_api.token' => str_repeat('fixture-key-', 4)]);
        $this->partialMock(LabImmutableEvidenceService::class)->shouldReceive('codeHash')->andReturn(str_repeat('f', 64));
        $this->partialMock(ResearchReleaseSealService::class)->shouldReceive('pythonHash')->andReturn(str_repeat('a', 64));
        if ($ready) $this->mock(SpecialistCouncilPreparationService::class)->shouldReceive('assertProspectiveDiscoveryPlan')->andReturnNull();
        $models = [];
        foreach (['scalp', 'hour', 'day', 'swing', 'candidate', 'ablation'] as $role) {
            $models[$role] = ModelVersion::create(['name' => 'Synthetic '.$role, 'strategy' => 'ema_rsi_v1', 'version' => 'v1',
                'generation' => 1, 'status' => 'testing', 'parameters' => ['ema_fast' => 4, 'ema_slow' => 10, ...$extraParameters],
                'metadata' => ['base_strategy' => 'ema_rsi', 'strategy_architecture' => 'ema_rsi']]);
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
        $plan = ['purpose' => 'research', 'preparation_source_hash' => str_repeat('e', 64), 'execution_hash' => str_repeat('d', 64),
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
