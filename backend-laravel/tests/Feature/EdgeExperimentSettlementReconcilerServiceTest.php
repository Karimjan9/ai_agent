<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\EdgeExperimentSettlementReconcilerService;
use App\Services\SourceGenerationAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EdgeExperimentSettlementReconcilerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_edge_passport_gets_one_research_only_receipt_without_replaying(): void
    {
        $lab = AiLaboratory::create(['name'=>'edge receipt','symbol'=>'XAUUSD','timeframe'=>'H1','strategy_families'=>['fixture']]);
        $generation = LabGeneration::create(['ai_laboratory_id'=>$lab->id,'generation'=>91,'trigger_type'=>'fixture','status'=>'completed',
            'trigger_context'=>['architecture_revision'=>'evidence_compiled_edge_hypothesis_v1']]);
        $passportId = DB::table('edge_genesis_passports')->insertGetId([
            'genesis_key'=>'edge-receipt-fixture','lab_generation_id'=>$generation->id,'symbol'=>'XAUUSD','timeframe'=>'H1',
            'strategy_family'=>'fixture','phase'=>'EDGE_DISCOVERY','status'=>'edge_not_found','data_hash'=>'data','execution_hash'=>'execution',
            'context'=>'{}','evidence'=>json_encode(['protocol'=>'edge_genesis_v1','architecture_revision'=>'evidence_compiled_edge_hypothesis_v1','frozen_window_plan'=>['window_plan_hash'=>'window']]),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        foreach (['compiled_control', 'compiled_primary', 'compiled_refinement', 'compiled_counterfactual', 'compiled_negative_control'] as $index => $arm) {
            $control = $arm === 'compiled_control';
            $model = ModelVersion::create(['name'=>'edge receipt '.$arm,'strategy'=>'fixture-'.$arm,'version'=>'v1','generation'=>91,'status'=>'testing',
                'parameters'=>[],'metadata'=>['edge_genesis'=>['protocol'=>'dependency_aware_edge_genesis_v1',
                    'intervention_attestation'=>['protocol'=>'edge_genesis_intervention_attestation_v1','control_identity'=>$control,'consumed_parameter_hash'=>'parameter-'.$index,'actual_parameter_diff'=>['gene'=>$index]]]]]);
            $agent = LabAgent::create(['lab_generation_id'=>$generation->id,'model_version_id'=>$model->id,'symbol'=>'XAUUSD','timeframe'=>'H1',
                'strategy_family'=>'fixture','origin'=>'edge_genesis','lifecycle_status'=>'rejected','parameter_diff'=>['gene'=>$index]]);
            ModelMarketPerformance::create(['model_version_id'=>$model->id,'symbol'=>'XAUUSD','timeframe'=>'H1','strategy_family'=>'fixture','metrics'=>['edge'=>'not_found']]);
            DB::table('edge_genesis_trials')->insert(['trial_key'=>'edge-receipt-'.$index,'edge_genesis_passport_id'=>$passportId,
                'lab_agent_id'=>$agent->id,'model_version_id'=>$model->id,'packet_key'=>'fixture','emitter'=>'fixture','arm'=>$arm,
                'stage'=>'two_fold_discovery','status'=>'edge_not_found','evidence'=>json_encode(['immutable'=>$index]),'settled_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        }

        $service = app(EdgeExperimentSettlementReconcilerService::class);
        $this->assertSame('would_reconcile', $service->reconcile('XAUUSD','H1')['status']);
        $first = $service->reconcile('XAUUSD','H1', true);
        $second = $service->reconcile('XAUUSD','H1', true);

        $this->assertSame('reconciled', $first['status']);
        $this->assertSame('INCONCLUSIVE', data_get($first, 'outcomes.0.classification'));
        $this->assertSame('idle', $second['status']);
        $this->assertDatabaseCount('research_experiment_receipts', 1);
        $report = app(SourceGenerationAuditService::class)->audit($generation->id);
        $this->assertTrue($report['provenance_complete']);
        $this->assertSame([], $report['provenance_blockers']);
        $this->artisan('trading:reconcile-edge-experiment-receipts', ['--apply'=>true,'--json'=>true])
            ->assertSuccessful()->expectsOutputToContain('edge_experiment_settlement_reconciler_v1');
    }
}
