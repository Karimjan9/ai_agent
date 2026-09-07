<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\AcademyExperimentMaterializerService;
use App\Services\StrategyParameterSchemaService;
use App\Services\XauusdEdgeFormationAcademyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademyExperimentMaterializerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_admits_a_compiled_pre2026_academy_cohort(): void
    {
        $lab = AiLaboratory::create(['name'=>'academy materializer','symbol'=>'XAUUSD','timeframe'=>'H1','strategy_families'=>['confirmation_entry_mtf'],'lifecycle_mode'=>'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id'=>$lab->id,'generation'=>1,'trigger_type'=>'fixture','status'=>'completed']);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf');
        $model = ModelVersion::create(['name'=>'academy baseline','strategy'=>'academy_baseline','version'=>'v1','generation'=>1,'status'=>'testing','parameters'=>$parameters,'metadata'=>['base_strategy'=>'confirmation_entry_mtf_v1']]);
        LabAgent::create(['lab_generation_id'=>$generation->id,'model_version_id'=>$model->id,'symbol'=>'XAUUSD','timeframe'=>'H1','strategy_family'=>'confirmation_entry_mtf','origin'=>'fixture','lifecycle_status'=>'completed','parameter_diff'=>[]]);
        $passport = app(XauusdEdgeFormationAcademyService::class)->passport('XAUUSD','H1',['composition_key'=>'academy-materializer','strategy_family'=>'confirmation_entry_mtf','deepest_stage'=>'trigger_entry_specialist']);
        $trialId = \DB::table('edge_academy_trials')->insertGetId(['trial_key'=>'academy-materializer-trial','edge_academy_passport_id'=>$passport['passport_id'],
            'trial_type'=>'fixture','status'=>'planned','frozen_contract'=>'{}','density_contract'=>'{}','arms'=>json_encode([
                ['role'=>'frozen_control','changed_axis'=>'trigger_topology_policy','value'=>'frozen_current'],
                ['role'=>'candidate','changed_axis'=>'trigger_topology_policy','value'=>'aggressive_structure_close'],
                ['role'=>'candidate','changed_axis'=>'trigger_topology_policy','value'=>'balanced_retest_reaction'],
                ['role'=>'candidate','changed_axis'=>'trigger_topology_policy','value'=>'conservative_continuation'],
                ['role'=>'blinded_control','changed_axis'=>'trigger_topology_policy','value'=>'trigger_blinded_control'],
            ]),'created_at'=>now(),'updated_at'=>now()]);
        $result = app(AcademyExperimentMaterializerService::class)->materialize($trialId,$model->id,['pre_2026_only'=>true,'data_hash'=>str_repeat('a',64),'execution_hash'=>str_repeat('b',64),'canonical_dataset_snapshots'=>[]]);

        $this->assertSame('would_queue',$result['status']);
        $this->assertCount(5,$result['compiled_contract']['arms']);
    }
}
