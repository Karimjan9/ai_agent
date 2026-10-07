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

    private function discoveryFixture(bool $nativeConstructor = false): array
    {
        [$generation, $request, $models] = $this->fixture($nativeConstructor);
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

    private function fixture(bool $nativeConstructor = false): array
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
