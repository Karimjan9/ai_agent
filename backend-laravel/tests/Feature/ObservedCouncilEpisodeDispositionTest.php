<?php

namespace Tests\Feature;

use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningSettlement;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\LabLifecycleEvent;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentReceipt;
use App\Models\ResearchExperimentWorkItem;
use App\Models\SpecialistCouncilVersion;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabQueueJobInspector;
use App\Services\ObservedCouncilEpisodeDispositionService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\ResearchReleaseSealService;
use App\Services\SpecialistCouncilLifecycleService;
use App\Services\SpecialistCouncilResearchFeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Conditional original attestations test operational closure, not a market/skill proof. */
class ObservedCouncilEpisodeDispositionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ordinary_generation_is_not_applicable_and_remains_unchanged(): void
    {
        [$generation] = $this->fixture();
        $generation->update(['trigger_context' => []]);
        $before = $this->immutableSnapshot();
        $owner = app(ObservedCouncilEpisodeDispositionService::class);
        $this->assertSame('not_applicable', $owner->inspectGeneration($generation)['status']);
        $this->assertSame('not_applicable', $owner->reconcileGeneration($generation)['status']);
        $this->assertSame($before, $this->immutableSnapshot());
        $this->assertDatabaseCount('agent_learning_settlements', 0);
        $this->assertDatabaseCount('agent_learning_episodes', 6);
    }

    public function test_inspection_is_pure_and_all_six_close_with_zero_authority_without_rewriting_originals(): void
    {
        [$generation] = $this->fixture();
        $owner = app(ObservedCouncilEpisodeDispositionService::class);
        $before = $this->immutableSnapshot();
        $inspection = $owner->inspectGeneration($generation);
        $this->assertTrue($inspection['allowed'], $inspection['reason_code']);
        $this->assertCount(6, $inspection['proof']['originals']);
        $this->assertSame('technical_quarantine', $inspection['proof']['generation_terminal_status']);
        $this->assertSame($before, $this->immutableSnapshot());
        $this->assertDatabaseCount('agent_learning_settlements', 0);
        $this->assertSame(6, AgentLearningEpisode::where('status', 'open')->count());

        $result = $owner->reconcileGeneration($generation);
        $this->assertSame('settled_zero_authority', $result['status']);
        $this->assertCount(6, $result['settlement_ids']);
        $this->assertSame($before, $this->immutableSnapshot());
        $this->assertSame(6, AgentLearningEpisode::where('status', 'settled')->count());
        foreach (AgentLearningSettlement::all() as $settlement) {
            $this->assertSame(0.0, $settlement->selection_reward);
            $this->assertFalse($settlement->hard_failure);
            $this->assertSame('neutral', $settlement->evidence_state);
            $this->assertSame('authority_withheld', $settlement->outcome_status);
            $this->assertSame('projection_withheld', $settlement->failure_class);
            $this->assertSame([], $settlement->outcome['metrics']);
            $this->assertFalse($settlement->outcome['causal_credit_allowed']);
            $this->assertFalse($settlement->outcome['economic_credit_allowed']);
            $this->assertFalse($settlement->outcome['selection_reward_authorized']);
            $this->assertFalse($settlement->outcome['quality_verdict_changed']);
            $this->assertFalse($settlement->outcome['promotion_evidence']);
            $this->assertSame('none', $settlement->reward_components['signal_authority']);
            $this->assertFalse($settlement->reflection['scientific_lesson_inferred']);
        }
        $this->assertSame('screening', $generation->fresh()->status, 'Only the existing terminal boundary may close the generation.');
        $this->assertNoDerivedAuthority();
    }

    public function test_reconciliation_retry_preserves_settlement_ids_contents_and_all_timestamps(): void
    {
        [$generation] = $this->fixture();
        $owner = app(ObservedCouncilEpisodeDispositionService::class);
        $first = $owner->reconcileGeneration($generation);
        $episodes = DB::table('agent_learning_episodes')->orderBy('id')->get()->toJson();
        $settlements = DB::table('agent_learning_settlements')->orderBy('id')->get()->toJson();
        $originals = $this->immutableSnapshot();
        $this->travel(1)->hours();
        $second = $owner->reconcileGeneration($generation);
        $this->assertSame($first['settlement_ids'], $second['settlement_ids']);
        $this->assertSame($episodes, DB::table('agent_learning_episodes')->orderBy('id')->get()->toJson());
        $this->assertSame($settlements, DB::table('agent_learning_settlements')->orderBy('id')->get()->toJson());
        $this->assertSame($originals, $this->immutableSnapshot());
        $this->assertNoDerivedAuthority();
    }

    #[DataProvider('invalidOriginalCases')]
    public function test_forged_partial_or_unbound_originals_cannot_close_any_episode(string $poison, string $reason): void
    {
        [$generation, $work, $version, $receipt, $agents] = $this->fixture();
        if ($poison === 'unsigned_body') {
            $payload = $work->payload;
            data_set($payload, 'followup_resolution.scientific_novelty_claimed', true);
            $work->update(['payload' => $payload]);
        } elseif ($poison === 'root_duplicate') {
            $duplicate = $work->replicate();
            $duplicate->work_key = hash('sha256', 'duplicate-conditional-root-claim');
            $duplicate->save();
        } elseif ($poison === 'root_missing') {
            $payload = $work->payload;
            data_set($payload, 'followup_resolution.original_observed_probe_completion_proof.root.root_version_id', 0);
            $work->update(['payload' => $payload]);
        } elseif ($poison === 'withholding_missing') {
            LabLifecycleEvent::where('lab_agent_id', $agents[5]->id)->delete();
        } elseif ($poison === 'withholding_forged') {
            $event = LabLifecycleEvent::where('lab_agent_id', $agents[5]->id)->where('event_type', 'screening_learning_projection_withheld')->sole();
            $event->update(['payload' => [...$event->payload, 'root_version_id' => 999]]);
        } elseif ($poison === 'feedback_hash') {
            $receipt->update(['evidence_hash' => str_repeat('0', 64)]);
        } elseif ($poison === 'feedback_reclassified') {
            $receipt->update(['classification' => 'LOCAL_NEGATIVE']);
        } elseif ($poison === 'assessment_hash') {
            $version->update(['assessment_hash' => str_repeat('0', 64)]);
        } elseif ($poison === 'partial_cohort') {
            $agents[5]->delete();
        } elseif ($poison === 'run_pending') {
            LabEvaluationRun::where('lab_agent_id', $agents[5]->id)->update(['status' => 'running', 'finished_at' => null]);
        } elseif ($poison === 'duplicate_run') {
            $copy = LabEvaluationRun::where('lab_agent_id', $agents[5]->id)->sole()->replicate();
            $copy->run_id = (string) Str::uuid();
            $copy->save();
        } elseif ($poison === 'missing_episode') {
            AgentLearningEpisode::where('lab_agent_id', $agents[5]->id)->delete();
        } elseif ($poison === 'episode_owner') {
            AgentLearningEpisode::where('lab_agent_id', $agents[5]->id)->update(['model_version_id' => $agents[0]->model_version_id]);
        } elseif ($poison === 'queue_unknown') {
            $this->mock(LabQueueJobInspector::class)->shouldReceive('generationQueueBacklog')->andReturn(['available' => false, 'total' => null]);
        }
        $before = $this->immutableSnapshot();
        $episodes = DB::table('agent_learning_episodes')->orderBy('id')->get()->toJson();
        $owner = app(ObservedCouncilEpisodeDispositionService::class);
        $result = $owner->reconcileGeneration($generation);
        $this->assertFalse($result['allowed']);
        $this->assertSame($reason, $result['reason_code']);
        $this->assertDatabaseCount('agent_learning_settlements', 0);
        $this->assertSame($episodes, DB::table('agent_learning_episodes')->orderBy('id')->get()->toJson());
        $this->assertSame($before, $this->immutableSnapshot());
        $this->assertNoDerivedAuthority();
    }

    public static function invalidOriginalCases(): array
    {
        return [
            ['unsigned_body', 'OBSERVED_COUNCIL_SIGNED_PROJECTION_OWNER_INVALID'],
            ['root_duplicate', 'OBSERVED_COUNCIL_GLOBAL_ROOT_CLAIM_INVALID'],
            ['root_missing', 'OBSERVED_COUNCIL_GLOBAL_ROOT_CLAIM_INVALID'],
            ['withholding_missing', 'OBSERVED_COUNCIL_ORIGINAL_WITHHOLDING_INVALID'],
            ['withholding_forged', 'OBSERVED_COUNCIL_ORIGINAL_WITHHOLDING_INVALID'],
            ['feedback_hash', 'OBSERVED_COUNCIL_IMMUTABLE_FEEDBACK_INVALID'],
            ['feedback_reclassified', 'OBSERVED_COUNCIL_IMMUTABLE_FEEDBACK_INVALID'],
            ['assessment_hash', 'OBSERVED_COUNCIL_ORIGINAL_ASSESSMENT_INVALID'],
            ['partial_cohort', 'OBSERVED_COUNCIL_EXACT_TERMINAL_SIX_REQUIRED'],
            ['run_pending', 'OBSERVED_COUNCIL_ORIGINAL_COMPLETED_RUN_REQUIRED'],
            ['duplicate_run', 'OBSERVED_COUNCIL_ORIGINAL_COMPLETED_RUN_REQUIRED'],
            ['missing_episode', 'OBSERVED_COUNCIL_ORIGINAL_SINGLE_EPISODE_REQUIRED'],
            ['episode_owner', 'OBSERVED_COUNCIL_ORIGINAL_SINGLE_EPISODE_REQUIRED'],
            ['queue_unknown', 'OBSERVED_COUNCIL_PHYSICAL_WORK_NOT_DRAINED'],
        ];
    }

    public function test_other_owner_settlement_collision_is_rejected_atomically(): void
    {
        [$generation, , , , $agents] = $this->fixture();
        $episode = AgentLearningEpisode::where('lab_agent_id', $agents[5]->id)->sole();
        $this->otherOwnerSettlement($episode);
        $before = DB::table('agent_learning_settlements')->get()->toJson();
        $result = app(ObservedCouncilEpisodeDispositionService::class)->reconcileGeneration($generation);
        $this->assertFalse($result['allowed']);
        $this->assertSame('OBSERVED_COUNCIL_EPISODE_OTHER_OWNER_COLLISION', $result['reason_code']);
        $this->assertSame($before, DB::table('agent_learning_settlements')->get()->toJson());
        $this->assertSame(6, AgentLearningEpisode::where('status', 'open')->count());
    }

    public function test_collision_arriving_after_inspection_is_rechecked_under_the_episode_lock(): void
    {
        [$generation, , , , $agents] = $this->fixture();
        $real = app(ObservedCouncilEpisodeDispositionService::class);
        $owner = Mockery::mock(ObservedCouncilEpisodeDispositionService::class, [
            app(SpecialistCouncilResearchFeedbackService::class), app(LabImmutableEvidenceService::class),
            app(ResearchPaperEpochContractService::class), app(ResearchReleaseSealService::class), app(LabQueueJobInspector::class),
        ])->makePartial();
        $owner->shouldReceive('inspectGeneration')->once()->andReturnUsing(function ($locked) use ($real, $agents) {
            $result = $real->inspectGeneration($locked);
            $this->assertTrue($result['allowed'], $result['reason_code']);
            // Simulated race at the inspection/locked-write seam, not a production write.
            $this->otherOwnerSettlement(AgentLearningEpisode::where('lab_agent_id', $agents[5]->id)->sole());
            return $result;
        });
        try {
            $owner->reconcileGeneration($generation);
            $this->fail('A late conflicting settlement must abort all six writes.');
        } catch (\LogicException $error) {
            $this->assertSame('OBSERVED_COUNCIL_EPISODE_OTHER_OWNER_COLLISION', $error->getMessage());
        }
        // The whole compensation transaction, including the simulated late writer, rolled back.
        $this->assertDatabaseCount('agent_learning_settlements', 0);
        $this->assertSame(6, AgentLearningEpisode::where('status', 'open')->count());
        $this->assertNoDerivedAuthority();
    }

    public function test_settle_only_paused_dependency_is_deferred_not_a_completed_scheduler_transition(): void
    {
        $classifier = app(\App\Services\ScheduledCommandOutcomeClassifierService::class);
        foreach (['OBSERVED_COUNCIL_ORIGINAL_WITHHOLDING_INVALID', 'SETTLEMENT_WATERMARK_NOT_TERMINAL'] as $reason) {
            $result = $classifier->classify('trading:run-lifecycle-cycle',
                ['--settle-only' => true, '--expected-generation-id' => 1, '--json' => true], 0,
                json_encode(['status' => 'paused', 'data' => ['terminal_boundary' => ['closed' => false, 'reason_code' => $reason]]]));
            $this->assertSame('deferred', $result['status']);
            $this->assertSame('lifecycle_transition_not_achieved', $result['reason']);
        }
        $this->assertDatabaseCount('agent_learning_settlements', 0);
    }

    private function fixture(): array
    {
        Storage::fake('observed-council-fixture');
        config(['services.lab_evidence.disk' => 'observed-council-fixture']);
        $legacy = new SpecialistCouncilObservedProjectionGuardTest('test_signed_observed_completion_skips_every_derived_projection_even_with_positive_eligible_economics');
        $app = new \ReflectionProperty(TestCase::class, 'app');
        $app->setValue($legacy, $app->getValue($this));
        [$agent, $run, , $response, $work] = (new \ReflectionMethod($legacy, 'fixture'))->invoke($legacy);
        $generation = $agent->generation;
        $generation->update(['population_size' => 6, 'status' => 'screening']);
        $roles = ['source_scalp', 'source_hour', 'source_day', 'source_swing', 'candidate_carrier', 'ablation_carrier'];
        $agents = []; $runs = [];
        foreach ($roles as $index => $role) {
            if ($index === 0) {
                $member = $agent; $original = $run;
            } else {
                $model = $agent->modelVersion->replicate();
                $model->name = 'conditional-episode-member-'.$index;
                $model->version = 'conditional-episode-member-'.$index;
                $model->save();
                $member = $agent->replicate();
                $member->model_version_id = $model->id;
                $member->save();
                $original = $run->replicate();
                $original->run_id = (string) Str::uuid();
                $original->lab_agent_id = $member->id;
                $original->model_version_id = $model->id;
                $original->save();
            }
            $member->unsetRelations()->load('modelVersion', 'generation');
            $member->update(['lifecycle_status' => 'screened']);
            $episode = AgentLearningEpisode::create(['episode_id' => (string) Str::uuid(),
                'decision_key' => 'conditional-zero-authority:'.$member->id,
                'lab_agent_id' => $member->id, 'model_version_id' => $member->model_version_id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $member->strategy_family,
                'stage' => 'mutation_selection', 'status' => 'open', 'context_hash' => hash('sha256', 'conditional-empty-context'),
                'decision_context' => [], 'observations' => [], 'opened_at' => now()->subHour()]);
            $metadata = $member->modelVersion->metadata;
            data_set($metadata, 'native_specialist_council_seed.slot_role', $role);
            data_set($metadata, 'learning_decision.episode_id', $episode->id);
            $member->modelVersion->update(['metadata' => $metadata]);
            $agents[] = $member->fresh(['modelVersion', 'generation']);
            $runs[] = $original->fresh();
        }
        $epochs = app(ResearchPaperEpochContractService::class);
        $manifest = ['conditional_fixture' => true];
        $manifest['manifest_hash'] = $epochs->parameterHash($manifest);
        $version = SpecialistCouncilVersion::create(['council_id' => 'conditional-zero-authority', 'version' => '1',
            'creator_id' => 'conditional-creator', 'state' => 'evaluated', 'manifest' => $manifest,
            'manifest_hash' => $manifest['manifest_hash'], 'sealed_at' => now()->subDay()]);
        $plan = ['conditional_original_plan' => true];
        $planHash = $epochs->parameterHash($plan);
        $originalIds = array_map(fn ($original) => $original->run_id, $runs);
        $assessment = ['protocol' => SpecialistCouncilLifecycleService::ASSESSMENT_PROTOCOL,
            'version_id' => $version->id, 'manifest_hash' => $version->manifest_hash, 'plan_hash' => $planHash,
            'qualified' => false, 'original_run_ids' => $originalIds, 'research_observation_status' => 'technical_unassessable'];
        $version->update(['assessment' => $assessment, 'assessment_hash' => $epochs->parameterHash($assessment)]);
        DB::table('specialist_council_evaluation_plans')->insert(['specialist_council_version_id' => $version->id,
            'evaluator_id' => 'conditional-independent-evaluator', 'plan' => json_encode($plan), 'plan_hash' => $planHash,
            'sealed_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('specialist_council_evaluations')->insert(['specialist_council_version_id' => $version->id,
            'evaluator_id' => 'conditional-independent-evaluator', 'original_run_ids' => json_encode($originalIds),
            'assessment' => json_encode($assessment), 'assessment_hash' => $version->assessment_hash,
            'created_at' => now(), 'updated_at' => now()]);
        $receipt = ResearchExperimentReceipt::findOrFail($work->research_experiment_receipt_id);
        $contract = ['source' => ['type' => SpecialistCouncilVersion::class, 'id' => $version->id]];
        $facts = ['assessment_hash' => $version->assessment_hash, 'plan_hash' => $planHash,
            'research_observation_status' => 'technical_unassessable', 'original_run_ids' => $originalIds];
        $receipt->update(['source_id' => $version->id, 'contract_hash' => $epochs->parameterHash($contract),
            'evidence_hash' => $epochs->parameterHash($facts), 'payload' => ['contract' => $contract, 'evidence' => $facts]]);
        $body = data_get($work->payload, 'followup_resolution');
        $work->update(['result' => ['status' => 'canonical_dispatch_admitted', 'generation_id' => $generation->id,
            'resolution_hash' => $body['resolution_hash']]]);
        $context = $generation->trigger_context;
        $context['specialist_council_preparation'] = ['version_id' => $version->id, 'plan_hash' => $planHash,
            'manifest_hash' => $version->manifest_hash];
        $context['research_release'] = ['release_hash' => hash('sha256', 'conditional-frozen-release')];
        $generation->update(['trigger_context' => $context]);
        foreach ($agents as $index => $member) {
            $disposition = app(SpecialistCouncilResearchFeedbackService::class)->screeningProjectionDisposition($member->fresh(['generation', 'modelVersion']), $runs[$index]);
            $this->assertSame('OBSERVED_COUNCIL_TECHNICAL_COMPLETION_WITHHOLDS_DERIVED_LEARNING', $disposition['reason_code']);
            LabLifecycleEvent::create(['event_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
                'lab_agent_id' => $member->id, 'run_id' => $runs[$index]->run_id, 'phase' => 'screening',
                'event_type' => 'screening_learning_projection_withheld', 'reason_code' => $disposition['reason_code'],
                'payload' => $disposition, 'occurred_at' => now()]);
        }
        // Original request/response gzip bytes, artifact owners and hashes are real.
        // Only runtime/archive attestation and physical queue idle are conditional.
        $evidence = app(LabImmutableEvidenceService::class);
        $canonicalResponse = $evidence->latestArtifactPayload($run, 'evaluation_response');
        foreach ($runs as $original) {
            // Replace only the helper's conditional test artifacts before sealing
            // this fixture's original record; never a production/history repair.
            LabEvidenceArtifact::where('run_id', $original->run_id)->whereIn('artifact_type', ['evaluation_request', 'evaluation_response'])->delete();
            $request = ['research_release' => ['source_hash' => $original->code_hash, 'dataset_hash' => $original->data_hash,
                'release_hash' => $context['research_release']['release_hash'], 'python_source_hash' => str_repeat('e', 64),
                'source_artifact' => ['artifact_hash' => str_repeat('f', 64)]]];
            $evidence->recordArtifact($original, 'evaluation_request', $request, ['request_hash' => $original->request_hash]);
            $responseArtifact = $evidence->recordArtifact($original, 'evaluation_response', $canonicalResponse);
            $original->update(['response_hash' => $responseArtifact->sha256, 'finished_at' => now()]);
        }
        $this->partialMock(LabImmutableEvidenceService::class, function ($mock) {
            $mock->shouldReceive('verifiedModelRuntimeIdentity')->andReturn(['conditional_test_attestation' => true]);
        });
        $this->mock(ResearchReleaseSealService::class, function ($mock) use ($run) {
            $mock->shouldReceive('responseValid')->andReturn(true);
            $mock->shouldReceive('verifySourceArtifact')->andReturn(['manifest' => ['source_identity' => [
                'source_hash' => $run->code_hash, 'python_source_hash' => str_repeat('e', 64)]]]);
        });
        $this->mock(LabQueueJobInspector::class)->shouldReceive('generationQueueBacklog')->andReturn(['available' => true, 'total' => 0]);
        return [$generation->fresh(), $work->fresh(), $version->fresh(), $receipt->fresh(), $agents];
    }

    private function otherOwnerSettlement(AgentLearningEpisode $episode): AgentLearningSettlement
    {
        return AgentLearningSettlement::create(['settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id,
            'source_key' => 'conditional-other-owner:'.$episode->id, 'source_type' => self::class,
            'outcome_status' => 'settled', 'evidence_state' => 'positive', 'selection_reward' => 0.5,
            'hard_failure' => false, 'outcome' => ['conditional_other_owner' => true], 'settled_at' => now()]);
    }

    private function immutableSnapshot(): array
    {
        $tables = ['lab_generations', 'lab_agents', 'model_versions', 'lab_evaluation_runs', 'lab_evidence_artifacts',
            'lab_lifecycle_events', 'candidate_gate_decisions', 'research_experiment_receipts', 'research_experiment_work_items',
            'specialist_council_versions', 'specialist_council_evaluation_plans', 'specialist_council_evaluations'];
        $snapshot = [];
        foreach ($tables as $table) if (Schema::hasTable($table)) $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        return $snapshot;
    }

    private function assertNoDerivedAuthority(): void
    {
        foreach (['lab_evolution_credit_events', 'agent_learning_lessons', 'agent_learning_retrievals', 'lab_mutation_response_maps',
            'agent_knowledge_cards', 'agent_memories', 'provisional_skill_cartridges', 'instrument_value_posteriors', 'playbook_value_posteriors'] as $table) {
            if (Schema::hasTable($table)) $this->assertDatabaseCount($table, 0);
        }
    }
}
