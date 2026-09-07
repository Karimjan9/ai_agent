<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\AgentCompetencyTensorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentCompetencyTensorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_tensor_projects_contextual_evidence_and_never_grants_runtime_permission(): void
    {
        $lab = AiLaboratory::create(['name'=>'tensor lab','symbol'=>'XAUUSD','timeframe'=>'H1','strategy_families'=>['confirmation_entry_mtf'],'lifecycle_mode'=>'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id'=>$lab->id,'generation'=>1,'trigger_type'=>'fixture','status'=>'completed']);
        $model = ModelVersion::create(['name'=>'tensor model','strategy'=>'tensor','version'=>'v1','generation'=>1,'status'=>'testing','parameters'=>[],
            'metadata'=>['academy_experiment'=>['context'=>['regime'=>'trend_up','session'=>'london','volatility'=>'normal']]]]);
        $agent = LabAgent::create(['lab_generation_id'=>$generation->id,'model_version_id'=>$model->id,'symbol'=>'XAUUSD','timeframe'=>'H1',
            'strategy_family'=>'confirmation_entry_mtf','origin'=>'fixture','lifecycle_status'=>'completed','parameter_diff'=>[]]);

        $tensor = app(AgentCompetencyTensorService::class);
        $projected = $tensor->project($agent->fresh('modelVersion'), [
            'entry_contract_funnel'=>['stage_counts'=>['setup'=>100,'confirmation'=>80]], 'false_positive_events'=>10,
            'forward_window_protocol'=>['observed_windows'=>3],
        ], 'epoch-1');
        $self = $tensor->selfKnowledge($agent, 'confirmation', ['regime'=>'trend_up','session'=>'london','volatility'=>'normal','epoch'=>'epoch-1']);

        $this->assertSame('KNOWN', $projected['knowledge_status']);
        $this->assertSame('KNOWN', $self['status']);
        $this->assertFalse($self['confidence_is_eligible']);
        $this->assertSame('WAIT', $self['runtime_behavior']);

        $stale = $tensor->selfKnowledge($agent, 'confirmation', ['regime'=>'trend_up','session'=>'london','volatility'=>'normal','epoch'=>'epoch-2']);
        $this->assertSame('STALE', $stale['status']);
        $this->assertSame('request_regression_challenge', $stale['required_action']);
    }

    public function test_contraindicated_context_requires_abstention_not_confident_trading(): void
    {
        $lab = AiLaboratory::create(['name'=>'contra tensor lab','symbol'=>'XAUUSD','timeframe'=>'H1','strategy_families'=>['confirmation_entry_mtf'],'lifecycle_mode'=>'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id'=>$lab->id,'generation'=>1,'trigger_type'=>'fixture','status'=>'completed']);
        $model = ModelVersion::create(['name'=>'contra tensor model','strategy'=>'tensor','version'=>'v2','generation'=>1,'status'=>'testing','parameters'=>[], 'metadata'=>[]]);
        $agent = LabAgent::create(['lab_generation_id'=>$generation->id,'model_version_id'=>$model->id,'symbol'=>'XAUUSD','timeframe'=>'H1',
            'strategy_family'=>'confirmation_entry_mtf','origin'=>'fixture','lifecycle_status'=>'completed','parameter_diff'=>[]]);

        $tensor = app(AgentCompetencyTensorService::class);
        $tensor->project($agent->fresh('modelVersion'), [
            'context'=>['regime'=>'range','session'=>'new_york','volatility'=>'high'],
            'entry_contract_funnel'=>['stage_counts'=>['setup'=>20,'confirmation'=>4]], 'false_positive_events'=>8,
        ], 'epoch-1');
        $self = $tensor->selfKnowledge($agent, 'confirmation', ['regime'=>'range','session'=>'new_york','volatility'=>'high']);

        $this->assertSame('CONTRAINDICATED', $self['status']);
        $this->assertSame('abstain_and_open_counterfactual', $self['required_action']);
        $this->assertSame('WAIT', $self['runtime_behavior']);
    }
}
