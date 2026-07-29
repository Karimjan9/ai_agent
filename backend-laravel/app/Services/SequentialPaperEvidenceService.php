<?php
namespace App\Services;
use App\Models\ModelMarketPerformance; use App\Models\PaperSequentialEvidence;
class SequentialPaperEvidenceService {
 public function observe(ModelMarketPerformance $performance, array $metrics): array { $n=(int)data_get($metrics,'sample_count',0); $pf=(float)data_get($metrics,'profit_factor',0); $net=(float)data_get($metrics,'net_profit_percent',0); $dd=(float)data_get($metrics,'max_drawdown',100); $lr=exp(max(-20,min(20,$net/5)))*max(.01,$pf/1.3); $e=max(0,$lr); $confidence=round(1-(1/(1+$e)),4); $status=$dd>15?'catastrophic_risk_stop':($n>=50&&$pf>=1.3&&$net>0?'minimum_sample_reached':($n>=20&&$net<-5?'futility_stop':'running')); PaperSequentialEvidence::updateOrCreate(['model_market_performance_id'=>$performance->id,'sample_count'=>$n],['e_value'=>$e,'likelihood_ratio'=>$lr,'confidence_sequence'=>$confidence,'status'=>$status,'metrics'=>$metrics]); return compact('n','e','lr','confidence','status'); }
}
