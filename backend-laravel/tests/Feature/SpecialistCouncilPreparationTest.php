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
use App\Services\AutonomousModeService;
use App\Services\GenerationSnapshotAdmissionService;
use App\Services\LabDatasetExportService;
use App\Services\LabPopulationService;
use App\Services\LabReplayRecoveryService;
use App\Services\LearningVelocityGateService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabImmutableEvidenceService;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SpecialistCouncilPreparationTest extends TestCase
{
    use RefreshDatabase;

    private array $originalProbeRows = [];

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

    public function test_invalid_last_native_assignment_rolls_back_all_prospective_assignments_before_sealing(): void
    {
        [$generation, $request, $models] = $this->fixture();
        $producer = app(\App\Services\LabInstrumentResearchService::class);
        $lastId = $models->last()->id;
        $this->mock(\App\Services\LabInstrumentResearchService::class, fn ($mock) => $mock->shouldReceive('assignment')
            ->andReturnUsing(fn (LabAgent $agent): array => $agent->model_version_id === $lastId
                ? ['protocol' => \App\Services\LabInstrumentResearchService::PROTOCOL, 'status' => 'blocked_exact_pair_reservation_missing']
                : $producer->assignment($agent)));
        try {
            app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
            $this->fail('Invalid original assignment was sealed.');
        } catch (\LogicException $error) {
            $this->assertSame('CANONICAL_COUNCIL_PRESEALED_INSTRUMENT_ASSIGNMENT_INVALID', $error->getMessage());
        }
        $this->assertRolledBack($generation, $models);
        foreach ($models as $model) $this->assertNull(data_get($model->fresh()->metadata, 'instrument_research_assignment'));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_assignment_json_storage_number_normalization_preserves_exact_presealed_semantics(): void
    {
        [$generation, $request, $models] = $this->fixture();
        $producer = app(\App\Services\LabInstrumentResearchService::class);
        $this->mock(\App\Services\LabInstrumentResearchService::class, fn ($mock) => $mock->shouldReceive('assignment')
            ->andReturnUsing(function (LabAgent $agent) use ($producer): array {
                $assignment = $producer->assignment($agent);
                // Simulate JSON's 1.0 -> 1 storage projection, not a different
                // decision rule or a different producer's assignment hash.
                $assignment['decision_doctrine']['causal_candidate_limit'] = 1.0;
                $persisted = data_get($agent->modelVersion->fresh()->metadata, 'instrument_research_assignment');
                $this->assertTrue(app(\App\Services\LabImmutableEvidenceService::class)->equivalentJsonValue($persisted, $assignment));
                return $assignment;
            }));
        $receipt = app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
        foreach ($models as $model) {
            $current = $model->fresh();
            $this->assertSame(1, data_get($current->metadata, 'instrument_research_assignment.decision_doctrine.causal_candidate_limit'));
            $this->assertSame($receipt['generation_model_hashes'][(string) $model->id],
                app(\App\Services\SpecialistCouncilContractService::class)->modelHash($current));
        }
        $this->assertTrue(app(SpecialistCouncilPreparationService::class)->isResearchGeneration($generation->fresh()));
    }

    public function test_changed_assignment_numeric_value_is_not_json_normalization_and_rolls_back(): void
    {
        [$generation, $request, $models] = $this->fixture();
        $producer = app(\App\Services\LabInstrumentResearchService::class);
        $this->mock(\App\Services\LabInstrumentResearchService::class, fn ($mock) => $mock->shouldReceive('assignment')
            ->andReturnUsing(function (LabAgent $agent) use ($producer): array {
                $assignment = $producer->assignment($agent);
                $assignment['decision_doctrine']['causal_candidate_limit'] = 1.25;
                return $assignment;
            }));
        try {
            app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
            $this->fail('Changed numeric policy value was treated as storage normalization.');
        } catch (\LogicException $error) {
            $this->assertSame('CANONICAL_COUNCIL_PRESEALED_INSTRUMENT_ASSIGNMENT_INVALID', $error->getMessage());
        }
        $this->assertRolledBack($generation, $models);
        foreach ($models as $model) $this->assertNull(data_get($model->fresh()->metadata, 'instrument_research_assignment'));
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

    public function test_prospective_plan_uses_one_combined_owner_proof_not_a_second_restore_readiness(): void
    {
        [, $request, , , $bundle] = $this->discoveryFixture();
        $owner = app(SpecialistCouncilPreparationService::class);
        $owner->assertProspectiveDiscoveryPlan($request['evaluation_plan'], $bundle['manifest']);
        $mtf = app(MultiTimeframeSnapshotService::class);
        $mtf->shouldHaveReceived('inspectAndRestoreDiscoveryBundle')->with($bundle['manifest'])->once();
        $mtf->shouldNotHaveReceived('discoveryBundleReadiness');
        $mtf->shouldNotHaveReceived('restoreAgentOwnedConfirmationValidationBundle');
        $changed = [...$bundle['manifest'], 'paper_eligible' => true];
        $this->expectExceptionMessage('CANONICAL_COUNCIL_DISCOVERY_BUNDLE_NOT_READY:verified_original_fixture_bytes');
        $owner->assertProspectiveDiscoveryPlan($request['evaluation_plan'], $changed);
    }

    public static function originalProbeScreeningRoutes(): array
    {
        return ['single screen' => [false], 'single-member batch' => [true]];
    }

    /** Request/receipt wiring only: the fake evaluator is not market evidence. */
    #[\PHPUnit\Framework\Attributes\DataProvider('originalProbeScreeningRoutes')]
    public function test_public_screening_seals_original_native_probe_in_both_payload_and_manifest(bool $batch): void
    {
        [$agent, $probe, $generic] = $this->originalProbeScreeningFixture();
        $this->assertNotSame($generic['experiment_key'], $probe['experiment_key']);
        $this->assertNotSame($generic['contract_hash'], $probe['contract_hash']);
        $transported = null;
        $this->fakeOriginalProbeEvaluator($transported);
        $this->driveOriginalProbeScreening($agent, $batch);
        $run = LabEvaluationRun::where('lab_agent_id', $agent->id)->sole();
        $this->assertSame($probe, data_get($transported, 'policy_context.prospective_probe_window'));
        $this->assertSame($probe, data_get($run->request_meta, 'payload.policy_context.prospective_probe_window'));
        $this->assertSame($probe, data_get($run->request_meta, 'dataset_manifest.prospective_probe_window'));
        $this->assertSame(15000, $probe['evaluated_rows']);
        $this->assertSame(512, $probe['warmup_rows']);
        $this->assertTrue(app(ProspectiveRepairProbeWindowService::class)->attests($probe, [...$probe, 'complete' => true]));
        // This sentinel is reached only AFTER the unchanged probe receipt guard.
        if ($batch) $this->assertSame('TEST_AFTER_ORIGINAL_PROBE_ATTESTATION', $run->error_message);
        $this->assertDatabaseCount('paper_authority_admissions', 0);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('originalProbeScreeningRoutes')]
    public function test_public_native_screening_refuses_a_generic_receipt_even_when_rows_and_costs_match(bool $batch): void
    {
        [$agent, $probe, $generic] = $this->originalProbeScreeningFixture();
        foreach (['dataset_hash', 'execution_hash', 'loaded_rows', 'warmup_rows', 'evaluated_rows',
            'loaded_start', 'loaded_end', 'evaluated_start', 'evaluated_end', 'evaluated_month_counts'] as $field) {
            $this->assertSame($probe[$field], $generic[$field]);
        }
        $transported = null;
        $this->fakeOriginalProbeEvaluator($transported, $generic);
        $this->driveOriginalProbeScreening($agent, $batch, 'PROSPECTIVE_PROBE_WINDOW_RECEIPT_MISMATCH');
        $run = LabEvaluationRun::where('lab_agent_id', $agent->id)->sole();
        $this->assertSame($probe, data_get($run->request_meta, 'dataset_manifest.prospective_probe_window'));
        if ($batch) $this->assertSame('PROSPECTIVE_PROBE_WINDOW_RECEIPT_MISMATCH', $run->error_message);
        $this->assertDatabaseCount('candidate_gate_decisions', 0);
        $this->assertDatabaseCount('paper_authority_admissions', 0);
    }

    public static function forgedOriginalProbeReceipts(): array
    {
        $cases = [];
        foreach ([false, true] as $batch) foreach (['dataset_hash', 'execution_hash', 'loaded_start', 'warmup_rows'] as $field) {
            $cases[($batch ? 'batch ' : 'single ').$field] = [$batch, $field];
        }
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forgedOriginalProbeReceipts')]
    public function test_public_native_screening_rejects_rehashed_source_clock_and_warmup_receipts(bool $batch, string $field): void
    {
        [$agent, $probe] = $this->originalProbeScreeningFixture();
        $forged = $probe;
        $forged[$field] = match ($field) {
            'loaded_start' => '2025-01-06T02:05:00Z',
            'warmup_rows' => 511,
            default => str_repeat('e', 64),
        };
        unset($forged['contract_hash']);
        $forged['contract_hash'] = hash('sha256', json_encode($forged, JSON_UNESCAPED_SLASHES));
        $transported = null;
        $this->fakeOriginalProbeEvaluator($transported, $forged);
        $this->driveOriginalProbeScreening($agent, $batch, 'PROSPECTIVE_PROBE_WINDOW_RECEIPT_MISMATCH');
        $run = LabEvaluationRun::where('lab_agent_id', $agent->id)->sole();
        $this->assertSame($probe, data_get($run->request_meta, 'dataset_manifest.prospective_probe_window'));
        if ($batch) $this->assertSame('PROSPECTIVE_PROBE_WINDOW_RECEIPT_MISMATCH', $run->error_message);
        $this->assertDatabaseCount('candidate_gate_decisions', 0);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('originalProbeScreeningRoutes')]
    public function test_public_native_screening_refuses_changed_original_source_before_http(bool $batch): void
    {
        [$agent] = $this->originalProbeScreeningFixture();
        app(LabImmutableEvidenceService::class)->shouldReceive('codeHash')->andReturn(str_repeat('e', 64));
        Http::fake();
        try {
            if ($batch) app(LabAgentEvaluationService::class)->screenBatch([$agent->id], 'XAUUSD');
            else app(LabAgentEvaluationService::class)->screen($agent);
            $this->fail('A changed source cannot own an old native probe.');
        } catch (\LogicException $error) {
            $this->assertSame('CANONICAL_COUNCIL_PREPARATION_ORIGINAL_SOURCE_DRIFT', $error->getMessage());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('candidate_gate_decisions', 0);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('originalProbeScreeningRoutes')]
    public function test_public_native_screening_refuses_forged_arm_owner_before_http(bool $batch): void
    {
        [$agent] = $this->originalProbeScreeningFixture();
        $model = $agent->modelVersion;
        $metadata = (array) $model->metadata;
        $metadata['specialist_council_evaluation']['arm_key'] = 'candidate';
        $model->update(['metadata' => $metadata]);
        Http::fake();
        try {
            if ($batch) app(LabAgentEvaluationService::class)->screenBatch([$agent->id], 'XAUUSD');
            else app(LabAgentEvaluationService::class)->screen($agent);
            $this->fail('A forged model cannot inherit another original arm probe.');
        } catch (\LogicException $error) {
            $this->assertContains($error->getMessage(), ['CANONICAL_COUNCIL_PREPARATION_ORIGINAL_MODEL_DRIFT',
                'DECLARED_COUNCIL_EVALUATION_BINDING_INVALID', 'CANONICAL_COUNCIL_PREPARATION_ORIGINAL_ARM_DRIFT']);
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('candidate_gate_decisions', 0);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('originalProbeScreeningRoutes')]
    public function test_public_unbound_auxiliary_source_keeps_generic_clean_probe(bool $batch): void
    {
        [$agent, $native, $generic] = $this->originalProbeScreeningFixture(true);
        $this->assertNull(data_get($agent->modelVersion->metadata, 'specialist_council_evaluation'));
        $transported = null;
        $this->fakeOriginalProbeEvaluator($transported);
        $this->driveOriginalProbeScreening($agent, $batch);
        $run = LabEvaluationRun::where('lab_agent_id', $agent->id)->sole();
        $this->assertSame($generic, data_get($transported, 'policy_context.prospective_probe_window'));
        $this->assertSame($generic, data_get($run->request_meta, 'dataset_manifest.prospective_probe_window'));
        $this->assertNotSame($native['contract_hash'], $generic['contract_hash']);
        if ($batch) $this->assertSame('TEST_AFTER_ORIGINAL_PROBE_ATTESTATION', $run->error_message);
        $this->assertDatabaseCount('paper_authority_admissions', 0);
    }

    private function originalProbeScreeningFixture(bool $auxiliary = false): array
    {
        Storage::fake('local');
        [$generation, $preparation, $models, $rows, $bundle] = $this->discoveryFixture();
        $this->originalProbeRows = $rows;
        app(SpecialistCouncilPreparationService::class)->prepare($generation, $preparation);
        $probe = $preparation['evaluation_plan']['windows'][0]['prospective_probe_window'];
        $generic = app(ProspectiveRepairProbeWindowService::class)->seal($rows, $bundle['bundle_hash'],
            $probe['execution_hash'], 'academy_clean_discovery:'.$bundle['bundle_hash'].':'.str_repeat('c', 64), 15000, 512);
        // Original solo and auxiliary source both omit an aggregate runtime;
        // only the solo belongs to the original comparison plan.
        $agent = LabAgent::where('model_version_id', $models[$auxiliary ? 1 : 0]->id)->firstOrFail();
        $agent->update(['lifecycle_status' => 'queued']);
        $datasets = \Mockery::mock(LabDatasetExportService::class)->makePartial();
        $datasets->shouldReceive('ensureGenerationSnapshot')->andReturn(['path' => '/fixture/paper.csv',
            'sha256' => str_repeat('f', 64), 'protocol' => 'fixture_paper_reference']);
        $datasets->shouldReceive('ensureGenerationFoundationSnapshot')->andReturn(['path' => '/fixture/foundation.csv',
            'sha256' => str_repeat('a', 64), 'protocol' => 'fixture_historical_reference']);
        $datasets->shouldReceive('rowsFromSnapshot')->with($bundle['entry_dataset_path'], 15512)->andReturn($rows);
        $this->app->instance(LabDatasetExportService::class, $datasets);
        $evidence = \Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $evidence->shouldReceive('replayEvidenceCompleteness')->andThrow(new \RuntimeException('TEST_AFTER_ORIGINAL_PROBE_ATTESTATION'));
        $this->app->instance(LabImmutableEvidenceService::class, $evidence);
        $this->app->forgetInstance(LabAgentEvaluationService::class);
        return [$agent->fresh(['modelVersion', 'generation']), $probe, $generic];
    }

    private function fakeOriginalProbeEvaluator(?array &$transported, ?array $receipt = null): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/api/replay-status' => Http::response(['protocol' => 'replay_liveness_v2_bounded_worker',
                'active_requests' => 0, 'screening_active' => 0, 'screening_capacity' => 1, 'full_active' => 0]),
            '*/api/backtest/run-all' => function ($httpRequest) use (&$transported, $receipt) {
                $transported = $httpRequest->data();
                $strategy = $transported['strategies'][0];
                $actual = $receipt === null ? $this->pythonOriginalProbeReceipt($transported) : [...$receipt, 'complete' => true];
                return Http::response(['leaderboard' => [['strategy' => $strategy['strategy'],
                    'version' => $strategy['version'], 'lab_agent_id' => $strategy['lab_agent_id'], 'score' => 0,
                    'result' => ['prospective_probe_window_receipt' => $actual]]]]);
            },
        ]);
    }

    /** Real PHP-to-Python serialization and existing slice producer, not replay/PnL. */
    private function pythonOriginalProbeReceipt(array $request): array
    {
        $script = <<<'PY'
import json, sys
import pandas as pd
from app.services.prospective_probe_window import select_probe_window
facts = json.load(sys.stdin)
request = facts['request']
frame, receipt = select_probe_window(pd.DataFrame(facts['rows']),
    request['policy_context']['prospective_probe_window'], request['replay_dataset_hash'],
    request['execution_contract']['execution_hash'])
assert len(frame) == 15000
print(json.dumps(receipt))
PY;
        $process = new \Symfony\Component\Process\Process(['python', '-B', '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setInput(json_encode(['request' => $request, 'rows' => $this->originalProbeRows], JSON_UNESCAPED_SLASHES));
        $process->setTimeout(30);
        $process->mustRun();
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function driveOriginalProbeScreening(LabAgent $agent, bool $batch,
        string $expected = 'TEST_AFTER_ORIGINAL_PROBE_ATTESTATION'): void
    {
        $evaluator = app(LabAgentEvaluationService::class);
        if ($batch) {
            $evaluator->screenBatch([$agent->id], 'XAUUSD');
            return;
        }
        try {
            $evaluator->screen($agent);
            $this->fail('The synthetic test must stop before learning or market settlement.');
        } catch (\RuntimeException $error) {
            $this->assertSame($expected, $error->getMessage());
        }
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

    public function test_recovery_refuses_original_member_drift_before_any_dataset_restore_or_queue(): void
    {
        [$generation, $request, $models] = $this->fixture();
        app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
        $models[1]->refresh();
        $metadata = $models[1]->metadata;
        $metadata['execution_contract']['observed_costs'] = ['commission_cash' => 12];
        $models[1]->update(['metadata' => $metadata]);
        $this->mock(LabDatasetExportService::class, function ($mock): void {
            $mock->shouldNotReceive('ensureGenerationSnapshot');
            $mock->shouldNotReceive('ensureGenerationFoundationSnapshot');
        });
        Queue::fake();
        $agent = $generation->agents()->orderBy('id')->firstOrFail();
        $before = $models[1]->fresh()->getAttributes();
        $owner = app(LabReplayRecoveryService::class);
        foreach (['prepare', 'assertContract'] as $boundary) {
            try {
                if ($boundary === 'prepare') {
                    $owner->prepare($agent, 'screen');
                } else {
                    $owner->assertContract($agent, ['protocol' => LabReplayRecoveryService::PROTOCOL,
                        'generation_id' => $generation->id, 'agent_id' => $agent->id,
                        'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'research_release_hash' => null]);
                }
                $this->fail('Recovery accepted a changed original member at '.$boundary);
            } catch (\LogicException $error) {
                $this->assertSame('CANONICAL_COUNCIL_PREPARATION_ORIGINAL_MODEL_DRIFT', $error->getMessage());
            }
        }
        $this->assertSame($before, $models[1]->fresh()->getAttributes());
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Queue::assertNothingPushed();
    }

    private function discoveryFixture(bool $nativeConstructor = false, bool $spreadStudy = false): array
    {
        [$generation, $request, $models] = $this->fixture($nativeConstructor, $spreadStudy);
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
        $mtf->shouldReceive('inspectAndRestoreDiscoveryBundle')->andReturnUsing(fn (array $candidate): array => [
            'readiness' => ['allowed' => $candidate === $manifest, 'reason' => 'verified_original_fixture_bytes'],
            'bundle' => $candidate === $manifest ? $bundle : null]);
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

    public function test_real_six_root_constructor_prepares_original_four_horizon_council_without_other_scientific_owner(): void
    {
        Queue::fake();
        [$generation, $request, $models, , $bundle] = $this->discoveryFixture(true);
        $before = $models->mapWithKeys(fn (ModelVersion $model): array => [$model->id => $model->parameters])->all();
        $owner = app(SpecialistCouncilPreparationService::class);
        $this->assertTrue($owner->hasNativeConstructorIntent($generation));
        $this->assertSame(['CANONICAL_COUNCIL_ATOMIC_PREPARATION_REQUIRED'],
            app(GenerationSnapshotAdmissionService::class)->inspect($generation)['reasons']);
        $receipt = $owner->prepare($generation, $request);
        $this->assertSame(data_get($generation->trigger_context, 'native_specialist_council_intent.intent_hash'), $receipt['native_intent_hash']);
        $this->assertCount(6, $receipt['generation_model_hashes']);
        // Real producer, not a mocked assignment: the original first replay
        // must only reuse already sealed descriptors across all six roots.
        $instruments = app(\App\Services\LabInstrumentResearchService::class);
        $contracts = app(\App\Services\SpecialistCouncilContractService::class);
        foreach ($generation->agents()->with('modelVersion', 'generation')->orderBy('id')->get() as $index => $agent) {
            $frozenHash = $receipt['generation_model_hashes'][(string) $agent->model_version_id];
            $first = $instruments->assignment($agent);
            $second = $instruments->assignment($agent->fresh(['modelVersion', 'generation']));
            $this->assertSame($first['assignment_hash'], $second['assignment_hash']);
            $this->assertSame($frozenHash, $contracts->modelHash($agent->modelVersion->fresh()));
            $this->assertSame($before[$agent->model_version_id], $agent->modelVersion->fresh()->parameters);
            if ($index < 4) {
                $payload = app(LabAgentEvaluationService::class)->specialistCouncilMemberPayload(
                    $agent->modelVersion->fresh(), 'M5', $bundle, $bundle['bundle_hash'], 'XAUUSD');
                $this->assertSame($first['assignment_hash'], $payload['instrument_research_assignment']['assignment_hash']);
                $this->assertSame($frozenHash, $contracts->modelHash($agent->modelVersion->fresh()));
            }
        }
        $sourceAgent = $generation->agents()->with('modelVersion', 'generation')->orderBy('id')->first();
        $screenPayload = new \ReflectionMethod(LabAgentEvaluationService::class, 'screeningStrategyPayload');
        $screenPayload->invoke(app(LabAgentEvaluationService::class), $sourceAgent, 'M5', $bundle, $bundle['bundle_hash']);
        $this->assertSame($receipt['generation_model_hashes'][(string) $sourceAgent->model_version_id],
            $contracts->modelHash($sourceAgent->modelVersion->fresh()));
        $this->assertSame(['scalp', 'hour', 'day', 'swing'], array_column($request['manifest']['members'], 'role'));
        $this->assertTrue($owner->isResearchGeneration($generation->fresh()));
        $this->assertTrue($owner->inspectDiscoveryOwner($generation->fresh(), $bundle['manifest'])['allowed']);
        $this->assertSame($receipt, $owner->prepare($generation->fresh(), $request));
        foreach ($models as $model) {
            $this->assertSame($before[$model->id], $model->fresh()->parameters);
            $this->assertFalse(data_get($model->fresh()->metadata, 'native_specialist_council_seed.qualified_specialist'));
        }
        $this->assertDatabaseCount('lab_generations', 2); // Original terminal reference plus canonical draft.
        $this->assertDatabaseCount('lab_agents', 6);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('paper_authority_admissions', 0);
        Queue::assertNothingPushed();
    }

    public function test_native_constructor_question_creator_and_slot_ownership_cannot_be_changed_at_preparation(): void
    {
        [$generation, $request, $models] = $this->fixture(true);
        foreach (['creator_id', 'research_question'] as $field) {
            $changed = [...$request, $field => 'changed'];
            try { app(SpecialistCouncilPreparationService::class)->prepare($generation, $changed); $this->fail('Changed sealed intent accepted'); }
            catch (\LogicException $error) { $this->assertSame('CANONICAL_COUNCIL_NATIVE_INTENT_REQUEST_MISMATCH', $error->getMessage()); }
        }
        $changed = $request;
        $changed['manifest']['members'][0]['model_version_id'] = $models[1]->id;
        try { app(SpecialistCouncilPreparationService::class)->prepare($generation, $changed); $this->fail('Wrong source role accepted'); }
        catch (\LogicException $error) { $this->assertSame('CANONICAL_COUNCIL_NATIVE_INTENT_SOURCE_ROLE_MISMATCH', $error->getMessage()); }
        $changed = $request;
        $changed['evaluation_plan']['arms'][2]['model_version_id'] = $models[2]->id;
        try { app(SpecialistCouncilPreparationService::class)->prepare($generation, $changed); $this->fail('Source stolen as ablation carrier'); }
        catch (\LogicException $error) { $this->assertSame('CANONICAL_COUNCIL_NATIVE_INTENT_ARM_ROLE_MISMATCH', $error->getMessage()); }
        $this->assertRolledBack($generation, $models);
    }

    public function test_original_spread_study_constructor_prepares_only_two_carriers_on_one_draft_native_version(): void
    {
        Queue::fake();
        config()->set('services.internal_api.token', str_repeat('x', 32));
        [$generation, $ordinary, $models, , $bundle] = $this->discoveryFixture(true, true);
        $request = array_intersect_key($ordinary, array_flip(['protocol', 'manifest', 'creator_id', 'evaluator_id', 'research_question', 'discovery_bundle_manifest']));
        $request['research_purpose'] = 'spread_context_study';
        $request['study_spec'] = ['specialist_id' => 'day', 'exact_context' => ['regime' => 'trend_up', 'volatility' => 'normal',
            'session' => 'london', 'venue_phase' => 'london_interfix', 'direction' => 'BUY'],
            'liquidity_atr_binding' => 'closed_m5_management_atr_v1', 'initial_capital' => 10000, 'minimum_paired_opportunities' => 1,
            'prospective_probe_window' => $ordinary['evaluation_plan']['windows'][0]['prospective_probe_window']];
        $owner = app(SpecialistCouncilPreparationService::class);
        $changed = $request; $changed['study_spec']['exact_context']['regime'] = 'trend_down';
        try { $owner->prepare($generation, $changed); $this->fail('Original declaration was replaced at preparation.'); }
        catch (\LogicException $error) { $this->assertSame('CANONICAL_SPREAD_STUDY_ORIGINAL_CONTEXT_DECLARATION_MISMATCH', $error->getMessage()); }
        $changed = $request; $changed['study_spec']['liquidity_atr_binding'] = 'closed_strategy_atr_v1';
        try { $owner->prepare($generation, $changed); $this->fail('Original ATR source was replaced at preparation.'); }
        catch (\LogicException $error) { $this->assertSame('CANONICAL_SPREAD_STUDY_ORIGINAL_CONTEXT_DECLARATION_MISMATCH', $error->getMessage()); }
        $receipt = $owner->prepare($generation, $request);
        $this->assertSame('spread_context_study', $receipt['research_purpose']);
        $this->assertSame('prepared_for_canonical_dispatch', $receipt['status']);
        $this->assertCount(6, $receipt['generation_model_hashes']);
        $this->assertCount(6, $receipt['original_constructor']['constructor_episode_ids']);
        $this->assertCount(2, $receipt['dispatch_agent_ids']);
        $this->assertSame($receipt['version_id'], data_get($models[4]->fresh()->metadata, 'specialist_council.version_id'));
        $this->assertSame($receipt['version_id'], data_get($models[5]->fresh()->metadata, 'specialist_council.version_id'));
        $this->assertSame($models[2]->parameters, $models[4]->fresh()->parameters);
        $this->assertSame($models[2]->parameters, $models[5]->fresh()->parameters);
        $storedReceipt = data_get($generation->fresh()->trigger_context, 'specialist_council_preparation');
        $this->assertSame($storedReceipt['receipt_hash'], app(ResearchPaperEpochContractService::class)->parameterHash(array_diff_key($storedReceipt, ['receipt_hash' => true])), 'Stored receipt exact hash');
        $this->assertTrue($owner->isResearchGeneration($generation->fresh()));
        $this->assertTrue($owner->inspectDiscoveryOwner($generation->fresh(), $bundle['manifest'])['allowed']);
        $this->assertSame($receipt['dispatch_agent_ids'], $owner->spreadStudyDispatchAgentIds($generation->fresh()));
        $this->assertSame($receipt, $owner->prepare($generation->fresh(), $request));
        $this->assertSame('normal', data_get($models[2]->fresh()->metadata, 'specialist_council_membership.contextual_cell.spread_liquidity_state'));
        $selected = (array) data_get($models[2]->fresh()->metadata, 'instrument_research_assignment.selected', []);
        $this->assertNotEmpty($selected);
        foreach ($selected as $instrument) $this->assertSame('normal', data_get($instrument, 'activation_contract.context.declared_context.spread_liquidity_state'));
        $dispatcher = app(\App\Console\Commands\DispatchLabGeneration::class);
        $admission = new \ReflectionMethod($dispatcher, 'normalCausalAdmission');
        $this->assertSame('original_native_spread_context_study', $admission->invoke($dispatcher, $generation->fresh())['owner']);
        $agents = $generation->agents()->with('modelVersion')->orderBy('id')->get();
        $jobs = (new \ReflectionMethod($dispatcher, 'screeningJobs'))->invoke($dispatcher, $generation->fresh(), $agents,
            $agents->pluck('id')->all(), 6, 'XAUUSD', 'H1');
        $this->assertCount(2, $jobs);
        $this->assertSame(array_map(fn ($id) => [$id], $receipt['dispatch_agent_ids']), array_map(fn ($job) => $job->labAgentIds, $jobs));
        $this->assertDatabaseCount('specialist_council_evaluation_plans', 0);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        Queue::assertNothingPushed();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('originalSpreadStudyTerminalCases')]
    public function test_original_six_slot_study_jobs_consume_real_native_products_and_close_neutrally(bool $technicalSecondArm): void
    {
        Queue::fake(); Storage::fake('local');
        config()->set('services.internal_api.token', str_repeat('x', 32));
        [$generation, $ordinary, $models] = $this->fixture(true, true);
        [$rows, $bundle, $probe] = $this->syntheticOriginalStudyBundle();
        $request = array_intersect_key($ordinary, array_flip(['protocol', 'manifest', 'creator_id', 'evaluator_id', 'research_question']));
        $request['research_purpose'] = 'spread_context_study';
        $request['discovery_bundle_manifest'] = $bundle['manifest'];
        $request['study_spec'] = ['specialist_id' => 'day', 'exact_context' => ['regime' => 'trend_up', 'volatility' => 'normal',
            'session' => 'london', 'venue_phase' => 'london_interfix', 'direction' => 'BUY'],
            'liquidity_atr_binding' => 'closed_m5_management_atr_v1', 'initial_capital' => 10000, 'minimum_paired_opportunities' => 1, 'prospective_probe_window' => $probe];
        // This is conditional synthetic CSV data, not recovered provider data,
        // market qualification, an economic experiment or an independent panel.
        // Both native owners still verify original physical bytes and hashes.
        $mtf = \Mockery::mock(MultiTimeframeSnapshotService::class)->makePartial();
        $mtf->shouldReceive('inspectAndRestoreDiscoveryBundle')->andReturnUsing(fn (array $candidate): array => [
            'readiness' => ['allowed' => $candidate === $bundle['manifest']], 'bundle' => $bundle]);
        $mtf->shouldReceive('restoreAgentOwnedConfirmationValidationBundle')->with($bundle['manifest'], true)->andReturn($bundle);
        $this->app->instance(MultiTimeframeSnapshotService::class, $mtf);
        $this->app->instance(LabDatasetExportService::class, \Mockery::mock(LabDatasetExportService::class)->makePartial());
        $receipt = app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('agent_learning_episodes', 6);
        $datasets = \Mockery::mock(LabDatasetExportService::class)->makePartial();
        $datasets->shouldReceive('ensureGenerationSnapshot')->andReturn(['path' => '/fixture/paper-never-read.csv',
            'sha256' => str_repeat('f', 64), 'protocol' => 'fixture_paper_reference']);
        $datasets->shouldReceive('ensureGenerationFoundationSnapshot')->andReturn(['path' => '/fixture/foundation-never-read.csv',
            'sha256' => str_repeat('a', 64), 'protocol' => 'fixture_historical_reference']);
        $this->app->instance(LabDatasetExportService::class, $datasets);
        $this->app->forgetInstance(LabAgentEvaluationService::class);
        app(\App\Services\ResearchReleaseSealService::class)->seal($generation->fresh());
        $generation->update(['status' => 'screening']);
        $generation->agents()->whereIn('id', $receipt['dispatch_agent_ids'])->update(['lifecycle_status' => 'queued']);
        $agents = $generation->agents()->with('modelVersion')->orderBy('id')->get();
        $jobs = (new \ReflectionMethod(\App\Console\Commands\DispatchLabGeneration::class, 'screeningJobs'))
            ->invoke(app(\App\Console\Commands\DispatchLabGeneration::class), $generation->fresh(), $agents,
                $agents->pluck('id')->all(), 6, 'XAUUSD', 'H1');
        $this->assertCount(2, $jobs);
        $requests = []; $productions = []; $processBudgets = [];
        Http::preventStrayRequests();
        Http::fake([
            '*/api/replay-status' => Http::response(['protocol' => 'replay_liveness_v2_bounded_worker',
                'active_requests' => 0, 'screening_active' => 0, 'screening_capacity' => 1, 'full_active' => 0]),
            '*/api/backtest/run-all' => function ($http) use (&$requests, &$productions, &$processBudgets, $technicalSecondArm) {
                $batch = $http->data(); $this->assertCount(1, $batch['strategies']);
                $strategy = $batch['strategies'][0]; $requests[] = $batch;
                if ($technicalSecondArm) return Http::response(['error' => 'CONDITIONAL_ORIGINAL_STUDY_TRANSPORT_FAILURE'], 503);
                $native = [...array_diff_key($batch, ['strategies' => true]), ...$strategy];
                // Ask the same request-specific production selector, rather
                // than impose a shorter test-only deadline on a 15k probe.
                $runtimeEnvironment = ['INTERNAL_API_TOKEN' => config('services.internal_api.token'), 'INTERNAL_API_TOKEN_FILE' => ''];
                $budgetProcess = new \Symfony\Component\Process\Process(['python', '-B', '-c',
                    "import json,sys;from app.schemas import SimpleBacktestRequest;from app.main import _bounded_replay_seconds;"
                    ."p=SimpleBacktestRequest.model_validate(json.load(sys.stdin));"
                    ."print(json.dumps({'owner':'app.main._bounded_replay_seconds','operation':'run_all',"
                    ."'seconds':_bounded_replay_seconds(p,'run_all'),'evaluated_rows':p.policy_context['prospective_probe_window']['evaluated_rows']}))"],
                    dirname(base_path()).'/ai-service-python', $runtimeEnvironment);
                $budgetProcess->setTimeout(30); $budgetProcess->setInput(json_encode($native, JSON_THROW_ON_ERROR));
                $budgetProcess->mustRun(); $budget = json_decode($budgetProcess->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $budget['arm'] = data_get($native, 'native_spread_context_study_contract.arm');
                $budget['transport_seconds'] = min(1800, max(60, (int) config('services.lab_queue.screening_batch_timeout_seconds', 1800)));
                $processBudgets[] = $budget;
                $this->assertSame('app.main._bounded_replay_seconds', $budget['owner']);
                $this->assertSame(15000, $budget['evaluated_rows']);
                $this->assertGreaterThan(600, $budget['seconds'], json_encode($budget));
                $this->assertLessThanOrEqual(1680, $budget['seconds'], json_encode($budget));
                $this->assertLessThan($budget['transport_seconds'], $budget['seconds'], json_encode($budget));
                $process = new \Symfony\Component\Process\Process(['python', '-B', '-c',
                    "import runpy;runpy.run_path('tests/support/native_spread_context_study_fixture.py',run_name='__main__')"],
                    dirname(base_path()).'/ai-service-python', $runtimeEnvironment);
                $process->setTimeout($budget['seconds']); $process->setInput(json_encode(['mode' => 'replay', 'request' => $native], JSON_THROW_ON_ERROR));
                $process->mustRun(); $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $productions[] = $result;
                // Mirror the real Python JSON transport: preserve integral
                // floats (2000.0 / 10000.0) used by the sealed native trace.
                return Http::response(json_encode(['leaderboard' => [['strategy' => $strategy['strategy'], 'version' => $strategy['version'],
                    'lab_agent_id' => $strategy['lab_agent_id'], 'score' => 0, 'result' => $result]]],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), 200, ['Content-Type' => 'application/json']);
            },
        ]);
        foreach ($jobs as $index => $job) {
            $job->handle(app(LabAgentEvaluationService::class), app(\App\Services\FrozenControlScreeningAdmissionService::class),
                app(\App\Services\LearningTechnicalCircuitBreakerService::class));
            if ($index === 0) {
                $this->assertDatabaseCount('agent_learning_settlements', 0);
                $this->assertCount(4, $generation->agents()->where('lifecycle_status', 'draft')->get());
                $waiting = app(\App\Services\LabGenerationTerminalBoundaryService::class)->closeIfTerminal($generation->fresh());
                $this->assertFalse($waiting['closed']);
                $this->assertSame('NATIVE_SPREAD_STUDY_ORIGINAL_PAIR_NOT_TERMINAL', $waiting['reason_code']);
            }
        }
        $runs = LabEvaluationRun::where('lab_generation_id', $generation->id)->orderBy('id')->get();
        $boundary = app(\App\Services\LabGenerationTerminalBoundaryService::class)->closeIfTerminal($generation->fresh());
        $watermark = app(\App\Services\SettlementWatermarkService::class)->reconcile('XAUUSD', 'H1', $generation->fresh());
        $originalComparison = \App\Models\ResearchExperimentReceipt::where('source_type', \App\Services\NativeSpreadContextStudyService::class)->first();
        $safeReason = static function ($value): ?string {
            if ($value === null) return null;
            return preg_match('/^([A-Z][A-Z0-9_]{3,160})(?=:|$)/', (string) $value, $match) ? $match[1] : 'UNCLASSIFIED_SANITIZED_REASON';
        };
        $terminalDiagnostic = ['synthetic_test_only' => true, 'promotion_evidence' => false,
            'generation_status' => $generation->fresh()->status, 'boundary_reason' => $boundary['reason_code'] ?? null,
            'native_process_budgets' => $processBudgets, 'returned_product_count' => count($productions),
            'source_reference_count' => $generation->agents()->where('lifecycle_status', 'completed')->count(),
            'neutral_settlement_count' => \App\Models\AgentLearningSettlement::count(),
            'settled_episode_count' => \App\Models\AgentLearningEpisode::where('status', 'settled')->count(),
            'work_item_count' => \App\Models\ResearchExperimentWorkItem::count(),
            'credit_event_count' => \App\Models\LabEvolutionCreditEvent::count(),
            'candidate_gate_count' => \Illuminate\Support\Facades\DB::table('candidate_gate_decisions')->count(),
            'paper_authority_count' => \Illuminate\Support\Facades\DB::table('paper_authority_admissions')->count(),
            'settlement_watermark' => array_intersect_key($watermark, array_flip(['terminal', 'generation_close_allowed'])),
            'agents' => $generation->agents()->get(['id', 'lifecycle_status', 'decision_reason'])->toArray(),
            'runs' => $runs->map(fn ($run) => ['run_id' => $run->run_id, 'status' => $run->status,
                'request_hash' => $run->request_hash, 'response_hash' => $run->response_hash,
                'error_class' => $run->error_class, 'reason' => $safeReason($run->error_message),
                'reason_code' => data_get($run->metadata, 'reason_code'),
                'evidence_reason_codes' => data_get($run->metadata, 'evidence_quality.reason_codes'),
                'decision_trace_reason_codes' => data_get($run->metadata, 'evidence_quality.decision_trace_reason_codes')])->all(),
            'comparison_classification' => $originalComparison?->classification,
            'comparison_outcome_status' => data_get($originalComparison?->payload, 'evidence.outcome.status'),
            'comparison_outcome_reason' => $safeReason(data_get($originalComparison?->payload, 'evidence.outcome.reason')),
            'artifacts' => \App\Models\LabEvidenceArtifact::where('lab_generation_id', $generation->id)
                ->get(['artifact_id', 'artifact_type', 'run_id', 'sha256', 'storage_path'])->toArray()];
        $diagnosticArtifact = app(LabImmutableEvidenceService::class)->recordArtifact(null, 'native_study_terminal_test_diagnostic',
            $terminalDiagnostic, ['synthetic_test_only' => true, 'promotion_evidence' => false], LabAgent::find($receipt['dispatch_agent_ids'][0]),
            data_get($generation->fresh()->trigger_context, 'native_spread_context_study.study_id'));
        $diagnosticMessage = json_encode([...$terminalDiagnostic, 'diagnostic_artifact_path' => $diagnosticArtifact->storage_path]);
        $this->assertCount(2, $requests, $diagnosticMessage);
        $this->assertCount($technicalSecondArm ? 0 : 2, $productions, $diagnosticMessage);
        $this->assertSame('masked', data_get($requests[0], 'strategies.0.native_spread_context_study_contract.arm'));
        $this->assertSame('unmasked', data_get($requests[1], 'strategies.0.native_spread_context_study_contract.arm'));
        $this->assertCount(2, $runs);
        foreach ($runs as $run) $this->assertTrue(app(LabImmutableEvidenceService::class)->isTerminalRun($run));
        if ($generation->fresh()->status === 'screened') $this->assertSame('GENERATION_ALREADY_TERMINAL', $boundary['reason_code']);
        else $this->assertTrue($boundary['closed'], $diagnosticMessage);
        $this->assertSame($technicalSecondArm ? 'technical_quarantine' : 'screened', $generation->fresh()->status,
            $diagnosticMessage);
        $this->assertCount(4, $generation->agents()->where('lifecycle_status', 'completed')->get());
        $this->assertDatabaseCount('agent_learning_settlements', 6);
        $this->assertSame(6, \App\Models\AgentLearningEpisode::where('status', 'settled')->count());
        $this->assertSame(6, $watermark['terminal']); $this->assertTrue($watermark['generation_close_allowed']);
        $this->assertDatabaseCount('research_experiment_receipts', 1);
        $comparison = \App\Models\ResearchExperimentReceipt::sole();
        $this->assertSame($technicalSecondArm ? 'TECHNICAL_QUARANTINE' : 'INCONCLUSIVE', $comparison->classification);
        if (! $technicalSecondArm) {
            $this->assertSame('measured_existing_data_sensitivity', data_get($comparison->payload, 'evidence.outcome.status'));
            $this->assertGreaterThan(0, data_get($comparison->payload, 'evidence.outcome.eligible_paired_opportunities'));
            // The unchanged spread_to_atr risk gate legitimately keeps this
            // pristine programme WAIT in both arms. Measured zero is evidence,
            // not a reason to loosen risk or fabricate a positive trade.
            $this->assertSame(0, data_get($comparison->payload, 'evidence.outcome.changed_entry_wait_decisions'));
            $this->assertFalse(data_get($comparison->payload, 'evidence.outcome.market_value_proven'));
        }
        $this->assertFalse(data_get($comparison->payload, 'evidence.economic_authority'));
        $this->assertFalse(data_get($comparison->payload, 'evidence.skill_authority'));
        $this->assertDatabaseCount('research_experiment_work_items', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertDatabaseCount('candidate_gate_decisions', 0);
        $this->assertDatabaseCount('paper_authority_admissions', 0);
        if ($technicalSecondArm) {
            Queue::assertNothingPushed();
        } else {
            Queue::assertPushed(\App\Jobs\ProjectLabCandleDecisionEvents::class, 2);
            Queue::assertCount(2);
            $projectionJobs = Queue::pushed(\App\Jobs\ProjectLabCandleDecisionEvents::class);
            $this->assertSame($runs->pluck('run_id')->sort()->values()->all(),
                $projectionJobs->pluck('runId')->sort()->values()->all());
            foreach ($projectionJobs as $projectionJob) {
                $projectionJob->handle(app(LabImmutableEvidenceService::class));
                $projectionJob->handle(app(LabImmutableEvidenceService::class));
            }
            $this->assertSame([], app(\App\Services\LabHistoricalLearningService::class)->refreshForLab('XAUUSD', 'H1'));
            $this->assertDatabaseCount('lab_learning_insights', 0);
            $this->assertDatabaseCount('lab_evolution_credit_events', 0);
            $this->assertDatabaseCount('lab_mutation_credit_events', 0);
            $this->assertDatabaseCount('agent_knowledge_cards', 0);
            $this->assertDatabaseCount('agent_memories', 0);
            $this->assertDatabaseCount('research_experiment_work_items', 0);
            $this->assertDatabaseCount('agent_learning_settlements', 6);
            Queue::assertCount(2);
        }
    }

    public static function originalSpreadStudyTerminalCases(): array { return ['original_complete_pair' => [false], 'original_typed_transport_failures' => [true]]; }

    private function syntheticOriginalStudyBundle(): array
    {
        // The ordinary immutable replay-use owner permits only canonical
        // frozen dataset roots. Use a unique isolated conditional-test root;
        // fake-local remains inappropriate for original source attestation.
        config()->set('filesystems.disks.native_original_study_fixture', ['driver' => 'local',
            'root' => storage_path('app/lab-datasets/native-original-study-'.Str::uuid()), 'throw' => true]);
        $start = CarbonImmutable::parse('2025-01-06T02:00:00Z'); $rows = [];
        // Prespecified sparse synthetic opportunities: a quiescent input
        // followed by a bounded active segment. No strategy/signal is patched.
        $priceAt = static fn ($index): float => $index < 14000 ? 2000.0 : 2000.0 + 10 * sin(($index - 14000) / 12) + ($index - 14000) * .025;
        for ($i = 0; $i < 15512; $i++) {
            $time = $start->addMinutes(5 * $i); $price = $priceAt($i);
            $rows[] = ['time' => $time->toIso8601String(), 'open' => $price, 'high' => $price + .5, 'low' => $price - .5,
                'close' => $price, 'volume' => 100, 'volume_available' => 1, 'spread_available' => 1, 'spread' => .1,
                'bid_close' => $price, 'ask_close' => $price + .1, 'quote_time_utc' => $time->addSeconds(270)->toIso8601String(),
                'quote_available_after_utc' => $time->addSeconds(300)->toIso8601String(), 'quote_age_ms' => 30000];
        }
        $streams = []; $cutoff = $start->addMinutes(5 * count($rows));
        foreach (['M5' => [5, count($rows)], 'M15' => [15, 8000], 'H1' => [60, 2400], 'H4' => [240, 1600]] as $timeframe => [$minutes, $count]) {
            $data = $rows;
            if ($timeframe !== 'M5') {
                $data = []; $contextCutoff = $cutoff->startOfHour();
                if ($timeframe === 'M15') $contextCutoff = $cutoff->setMinute((int) floor($cutoff->minute / 15) * 15);
                if ($timeframe === 'H4') $contextCutoff = $cutoff->startOfDay()->addHours((int) floor($cutoff->hour / 4) * 4);
                $contextStart = $contextCutoff->subMinutes($minutes * $count);
                for ($i = 0; $i < $count; $i++) {
                    $time = $contextStart->addMinutes($minutes * $i);
                    $offset = (int) ($time->getTimestamp() - $start->getTimestamp()) / 300; $prices = [];
                    for ($part = 0; $part < $minutes / 5; $part++) $prices[] = $priceAt($offset + $part);
                    // Closed context bars aggregate the same deterministic M5
                    // price function, including an explicit earlier warmup.
                    $data[] = ['time' => $time->toIso8601String(), 'open' => $prices[0], 'high' => max($prices) + .5,
                        'low' => min($prices) - .5, 'close' => $prices[count($prices) - 1], 'volume' => 100 * count($prices), 'volume_available' => 1];
                }
            }
            $csv = fopen('php://temp', 'w+'); fputcsv($csv, array_keys($data[0]), ',', '"', '');
            foreach ($data as $row) fputcsv($csv, array_values($row), ',', '"', ''); rewind($csv); $bytes = stream_get_contents($csv); fclose($csv);
            $relative = $timeframe.'.csv'; Storage::disk('native_original_study_fixture')->put($relative, $bytes);
            $streams[$timeframe] = ['path' => Storage::disk('native_original_study_fixture')->path($relative), 'sha256' => hash('sha256', $bytes), 'row_count' => count($data)];
        }
        $hash = app(ResearchPaperEpochContractService::class)->parameterHash($streams);
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $probe = app(ProspectiveRepairProbeWindowService::class)->seal($rows, $hash, $execution['execution_hash'], 'original-synthetic-spread-study', 15000, 512);
        $calendar = array_intersect_key($probe, array_flip(['loaded_rows', 'warmup_rows', 'evaluated_rows', 'loaded_start',
            'loaded_end', 'evaluated_start', 'evaluated_end', 'evaluated_month_counts']));
        $calendar['selected_unexpected_gaps'] = 0;
        $scope = ['protocol' => 'prospective_clean_discovery_scope_v1', 'symbol' => 'XAUUSD', 'calendar' => $calendar,
            'fixture' => 'synthetic_transport_not_provider_or_independent_qualification', 'independent_evidence' => false,
            'full_validation_eligible' => false, 'paper_eligible' => false, 'promotion_evidence' => false];
        $scope['scope_hash'] = app(ExecutionContractService::class)->hashParameters($scope);
        $manifest = ['protocol' => MultiTimeframeSnapshotService::PROTOCOL, 'validation_bundle_protocol' => MultiTimeframeSnapshotService::DISCOVERY_BUNDLE_PROTOCOL,
            'bundle_hash' => $hash, 'data_role' => 'pre_2026_discovery_only', 'symbol' => 'XAUUSD', 'closed_cutoff' => $cutoff->toIso8601ZuluString(),
            'streams' => $streams, 'entry_rows' => 15512, 'discovery_scope' => $scope,
            'bounded_cost_contract' => ['requested_m5_rows' => 15512, 'evaluated_rows' => 15000, 'warmup_rows' => 512],
            'quote_spread_provenance' => ['protocol' => 'historical_quote_spread_snapshot_v1', 'provider' => 'dukascopy_historical_synchronized_tick_v1',
                'maximum_quote_age_ms' => 60000, 'paper_2026_included' => false, 'promotion_evidence' => false,
                'source_m5_csv_sha256' => $streams['M5']['sha256'], 'sources' => [['sha256' => $streams['M5']['sha256'],
                    'fixture' => 'synthetic_transport_not_provider_qualification']]],
            'independent_evidence' => false, 'full_validation_eligible' => false, 'paper_eligible' => false,
            'runtime_trade_authority' => false, 'parent_authority' => false, 'promotion_evidence' => false];
        return [$rows, ['bundle_hash' => $hash, 'manifest' => $manifest, 'manifest_path' => '/fixture/synthetic-manifest.json',
            'entry_dataset_path' => $streams['M5']['path'], 'context_dataset_paths' => array_map(fn ($stream) => $stream['path'], array_diff_key($streams, ['M5' => true]))], $probe];
    }

    public function test_future_native_constructor_refuses_missing_solo_declaration_without_partial_seals(): void
    {
        Queue::fake();
        [$generation, $request] = $this->fixture(true);
        unset($request['evaluation_plan']['solo_comparison']);
        try {
            app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
            $this->fail('An undeclared future native SOLO entered preparation.');
        } catch (\LogicException $error) {
            $this->assertSame('CANONICAL_COUNCIL_FUTURE_NATIVE_SOLO_DECLARATION_REQUIRED', $error->getMessage());
        }
        $this->assertDatabaseCount('specialist_council_versions', 0);
        $this->assertDatabaseCount('specialist_council_evaluation_plans', 0);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_future_native_constructor_seals_exact_matched_allocation_solo_without_model_mutation(): void
    {
        Queue::fake();
        [$generation, $request, $models] = $this->fixture(true);
        $before = $models[0]->parameters;
        $receipt = app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $service = app(SpecialistCouncilLifecycleService::class);
        $contract = $service->runtimeContractForModel($models[0]->fresh(), 'M5', str_repeat('d', 64), $execution['execution_hash'], null, 'XAUUSD');
        $this->assertCount(1, $contract['members']);
        $this->assertSame('scalp', $contract['members'][0]['specialist_id']);
        $this->assertSame($before, $models[0]->fresh()->parameters);
        $this->assertNull(data_get($models[0]->fresh()->metadata, 'specialist_council'));
        $version = SpecialistCouncilVersion::findOrFail($receipt['version_id']);
        $candidate = $service->runtimeContract($version, str_repeat('d', 64), $execution['execution_hash'], 'M5', null, 'XAUUSD');
        $this->assertSame($candidate['members'][0], $contract['members'][0]);
        $this->assertSame($candidate['policy'], $contract['policy']);
        $this->assertSame(.25, $contract['members'][0]['capital_weight']);
        $this->assertSame('matched_member_allocation', $contract['solo_comparison']['comparison_kind']);
        $this->assertFalse($contract['solo_comparison']['best_solo_full_budget_proven']);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_fresh_native_constructor_seals_chosen_full_account_view_without_renormalizing_manifest(): void
    {
        Queue::fake();
        [$generation, $request, $models] = $this->fixture(true);
        $before = $models[0]->parameters;
        $request['evaluation_plan']['solo_comparison'] = $this->fullAccountChosenSoloSelector();
        $receipt = app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $service = app(SpecialistCouncilLifecycleService::class);
        $runtime = $service->runtimeContractForModel($models[0]->fresh(), 'M5', str_repeat('d', 64), $execution['execution_hash'], null, 'XAUUSD');
        $version = SpecialistCouncilVersion::findOrFail($receipt['version_id']);
        $candidate = $service->runtimeContract($version, str_repeat('d', 64), $execution['execution_hash'], 'M5', null, 'XAUUSD');
        $this->assertSame($candidate['members'][0], $runtime['solo_source_member']);
        $restored = $runtime['members'][0]; $restored['capital_weight'] = .25;
        $this->assertSame($runtime['solo_source_member'], $restored);
        $this->assertSame($candidate['policy'], $runtime['policy']);
        $this->assertSame(1.0, $runtime['members'][0]['capital_weight']);
        $this->assertSame(.25, $version->manifest['members'][0]['capital_weight']);
        $this->assertSame($before, $models[0]->fresh()->parameters);
        $this->assertSame('chosen_source_unqualified', $runtime['solo_comparison']['selection_status']);
        $this->assertFalse($runtime['solo_comparison']['best_solo_full_budget_proven']);
        $this->assertFalse($runtime['solo_comparison']['promotion_evidence']);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_original_matched_preparation_cannot_be_upgraded_to_full_account_choice(): void
    {
        Queue::fake();
        [$generation, $request] = $this->fixture(true);
        $owner = app(SpecialistCouncilPreparationService::class);
        $receipt = $owner->prepare($generation, $request);
        $row = \Illuminate\Support\Facades\DB::table('specialist_council_evaluation_plans')->sole();
        $request['evaluation_plan']['solo_comparison'] = $this->fullAccountChosenSoloSelector();
        try { $owner->prepare($generation->fresh(), $request); $this->fail('An original matched plan was upgraded in place.'); }
        catch (\LogicException $error) { $this->assertSame('CANONICAL_COUNCIL_PREPARATION_RETRY_CHANGED', $error->getMessage()); }
        $this->assertSame($row->plan, \Illuminate\Support\Facades\DB::table('specialist_council_evaluation_plans')->sole()->plan);
        $this->assertSame($row->plan_hash, \Illuminate\Support\Facades\DB::table('specialist_council_evaluation_plans')->sole()->plan_hash);
        $this->assertSame($receipt['receipt_hash'], data_get($generation->fresh()->trigger_context, 'specialist_council_preparation.receipt_hash'));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Queue::assertNothingPushed();
    }

    private function fullAccountChosenSoloSelector(): array
    {
        return ['protocol' => \App\Services\SpecialistCouncilContractService::NATIVE_CHOSEN_SOLO_PROTOCOL,
            'comparison_kind' => 'chosen_source_full_account_allocation', 'specialist_id' => 'scalp',
            'selection_status' => 'chosen_source_unqualified', 'selection_timing' => 'preregistered_before_outcomes',
            'best_solo_full_budget_proven' => false];
    }

    public function test_full_account_choice_refuses_an_original_source_attempt_before_partial_preparation(): void
    {
        Queue::fake();
        [$generation, $request, $models] = $this->fixture(true);
        $request['evaluation_plan']['solo_comparison'] = $this->fullAccountChosenSoloSelector();
        $run = LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
            'model_version_id' => $models[0]->id, 'phase' => 'screening', 'status' => 'started']);
        try { app(SpecialistCouncilPreparationService::class)->prepare($generation, $request); $this->fail('Original source attempt was grandfathered into full allocation.'); }
        catch (\LogicException $error) { $this->assertSame('CANONICAL_COUNCIL_MODEL_ALREADY_OBSERVED_OR_REUSED', $error->getMessage()); }
        $this->assertSame('started', $run->fresh()->status);
        $this->assertDatabaseCount('lab_evaluation_runs', 1);
        $this->assertDatabaseCount('specialist_council_versions', 0);
        $this->assertDatabaseCount('specialist_council_evaluation_plans', 0);
        Queue::assertNothingPushed();
    }

    public function test_unprepared_native_constructor_and_removed_intent_cannot_fall_back_to_ordinary_dispatch(): void
    {
        Queue::fake();
        [$generation] = $this->fixture(true);
        // The constructor used its foundation proof; dispatch must not seal any
        // snapshot or release while the original atomic preparation is missing.
        $this->mock(LabDatasetExportService::class, fn ($mock) => $mock->shouldReceive('ensureFoundationDataset')->never());
        $this->artisan('trading:dispatch-lab', ['symbol' => 'XAUUSD', '--resume-draft-agents' => true])->assertExitCode(0);
        $context = (array) $generation->fresh()->trigger_context;
        foreach (['research_release', 'canonical_dataset_snapshots', 'queue_batches'] as $key) $this->assertEmpty($context[$key] ?? null);
        unset($context['native_specialist_council_intent']);
        $generation->forceFill(['trigger_context' => $context])->save();
        $this->assertTrue(app(SpecialistCouncilPreparationService::class)->hasNativeConstructorIntent($generation->fresh()));
        $this->assertSame(['CANONICAL_COUNCIL_ATOMIC_PREPARATION_REQUIRED'],
            app(GenerationSnapshotAdmissionService::class)->inspect($generation->fresh())['reasons']);
        try { app(SpecialistCouncilPreparationService::class)->isResearchGeneration($generation->fresh()); $this->fail('Ordinary authority fallback accepted'); }
        catch (\LogicException $error) { $this->assertSame('CANONICAL_COUNCIL_ATOMIC_PREPARATION_REQUIRED', $error->getMessage()); }
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_prepared_native_constructor_seed_drift_is_not_a_new_or_ordinary_authority(): void
    {
        [$generation, $request, $models] = $this->fixture(true);
        app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
        $metadata = $models[3]->fresh()->metadata;
        $metadata['native_specialist_council_seed']['slot_role'] = 'source_day';
        $models[3]->forceFill(['metadata' => $metadata])->save();
        try { app(SpecialistCouncilPreparationService::class)->isResearchGeneration($generation->fresh()); $this->fail('Resealed source role accepted'); }
        catch (\LogicException $error) { $this->assertSame('CANONICAL_COUNCIL_NATIVE_CONSTRUCTOR_SEED_DRIFT', $error->getMessage()); }
    }

    public function test_actual_prepared_native_constructor_uses_original_comparison_in_normal_dispatch_not_legacy_pair_flags(): void
    {
        [$generation, $request] = $this->fixture(true);
        $inspect = new \ReflectionMethod(\App\Console\Commands\DispatchLabGeneration::class, 'normalCausalAdmission');
        $dispatcher = app(\App\Console\Commands\DispatchLabGeneration::class);
        $this->assertSame('normal_research', data_get($generation->trigger_context, 'research_allocation_budget.mode'));
        $this->assertEmpty(data_get($generation->trigger_context, 'control_pairing_contract'));
        $unprepared = $inspect->invoke($dispatcher, $generation);
        $this->assertFalse($unprepared['allowed']);
        $this->assertSame(['CANONICAL_COUNCIL_ATOMIC_PREPARATION_REQUIRED'], $unprepared['reasons']);
        app(SpecialistCouncilPreparationService::class)->prepare($generation, $request);
        $admitted = $inspect->invoke($dispatcher, $generation->fresh());
        $this->assertTrue($admitted['allowed']);
        $this->assertSame('original_native_council_research_plan', $admitted['owner']);
        // A label or rehashed intake projection is not the original sealed comparison.
        $context = (array) $generation->fresh()->trigger_context;
        $context['specialist_council_preparation']['plan_hash'] = str_repeat('0', 64);
        $generation->forceFill(['trigger_context' => $context])->save();
        $this->assertFalse($inspect->invoke($dispatcher, $generation->fresh())['allowed']);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('paper_authority_admissions', 0);
    }

    private function fixture(bool $nativeConstructor = false, bool $spreadStudy = false): array
    {
        if ($nativeConstructor) {
            config()->set('services.xauusd_organism.historical_research_until_champion', true);
            config()->set('services.market_data.provider', 'csv');
            config()->set('services.lab_selection.constructor_initial_seat_budget', 6);
            app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'native original references');
            $lab = AiLaboratory::create(['name' => 'native-original-intake', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
            $lab->generations()->create(['generation' => 1, 'trigger_type' => 'historical_research', 'status' => 'completed',
                'population_size' => 0, 'trigger_context' => ['data_count' => 0], 'completed_at' => now()]);
            $this->mock(LearningVelocityGateService::class, fn ($mock) => $mock->shouldReceive('inspect')->andReturn(['status' => 'healthy', 'allowed' => true]));
            $this->mock(LabDatasetExportService::class, fn ($mock) => $mock->shouldReceive('ensureFoundationDataset')->andReturn([
                'sha256' => str_repeat('a', 64), 'path' => 'verified-fixture-archive.csv', 'manifest' => ['row_count' => 123000,
                    'first_candle_at' => '2005-01-03T00:00:00Z', 'last_candle_at' => '2025-12-31T23:00:00Z']]));
            $intent = ['protocol' => LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL, 'purpose' => 'research',
                'symbol' => 'XAUUSD', 'storage_timeframe' => 'H1', 'population_size' => 6,
                'creator_id' => 'native-creator', 'research_question' => 'Does this original four-horizon council change outcomes relative to its exact solo and member ablation at equal capital and risk?'];
            if ($spreadStudy) {
                $intent['research_purpose'] = 'spread_context_study';
                $intent['study_context_declaration'] = ['specialist_id' => 'day', 'exact_context' => ['regime' => 'trend_up',
                    'volatility' => 'normal', 'session' => 'london', 'venue_phase' => 'london_interfix', 'direction' => 'BUY'],
                    'spread_context_predicate' => 'normal', 'liquidity_atr_binding' => 'closed_m5_management_atr_v1'];
            }
            $population = app(LabPopulationService::class);
            $generation = $population->build('XAUUSD', 'historical_research', false, 'H1', [], false, false,
                null, null, false, null, $intent);
            $this->assertNotNull($generation, json_encode($population->lastBuildOutcome()));
            $generation->refresh();
            $models = $generation->agents()->with('modelVersion')->orderBy('id')->get()->map(fn ($agent) => $agent->modelVersion);
            $this->assertCount(6, $models, json_encode($generation->trigger_context));
        } else {
        $models = collect(['hour', 'day', 'carrier', 'ablation'])->map(fn (string $name): ModelVersion => $this->model($name));
        $lab = AiLaboratory::create(['name' => 'Prospective native council fixture', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['ema_rsi'], 'is_active' => false]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'historical_research',
            'status' => 'draft', 'population_size' => 4, 'trigger_context' => [], 'started_at' => now()]);
        foreach ($models as $model) LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi', 'origin' => 'test',
            'lifecycle_status' => 'draft', 'parameter_diff' => []]);
        }
        $carrierIndex = $nativeConstructor ? 4 : 2;
        $ablationIndex = $nativeConstructor ? 5 : 3;
        $account = ['id' => 'canonical-execution', 'version' => '1', 'broker_position_mode' => 'hedging', 'opposite_position_policy' => 'hedge',
            'max_open_positions' => 8, 'max_reserved_capital_percent' => 100, 'max_gross_exposure_percent' => 100,
            'max_total_risk_percent' => 2, 'max_drawdown_percent' => 10, 'max_daily_loss_percent' => 3, 'max_expected_cost_percent' => 1];
        $manifest = ['council_id' => 'prospective-native-council', 'version' => '1',
            'members' => $nativeConstructor ? array_map(fn ($role, $index) => $this->passport($role, $models[$index]),
                ['scalp', 'hour', 'day', 'swing'], [0, 1, 2, 3]) : [$this->passport('hour', $models[0]), $this->passport('day', $models[1])], 'components' => [],
            'routing' => ['id' => 'scope-router', 'version' => '1'], 'allocation' => ['id' => 'shared-capital', 'version' => '1'],
            'risk' => ['id' => 'external-hard-risk', 'version' => '1'], 'execution' => $account,
            'evaluation_policy' => ['objective' => 'net_return_at_equal_risk', 'champion_model_version_id' => $models[0]->id,
                'solo_model_version_id' => $models[0]->id]];
        if ($nativeConstructor) foreach ($manifest['members'] as &$member) {
            $member['capital_weight'] = .25;
            $member['horizon']['max_holding_seconds'] = match ($member['role']) {
                'scalp' => 3600, 'hour' => 10800, 'day' => 43200, 'swing' => 259200,
            };
        }
        unset($member);
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
            'arms' => [['arm_key' => 'candidate', 'kind' => 'candidate', 'window_key' => 'original-window', 'model_version_id' => $models[$carrierIndex]->id],
                ['arm_key' => 'solo', 'kind' => 'solo', 'window_key' => 'original-window', 'model_version_id' => $models[0]->id],
                ['arm_key' => 'without-hour', 'kind' => 'ablation', 'removed_id' => 'hour', 'window_key' => 'original-window', 'model_version_id' => $models[$ablationIndex]->id]]];
        if ($nativeConstructor) $plan['solo_comparison'] = [
            'protocol' => \App\Services\SpecialistCouncilContractService::NATIVE_SOLO_PROTOCOL,
            'comparison_kind' => 'matched_member_allocation', 'specialist_id' => 'scalp', 'best_solo_full_budget_proven' => false];
        return [$generation, ['protocol' => SpecialistCouncilPreparationService::PROTOCOL, 'creator_id' => 'native-creator',
            'research_question' => $nativeConstructor ? $intent['research_question'] : 'Does this two-horizon council change native outcomes relative to the exact solo and one member ablation at equal capital and risk?',
            'evaluator_id' => 'native-evaluator', 'carrier_model_version_id' => $models[$carrierIndex]->id, 'manifest' => $manifest,
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
                'max_holding_seconds' => 3600, 'execution_precision' => 'candle'],
            'data_requirements' => match ($role) {
                'scalp' => ['bid_ask', 'spread', 'slippage', 'quote_age', 'intrabar_ambiguity'],
                'swing' => ['gap', 'carry', 'rollover', 'mature_holding_outcomes'],
                default => ['sessions', 'costs'],
            },
            'model_version_id' => $model->id, 'strategy_version' => 'strategy-v1', 'tactic_version' => 'tactic-v1',
            'management_version' => 'management-v1', 'capital_weight' => .5, 'risk_per_trade_percent' => .5,
            'sensor_timeframes' => ['H4', 'H1', 'M15', 'M5']];
    }
}
