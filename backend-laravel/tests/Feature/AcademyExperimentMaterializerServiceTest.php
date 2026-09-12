<?php

namespace Tests\Feature;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\AcademyExperimentMaterializerService;
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
        LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf', 'origin' => 'fixture', 'lifecycle_status' => 'completed', 'parameter_diff' => []]);
        $passport = app(XauusdEdgeFormationAcademyService::class)->passport('XAUUSD', 'H1', ['composition_key' => 'academy-materializer', 'strategy_family' => 'confirmation_entry_mtf', 'deepest_stage' => 'trigger_entry_specialist']);
        $trialId = \DB::table('edge_academy_trials')->insertGetId(['trial_key' => 'academy-materializer-trial', 'edge_academy_passport_id' => $passport['passport_id'],
            'trial_type' => 'fixture', 'status' => 'planned', 'frozen_contract' => '{}', 'density_contract' => '{}', 'arms' => json_encode([
                ['role' => 'frozen_control', 'changed_axis' => 'trigger_topology_policy', 'value' => 'frozen_current'],
                ['role' => 'candidate', 'changed_axis' => 'trigger_topology_policy', 'value' => 'aggressive_structure_close'],
                ['role' => 'candidate', 'changed_axis' => 'trigger_topology_policy', 'value' => 'balanced_retest_reaction'],
                ['role' => 'candidate', 'changed_axis' => 'trigger_topology_policy', 'value' => 'conservative_continuation'],
                ['role' => 'blinded_control', 'changed_axis' => 'trigger_topology_policy', 'value' => 'trigger_blinded_control'],
            ]), 'created_at' => now(), 'updated_at' => now()]);
        $result = app(AcademyExperimentMaterializerService::class)->materialize($trialId, $model->id, ['pre_2026_only' => true, 'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64), 'canonical_dataset_snapshots' => []]);

        $this->assertSame('would_queue', $result['status']);
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
        $queued = $service->materialize($trialId, $baseline->id, ['pre_2026_only' => true, 'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64), 'canonical_dataset_snapshots' => []], true);
        $this->assertSame(20, LabAgent::query()->where('lab_generation_id', $queued['generation_id'])->count());
        $agents = LabAgent::query()->where('lab_generation_id', $queued['generation_id'])->where('origin', 'academy_experiment')->with('modelVersion')->get();
        $this->assertCount(5, $agents);
        Queue::assertPushed(EvaluateLabAgentJob::class, 20);
        foreach ($agents as $agent) {
            ModelMarketPerformance::create(['model_version_id' => $agent->model_version_id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf', 'metrics' => [
                'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64), 'total_trades' => 8,
                'net_r' => data_get($agent->modelVersion->metadata, 'academy_experiment.arm_role') === 'candidate' ? .2 : .1,
                'entry_contract_funnel' => ['stage_counts' => ['setup' => 20, 'trigger' => 12]],
            ]]);
        }

        $reconciliation = app(AcademyExperimentSettlementReconcilerService::class)->reconcile('XAUUSD', 'H1', true);
        $this->assertSame('reconciled', $reconciliation['status']);
        $this->assertSame(1, $reconciliation['settled_count']);
        $settled = $reconciliation['outcomes'][0]['result'];

        $this->assertSame('settled_powered', $settled['status']);
        $this->assertSame('POSITIVE_CANDIDATE', $settled['classification']);
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
        $queued = app(AcademyExperimentMaterializerService::class)->materialize($trialId, $baseline->id, [
            'pre_2026_only' => true, 'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64), 'canonical_dataset_snapshots' => [],
        ], true);
        $agents = LabAgent::query()->where('lab_generation_id', $queued['generation_id'])->where('origin', 'academy_experiment')->with('modelVersion')->get();
        foreach ($agents->values() as $index => $agent) {
            ModelMarketPerformance::create(['model_version_id' => $agent->model_version_id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf', 'metrics' => [
                'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64), 'total_trades' => 8, 'net_r' => .1,
                'entry_contract_funnel' => ['stage_counts' => ['setup' => $index === 0 ? 19 : 20, 'trigger' => 12]],
            ]]);
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
        $queued = app(AcademyExperimentMaterializerService::class)->materialize($trialId, $baseline->id, [
            'pre_2026_only' => true, 'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64), 'canonical_dataset_snapshots' => [],
        ], true);
        $agents = LabAgent::query()->where('lab_generation_id', $queued['generation_id'])->where('origin', 'academy_experiment')->with('modelVersion')->get();
        foreach ($agents as $agent) {
            ModelMarketPerformance::create(['model_version_id' => $agent->model_version_id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf', 'metrics' => [
                'data_hash' => str_repeat('c', 64), 'execution_hash' => str_repeat('b', 64), 'total_trades' => 8,
            ]]);
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
}
