<?php

namespace Tests\Feature;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\ResearchLoopDecision;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\AcademyExperimentMaterializerService;
use App\Services\AcademyExperimentContractCompilerService;
use App\Services\LabImmutableEvidenceService;
use App\Services\AcademyExperimentSettlementReconcilerService;
use App\Services\StrategyParameterSchemaService;
use App\Services\XauusdEdgeFormationAcademyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AcademyExperimentMaterializerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_admits_a_compiled_pre2026_academy_cohort(): void
    {
        $lab = AiLaboratory::create(['name' => 'academy materializer', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['confirmation_entry_mtf'], 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'fixture', 'status' => 'completed']);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf');
        $model = ModelVersion::create(['name' => 'academy baseline', 'strategy' => 'academy_baseline', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => $parameters, 'metadata' => ['base_strategy' => 'confirmation_entry_mtf_v1']]);
        $parameters = (array) $model->fresh()->parameters;
        LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf', 'origin' => 'fixture', 'lifecycle_status' => 'completed', 'parameter_diff' => []]);
        $passport = app(XauusdEdgeFormationAcademyService::class)->passport('XAUUSD', 'H1', ['composition_key' => 'academy-materializer', 'strategy_family' => 'confirmation_entry_mtf', 'deepest_stage' => 'trigger_entry_specialist',
            'baseline_model_version_id' => $model->id, 'baseline_parameters' => $parameters,
            'baseline_parameter_hash' => app(AcademyExperimentContractCompilerService::class)->parameterHash($parameters),
            'prospective_source_identity' => $this->identity()]);
        $frozen = \DB::table('edge_academy_passports')->where('id', $passport['passport_id'])->value('frozen_upstream_contract');
        $trialId = \DB::table('edge_academy_trials')->insertGetId(['trial_key' => 'academy-materializer-trial', 'edge_academy_passport_id' => $passport['passport_id'],
            'trial_type' => 'fixture', 'status' => 'planned', 'frozen_contract' => $frozen, 'density_contract' => '{}', 'arms' => json_encode([
                ['role' => 'frozen_control', 'changed_axis' => 'trigger_topology_policy', 'value' => 'frozen_current'],
                ['role' => 'candidate', 'changed_axis' => 'trigger_topology_policy', 'value' => 'aggressive_structure_close'],
                ['role' => 'candidate', 'changed_axis' => 'trigger_topology_policy', 'value' => 'balanced_retest_reaction'],
                ['role' => 'candidate', 'changed_axis' => 'trigger_topology_policy', 'value' => 'conservative_continuation'],
                ['role' => 'blinded_control', 'changed_axis' => 'trigger_topology_policy', 'value' => 'trigger_blinded_control'],
            ]), 'created_at' => now(), 'updated_at' => now()]);
        $result = app(AcademyExperimentMaterializerService::class)->materialize($trialId, $model->id, ['pre_2026_only' => true, 'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64), 'canonical_dataset_snapshots' => []]);

        $this->assertSame('would_queue', $result['status'], $result['reason'] ?? '');
        $this->assertCount(5, $result['compiled_contract']['arms']);
    }

    public function test_full_cohort_settles_only_after_every_arm_has_matching_evidence(): void
    {
        Queue::fake();
        $this->test_it_only_admits_a_compiled_pre2026_academy_cohort();
        $trialId = (int) \DB::table('edge_academy_trials')->where('trial_key', 'academy-materializer-trial')->value('id');
        \DB::table('edge_academy_trials')->where('id', $trialId)->update(['density_contract' => json_encode([
            'minimum_setup_events' => 20, 'minimum_trigger_events' => 12, 'minimum_closed_trades' => 8,
        ])]);
        $baseline = ModelVersion::query()->where('name', 'academy baseline')->firstOrFail();
        $service = app(AcademyExperimentMaterializerService::class);
        $queued = $service->admit($trialId, $baseline->id, $this->identity(), $this->decision());
        $this->assertSame(20, LabAgent::query()->where('lab_generation_id', $queued['generation_id'])->count());
        $agents = LabAgent::query()->where('lab_generation_id', $queued['generation_id'])->where('origin', 'academy_experiment')->with('modelVersion')->get();
        $this->assertCount(5, $agents);
        Queue::assertNothingPushed();
        $this->assertSame('pending_canonical_admission', $queued['status']);
        $this->assertSame(20, LabAgent::query()->where('lab_generation_id', $queued['generation_id'])->where('lifecycle_status', 'draft')->count());
        foreach ($agents as $agent) {
            $this->recordArmResult($agent, [
                'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64), 'total_trades' => 8,
                'net_r' => data_get($agent->modelVersion->metadata, 'academy_experiment.arm_role') === 'candidate' ? .2 : .1,
                'entry_contract_funnel' => ['stage_counts' => ['setup' => 20, 'trigger' => 12]],
            ]);
        }

        $reconciliation = app(AcademyExperimentSettlementReconcilerService::class)->reconcile('XAUUSD', 'H1', true);
        $this->assertSame('reconciled', $reconciliation['status']);
        $this->assertSame(1, $reconciliation['settled_count']);
        $settled = $reconciliation['outcomes'][0]['result'];

        $this->assertSame('settled_powered', $settled['status']);
        $this->assertSame('POSITIVE_CANDIDATE', $settled['classification']);
        $this->assertSame('unassessable', data_get(json_decode(\DB::table('edge_academy_trials')->find($trialId)->outcome, true), 'outcome.behavioral_proof.status'));
        $this->assertDatabaseCount('edge_academy_passports', 1);
        $this->assertDatabaseCount('causal_stage_mastery_assessments', 0);
        $this->assertNotNull(data_get($settled, 'conversion_receipt.work_id'));
        $this->assertDatabaseHas('edge_academy_trials', ['id' => $trialId, 'status' => 'settled_powered']);
        $this->assertDatabaseHas('research_experiment_receipts', ['source_type' => 'edge_academy_trial', 'source_id' => $trialId, 'classification' => 'POSITIVE_CANDIDATE']);
        $this->assertDatabaseHas('research_experiment_work_items', ['work_type' => 'academy_independent_replication', 'status' => 'blocked']);
        $work = \App\Models\ResearchExperimentWorkItem::query()->where('work_type', 'academy_independent_replication')->sole();
        $this->assertSame('NEW_INDEPENDENT_WINDOW_CONTRACT_REQUIRED', data_get($work->payload, 'retry_condition.code'));
        $this->assertSame(\App\Services\ResearchLoopArbiterService::class, data_get($work->payload, 'owner'));
    }

    public function test_cohort_power_cannot_be_created_by_summing_underpowered_arms(): void
    {
        Queue::fake();
        $this->test_it_only_admits_a_compiled_pre2026_academy_cohort();
        $trialId = (int) \DB::table('edge_academy_trials')->where('trial_key', 'academy-materializer-trial')->value('id');
        \DB::table('edge_academy_trials')->where('id', $trialId)->update(['density_contract' => json_encode([
            'minimum_setup_events' => 20, 'minimum_trigger_events' => 12, 'minimum_closed_trades' => 8,
        ])]);
        $baseline = ModelVersion::query()->where('name', 'academy baseline')->firstOrFail();
        $queued = app(AcademyExperimentMaterializerService::class)->admit($trialId, $baseline->id, $this->identity(), $this->decision());
        $agents = LabAgent::query()->where('lab_generation_id', $queued['generation_id'])->where('origin', 'academy_experiment')->with('modelVersion')->get();
        foreach ($agents->values() as $index => $agent) {
            $this->recordArmResult($agent, [
                'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64), 'total_trades' => 8, 'net_r' => .1,
                'entry_contract_funnel' => ['stage_counts' => ['setup' => $index === 0 ? 19 : 20, 'trigger' => 12]],
            ]);
        }

        $settled = app(AcademyExperimentMaterializerService::class)->settleOutcome($agents->first());

        $this->assertSame('settled_without_economic_claim', $settled['status']);
        $this->assertSame('UNDERPOWERED', $settled['classification']);
        $this->assertDatabaseHas('research_experiment_receipts', ['source_id' => $trialId, 'classification' => 'UNDERPOWERED']);
        $this->assertDatabaseHas('research_experiment_work_items', ['work_type' => 'academy_power_extension', 'status' => 'blocked']);
        $this->assertSame('NEW_INDEPENDENT_POWERED_WINDOW_REQUIRED', data_get(
            \App\Models\ResearchExperimentWorkItem::query()->where('work_type', 'academy_power_extension')->sole()->payload,
            'retry_condition.code',
        ));
    }

    public function test_hash_mismatch_is_quarantined_with_a_receipt_and_repair_work(): void
    {
        Queue::fake();
        $this->test_it_only_admits_a_compiled_pre2026_academy_cohort();
        $trialId = (int) \DB::table('edge_academy_trials')->where('trial_key', 'academy-materializer-trial')->value('id');
        $baseline = ModelVersion::query()->where('name', 'academy baseline')->firstOrFail();
        $queued = app(AcademyExperimentMaterializerService::class)->admit($trialId, $baseline->id, $this->identity(), $this->decision());
        $agents = LabAgent::query()->where('lab_generation_id', $queued['generation_id'])->where('origin', 'academy_experiment')->with('modelVersion')->get();
        foreach ($agents as $agent) {
            $this->recordArmResult($agent, [
                'data_hash' => str_repeat('c', 64), 'execution_hash' => str_repeat('b', 64), 'total_trades' => 8,
            ]);
        }

        $settled = app(AcademyExperimentMaterializerService::class)->settleOutcome($agents->first());

        $this->assertSame('technical_quarantine', $settled['status']);
        $this->assertSame('TECHNICAL_QUARANTINE', $settled['classification']);
        $this->assertDatabaseHas('research_experiment_receipts', ['source_id' => $trialId, 'classification' => 'TECHNICAL_QUARANTINE']);
        $this->assertDatabaseHas('research_experiment_work_items', ['work_type' => 'academy_technical_quarantine', 'status' => 'blocked']);
        $this->assertSame('TECHNICAL_ROOT_CAUSE_REPAIR_REQUIRED', data_get(
            \App\Models\ResearchExperimentWorkItem::query()->where('work_type', 'academy_technical_quarantine')->sole()->payload,
            'retry_condition.code',
        ));
    }

    public function test_repeated_materialization_reuses_one_draft_generation_and_pollable_dispatch_intent(): void
    {
        Queue::fake();
        $this->test_it_only_admits_a_compiled_pre2026_academy_cohort();
        $trialId = (int) \DB::table('edge_academy_trials')->where('trial_key', 'academy-materializer-trial')->value('id');
        $baseline = ModelVersion::query()->where('name', 'academy baseline')->sole();
        $service = app(AcademyExperimentMaterializerService::class);
        $decision = $this->decision();
        $first = $service->admit($trialId, $baseline->id, $this->identity(), $decision);
        $second = $service->admit($trialId, $baseline->id, $this->identity(), $decision);
        $this->assertSame($first['generation_id'], $second['generation_id']);
        $this->assertTrue($second['reused']);
        $resumed = $service->admit($trialId, $baseline->id, $this->identity(), $this->decision());
        $this->assertSame($first['generation_id'], $resumed['generation_id']);
        $differentIdentity = [...$this->identity(), 'data_hash' => str_repeat('d', 64)];
        $this->assertSame('ACADEMY_PROSPECTIVE_IDENTITY_MISMATCH', $service->admit($trialId, $baseline->id, $differentIdentity, $decision)['reason']);
        $this->assertSame(2, LabGeneration::query()->count());
        $this->assertSame(20, LabAgent::query()->where('lab_generation_id', $first['generation_id'])->count());
        $proposal = $service->proposal();
        $this->assertSame('pending_canonical_admission', $proposal['status']);
        $this->assertSame($first['generation_id'], $proposal['generation_id']);
        $this->assertSame('blocked', $service->confirmCanonicalAdmission($trialId, $first['generation_id'])['status']);
        Queue::assertNothingPushed();
    }

    public function test_apply_without_durable_arbiter_and_mutable_projection_only_evidence_are_not_admitted(): void
    {
        $this->test_it_only_admits_a_compiled_pre2026_academy_cohort();
        $trialId = (int) \DB::table('edge_academy_trials')->where('trial_key', 'academy-materializer-trial')->value('id');
        $baseline = ModelVersion::query()->where('name', 'academy baseline')->sole();
        $this->assertSame('ACADEMY_DURABLE_ARBITER_ADMISSION_REQUIRED', app(AcademyExperimentMaterializerService::class)->materialize($trialId, $baseline->id, $this->identity(), true)['reason']);
        $this->assertSame(1, LabGeneration::query()->count());
    }

    public function test_missing_primary_arm_cannot_settle_as_a_complete_comparison(): void
    {
        Queue::fake();
        $this->test_it_only_admits_a_compiled_pre2026_academy_cohort();
        $trialId = (int) \DB::table('edge_academy_trials')->where('trial_key', 'academy-materializer-trial')->value('id');
        $baseline = ModelVersion::query()->where('name', 'academy baseline')->sole();
        $service = app(AcademyExperimentMaterializerService::class);
        $prepared = $service->admit($trialId, $baseline->id, $this->identity(), $this->decision());
        $primary = LabAgent::query()->where('lab_generation_id', $prepared['generation_id'])->where('origin', 'academy_experiment')->get();
        $primary->last()->delete();
        $result = $service->settleOutcome($primary->first()->fresh());
        $this->assertSame('technical_quarantine', $result['status']);
        $this->assertSame('ACADEMY_EXACT_ARM_MEMBERSHIP_MISMATCH', $result['reason']);
        $this->assertDatabaseCount('edge_academy_passports', 1);
        $this->assertDatabaseCount('causal_stage_mastery_assessments', 0);
        Queue::assertNothingPushed();
    }

    public function test_a_terminal_arm_with_wrong_parameter_attestation_cannot_upgrade_any_stage(): void
    {
        Queue::fake();
        $this->test_it_only_admits_a_compiled_pre2026_academy_cohort();
        $trialId = (int) \DB::table('edge_academy_trials')->where('trial_key', 'academy-materializer-trial')->value('id');
        $baseline = ModelVersion::query()->where('name', 'academy baseline')->sole();
        $service = app(AcademyExperimentMaterializerService::class);
        $prepared = $service->admit($trialId, $baseline->id, $this->identity(), $this->decision());
        $primary = LabAgent::query()->where('lab_generation_id', $prepared['generation_id'])->where('origin', 'academy_experiment')->with('modelVersion')->get();
        foreach ($primary as $agent) {
            $this->recordArmResult($agent, ['data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
                'total_trades' => 100, 'net_r' => 1, 'entry_contract_funnel' => ['stage_counts' => ['setup' => 100, 'trigger' => 100]]]);
            $agent->update(['lifecycle_status' => 'screened']);
        }
        LabEvaluationRun::query()->where('lab_agent_id', $primary->last()->id)->update(['parameter_hash' => str_repeat('f', 64)]);
        $settled = $service->settleOutcome($primary->first()->fresh());
        $this->assertSame('technical_quarantine', $settled['status']);
        $this->assertSame('ACADEMY_INCOMPLETE_IMMUTABLE_ARM_EVIDENCE', $settled['reason']);
        $this->assertDatabaseCount('edge_academy_passports', 1);
        $this->assertDatabaseCount('causal_stage_mastery_assessments', 0);
        $this->assertDatabaseCount('evolutionary_authority_ledgers', 0);
        $this->assertDatabaseCount('edge_academy_trials', 1);
    }

    private function identity(): array
    {
        return ['pre_2026_only' => true, 'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
            'mtf_bundle_hash' => str_repeat('c', 64), 'mtf_bundle_manifest' => ['protocol' => \App\Services\MultiTimeframeSnapshotService::PROTOCOL,
                'streams' => array_fill_keys(['M5', 'M15', 'H1', 'H4'], ['sha256' => str_repeat('a', 64)])],
            'canonical_dataset_snapshots' => ['foundation' => ['manifest' => ['sha256' => str_repeat('a', 64)]]]];
    }

    public function test_matching_wrong_v2_context_streams_cannot_advance_from_copied_dataset_labels(): void
    {
        Queue::fake();
        $this->test_it_only_admits_a_compiled_pre2026_academy_cohort();
        $trialId = (int) \DB::table('edge_academy_trials')->where('trial_key', 'academy-materializer-trial')->value('id');
        $baseline = ModelVersion::query()->where('name', 'academy baseline')->sole();
        $service = app(AcademyExperimentMaterializerService::class);
        $prepared = $service->admit($trialId, $baseline->id, $this->identity(), $this->decision());
        $primary = LabAgent::query()->where('lab_generation_id', $prepared['generation_id'])->where('origin', 'academy_experiment')->with('modelVersion')->get();
        foreach ($primary as $agent) {
            $this->recordArmResult($agent, ['data_hash' => str_repeat('c', 64), 'execution_hash' => str_repeat('b', 64),
                'total_trades' => 100, 'entry_contract_funnel' => ['stage_counts' => ['setup' => 100, 'trigger' => 100]],
                'data_quality' => ['decision_identity_receipt' => ['protocol' => 'replay_decision_identity_v2', 'status' => 'complete',
                    'bindings' => ['dataset_identity' => str_repeat('c', 64)],
                    'dependency_identity' => ['streams' => array_fill_keys(['M5', 'H4', 'H1', 'M15'], ['actual_source_sha256' => str_repeat('d', 64)])]]]]);
            $agent->update(['lifecycle_status' => 'screened']);
        }
        $result = $service->settleOutcome($primary->first()->fresh());
        $this->assertSame('ACADEMY_FROZEN_MTF_DEPENDENCY_MISMATCH', $result['reason']);
        $this->assertSame('TECHNICAL_QUARANTINE', $result['classification']);
        $this->assertDatabaseCount('edge_academy_passports', 1);
        $this->assertDatabaseCount('causal_stage_mastery_assessments', 0);
        $this->assertDatabaseCount('evolutionary_authority_ledgers', 0);
    }

    public function test_completed_runs_from_another_evaluator_cannot_settle_the_frozen_question(): void
    {
        Queue::fake();
        $this->test_it_only_admits_a_compiled_pre2026_academy_cohort();
        $trialId = (int) \DB::table('edge_academy_trials')->where('trial_key', 'academy-materializer-trial')->value('id');
        $baseline = ModelVersion::query()->where('name', 'academy baseline')->sole();
        $service = app(AcademyExperimentMaterializerService::class);
        $prepared = $service->admit($trialId, $baseline->id, $this->identity(), $this->decision());
        $generation = LabGeneration::findOrFail($prepared['generation_id']);
        $generation->update(['trigger_context' => [...$generation->trigger_context, 'source_evaluator_hash' => str_repeat('e', 64)]]);
        $primary = $generation->agents()->where('origin', 'academy_experiment')->with('modelVersion')->get();
        foreach ($primary as $agent) {
            $this->recordArmResult($agent, ['data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64), 'total_trades' => 100]);
            LabEvaluationRun::query()->where('lab_agent_id', $agent->id)->update(['code_hash' => str_repeat('d', 64)]);
            $agent->update(['lifecycle_status' => 'screened']);
        }
        $result = $service->settleOutcome($primary->first()->fresh());
        $this->assertSame('ACADEMY_INCOMPLETE_IMMUTABLE_ARM_EVIDENCE', $result['reason']);
        $this->assertSame('TECHNICAL_QUARANTINE', $result['classification']);
        $this->assertDatabaseCount('edge_academy_passports', 1);
        $this->assertDatabaseCount('causal_stage_mastery_assessments', 0);
    }

    public function test_modern_unassessable_terminal_counts_as_settled_and_never_proposes_economic_replication(): void
    {
        Queue::fake();
        $this->test_it_only_admits_a_compiled_pre2026_academy_cohort();
        $trialId = (int) \DB::table('edge_academy_trials')->where('trial_key', 'academy-materializer-trial')->value('id');
        \DB::table('edge_academy_trials')->where('id', $trialId)->update(['density_contract' => json_encode([
            'minimum_setup_events' => 20, 'minimum_trigger_events' => 12, 'minimum_closed_trades' => 8,
        ])]);
        $baseline = ModelVersion::query()->where('name', 'academy baseline')->sole();
        $prepared = app(AcademyExperimentMaterializerService::class)->admit($trialId, $baseline->id, $this->identity(), $this->decision());
        $generation = LabGeneration::findOrFail($prepared['generation_id']);
        // The runtime bundle matches these arms, but their original legacy
        // source lacks the required prospective typed provenance. A copied
        // modern label plus positive arithmetic cannot replace that proof.
        $generation->update(['trigger_context' => [...$generation->trigger_context,
            'mtf_bundle_manifest' => ['protocol' => \App\Services\MultiTimeframeSnapshotService::PROTOCOL, 'streams' => array_fill_keys(['M5', 'M15', 'H1', 'H4'], ['sha256' => str_repeat('a', 64)])]]]);
        $primary = $generation->agents()->where('origin', 'academy_experiment')->with('modelVersion')->get();
        foreach ($primary as $agent) {
            $this->recordArmResult($agent, [
                'data_hash' => str_repeat('c', 64), 'execution_hash' => str_repeat('b', 64), 'total_trades' => 8,
                'net_r' => data_get($agent->modelVersion->metadata, 'academy_experiment.arm_role') === 'candidate' ? .5 : .1,
                'entry_contract_funnel' => ['stage_counts' => ['setup' => 20, 'trigger' => 12]],
                'data_quality' => ['decision_identity_receipt' => ['protocol' => 'replay_decision_identity_v2', 'status' => 'complete',
                    'bindings' => ['dataset_identity' => str_repeat('c', 64)],
                    'dependency_identity' => ['streams' => array_fill_keys(['M5', 'M15', 'H1', 'H4'], ['actual_source_sha256' => str_repeat('a', 64)])]]],
            ]);
            $agent->update(['lifecycle_status' => 'screened']);
        }
        $reconciler = app(AcademyExperimentSettlementReconcilerService::class);
        $result = $reconciler->reconcile('XAUUSD', 'H1', true);
        $settled = $result['outcomes'][0]['result'];
        $this->assertSame('settled_unassessable_stage_evidence', $settled['status'], json_encode($settled));
        $this->assertSame(1, $result['settled_count']);
        $this->assertSame('powered_for_economic_settlement', data_get($settled, 'density.status'));
        $this->assertSame('TECHNICAL_QUARANTINE', $settled['classification']);
        $storedOutcome = json_decode((string) \DB::table('edge_academy_trials')->where('id', $trialId)->value('outcome'), true);
        $this->assertSame('unassessable', data_get($storedOutcome, 'outcome.behavioral_proof.status'));
        $this->assertFalse(data_get($storedOutcome, 'outcome.behavioral_proof.evidence_assessable'));
        $this->assertSame(data_get($storedOutcome, 'stage_progress.reason'), data_get($storedOutcome, 'outcome.behavioral_proof.reason'));
        $this->assertDatabaseHas('research_experiment_receipts', ['source_id' => $trialId, 'classification' => 'TECHNICAL_QUARANTINE']);
        $this->assertDatabaseMissing('research_experiment_receipts', ['source_id' => $trialId, 'classification' => 'POSITIVE_CANDIDATE']);
        $this->assertDatabaseMissing('research_experiment_work_items', ['work_type' => 'academy_independent_replication']);
        $work = \App\Models\ResearchExperimentWorkItem::query()->where('work_type', 'academy_technical_quarantine')->sole();
        $this->assertSame('blocked', $work->status);
        $this->assertSame('TECHNICAL_ROOT_CAUSE_REPAIR_REQUIRED', data_get($work->payload, 'retry_condition.code'));
        $this->assertSame('idle', $reconciler->reconcile('XAUUSD', 'H1', true)['status']);
        $this->assertDatabaseCount('edge_academy_passports', 1);
        $this->assertDatabaseCount('causal_stage_mastery_assessments', 0);
        $this->assertDatabaseCount('evolutionary_authority_ledgers', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        Queue::assertNothingPushed();
    }

    private function decision(): ResearchLoopDecision
    {
        $trialId = (int) \DB::table('edge_academy_trials')->where('trial_key', 'academy-materializer-trial')->value('id');
        $baselineId = (int) ModelVersion::query()->where('name', 'academy baseline')->value('id');
        return ResearchLoopDecision::create(['decision_key' => (string) \Illuminate\Support\Str::uuid(), 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'action' => 'OPEN_ACADEMY_EXPERIMENT', 'status' => 'running', 'evidence_hash' => str_repeat('d', 64),
            'command' => 'trading:admit-academy-experiment', 'arguments' => [0 => $trialId],
            'reason_codes' => [], 'evidence_snapshot' => ['academy_proposal' => ['trial_id' => $trialId,
                'baseline_model_version_id' => $baselineId, 'identity' => $this->identity()]], 'contract' => []]);
    }

    private function recordArmResult(LabAgent $agent, array $metrics): void
    {
        $hash = app(LabImmutableEvidenceService::class)->hash($metrics);
        $run = LabEvaluationRun::create(['run_id' => (string) \Illuminate\Support\Str::uuid(), 'lab_generation_id' => $agent->lab_generation_id,
            'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id, 'phase' => 'screening', 'mode' => 'screen',
            'status' => 'completed', 'response_hash' => $hash, 'data_hash' => data_get($metrics, 'data_hash'),
            'parameter_hash' => app(LabImmutableEvidenceService::class)->parameterHash($agent), 'attempt' => 1]);
        LabEvidenceArtifact::create(['artifact_id' => (string) \Illuminate\Support\Str::uuid(), 'run_id' => $run->run_id,
            'lab_generation_id' => $agent->lab_generation_id, 'lab_agent_id' => $agent->id, 'artifact_type' => 'evaluation_response',
            'sha256' => $hash, 'payload' => $metrics, 'byte_size' => strlen(json_encode($metrics)), 'content_encoding' => 'json', 'recorded_at' => now()]);
    }
}
