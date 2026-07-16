<?php
namespace App\Services;
use App\Models\AgentDiagnosis;
use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
class AgentDiagnosisService
{
    public function __construct(private StrategyParameterSchemaService $schemas) {}
    public function diagnose(ModelMarketPerformance $performance, array $result): AgentDiagnosis
    {
        $agent = LabAgent::where('model_version_id',$performance->model_version_id)->latest()->first();
        $pf=(float)($result['profit_factor']??0); $dd=(float)($result['max_drawdown_percent']??100);
        $ruin=(float)data_get($result,'monte_carlo.risk_of_ruin_percent',100); $trades=(int)($result['total_trades']??0);
        $failure = match(true) {
            (bool)($result['is_overfit']??false) => 'overfit', $trades<30 => 'insufficient_trades',
            $ruin>10 => 'ruin_risk', $dd>15 => 'excessive_drawdown', $pf<1.3 => 'weak_profit_factor',
            default => 'no_critical_failure',
        };
        $schema=array_keys($this->schemas->schema($performance->strategy_family));
        $recommended=match($failure){
            'insufficient_trades'=>array_values(array_intersect($schema,['confirmation_candles','lookback','roc_threshold','deviation'])),
            'ruin_risk','excessive_drawdown'=>array_values(array_intersect($schema,['atr_multiplier','confirmation_candles','ema_slow','lookback'])),
            'overfit'=>array_values(array_intersect($schema,['lookback','ema_fast','ema_slow'])),
            'weak_profit_factor'=>array_slice($schema,0,2), default=>[],
        };
        $blocked=$trades<30?array_values(array_intersect($schema,['confirmation_candles','deviation','roc_threshold'])):[];
        return AgentDiagnosis::updateOrCreate(
            ['model_market_performance_id'=>$performance->id,'lab_agent_id'=>$agent?->id],
            ['primary_failure'=>$failure,'evidence'=>compact('pf','dd','ruin','trades'),
                'recommended_mutations'=>$recommended,'blocked_mutations'=>$blocked,
                'explanation'=>"Diagnosis {$failure}: PF {$pf}, DD {$dd}%, ruin {$ruin}%, trades {$trades}.",
                'confidence'=>min(95,50+(int)($performance->rolling_windows_count*10))]
        );
    }
}
