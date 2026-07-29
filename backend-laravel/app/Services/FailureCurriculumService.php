<?php
namespace App\Services;
use App\Models\AgentFailureCase;
use App\Models\FailureCaseRun;
use App\Models\ModelMarketPerformance;
class FailureCurriculumService {
 public function evaluate(ModelMarketPerformance $performance,array $result): array {
  $cases=AgentFailureCase::query()->where('symbol',$performance->symbol)->where('timeframe',$performance->timeframe)->where('regression_status','open')->get(); $runs=[];
  foreach($cases as $case){$pass=match($case->failure_type){'cost_fragility'=>(float)data_get($result,'pf_attribution.stress_cost.profit_factor',0)>=1.05,'transition_failure'=>(float)data_get($result,'transition_homework.score',0)>=50,'edge_pf_signal_quality'=>(float)data_get($result,'profit_factor',0)>=1.30,'trade_viability_signal_frequency'=>(int)data_get($result,'total_trades',0)>=30,default=>null};$status=$pass===null?'not_assessed':($pass?'passed':'failed');$penalty=$case->severity==='P1_QUALITY'&&$status==='failed'?10:0; FailureCaseRun::updateOrCreate(['agent_failure_case_id'=>$case->id,'model_market_performance_id'=>$performance->id],['status'=>$status,'score_penalty'=>$penalty,'evaluated_at'=>now(),'evidence'=>['failure_type'=>$case->failure_type,'expected_action'=>$case->expected_action,'hidden_variant_required'=>true,'rule'=>'A frozen perturbation variant is required before a case may be marked fixed.']]);$runs[]=['failure_case_id'=>$case->id,'severity'=>$case->severity,'status'=>$status,'penalty'=>$penalty];}
  return ['protocol'=>'failure_curriculum_v1','runs'=>$runs,'p0_safety_passed'=>collect($runs)->where('severity','P0_SAFETY')->every(fn($run)=>$run['status']==='passed'),'quality_penalty'=>collect($runs)->sum('penalty'),'rule'=>'Only P0 failures can hard-block v4 forward certification; P1/P2 remain measurable quality/research signals.'];
 }
}
