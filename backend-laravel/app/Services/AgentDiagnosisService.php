<?php
namespace App\Services;
use App\Models\AgentDiagnosis;
use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
class AgentDiagnosisService
{
    public function __construct(private StrategyParameterSchemaService $schemas, private ForwardGateProgressService $gates) {}
    public function diagnose(ModelMarketPerformance $performance, array $result): AgentDiagnosis
    {
        $agent = LabAgent::where('model_version_id',$performance->model_version_id)->latest()->first();
        $pf=(float)($result['profit_factor']??0); $dd=(float)($result['max_drawdown_percent']??100);
        $ruin=(float)data_get($result,'monte_carlo.risk_of_ruin_percent',100); $trades=(int)($result['total_trades']??0);
        $funnel=(array) data_get($result,'entry_funnel',[]);
        $flatSignals=(int) data_get($funnel,'flat_signal_opportunities',0);
        $acceptedEntries=(int) data_get($funnel,'accepted_entries',0);
        $dominantRejection=data_get($funnel,'dominant_rejection');
        $signalStarved=$trades<30 && $flatSignals>0 && $flatSignals<30;
        $overFiltered=$trades<30 && $flatSignals>=30 && $acceptedEntries / max(1,$flatSignals) < .5;
        $mistakes=collect($result['top_mistakes']??[])->pluck('type')->all();
        $failures=array_values(array_filter([
            (bool)($result['is_overfit']??false)?'overfit':null, $trades===0?'no_trade':null,
            $pf<1.0?'negative_edge':($pf<1.3?'weak_profit_factor':null), $ruin>10?'ruin_risk':null,
            $dd>15?'excessive_drawdown':null, $trades<30?'insufficient_trades':null,
            $signalStarved?'signal_starvation':null, $overFiltered?'over_filtering':null,
            in_array('stop_loss_too_close',$mistakes,true)?'stop_too_tight':null,
        ]));
        $failure = match(true) {
            (bool)($result['is_overfit']??false) => 'overfit', $trades===0 => 'no_trade',
            $pf<1.0 => 'negative_edge', $ruin>10 => 'ruin_risk', $dd>15 => 'excessive_drawdown',
            $pf<1.3 => 'weak_profit_factor', $signalStarved => 'signal_starvation',
            $overFiltered => 'over_filtering', $trades<30 => 'insufficient_trades',
            default => 'no_critical_failure',
        };
        $schema=array_keys($this->schemas->schema($performance->strategy_family));
        $recommended=match($failure){
            'no_trade'=>array_values(array_intersect($schema,['atr_threshold','compression_ratio','expansion_multiplier','minimum_signal_confidence','confirmation_candles','lookback'])),
            'signal_starvation'=>array_values(array_intersect($schema,['lookback','confirmation_candles','trend_strength_min','pullback_atr_fraction','adx_max','deviation','roc_threshold','atr_threshold','compression_ratio'])),
            'over_filtering'=>array_values(array_intersect($schema,['minimum_signal_confidence','max_spread_atr_ratio','avoid_high_volatility','max_loss_streak_before_wait','loss_cooldown_candles'])),
            'insufficient_trades'=>array_values(array_intersect($schema,['confirmation_candles','lookback','roc_threshold','deviation','minimum_signal_confidence'])),
            'negative_edge','weak_profit_factor'=>array_values(array_intersect($schema,['atr_stop_multiplier','atr_target_multiplier','trailing_atr_multiplier','time_stop_candles','partial_take_profit_fraction','trend_strength_min','pullback_atr_fraction'])),
            'ruin_risk','excessive_drawdown'=>array_values(array_intersect($schema,['atr_stop_multiplier','high_volatility_risk_multiplier','max_loss_streak_before_wait','loss_cooldown_candles'])),
            'overfit'=>array_values(array_intersect($schema,['lookback','ema_fast','ema_slow'])),
            default=>[],
        };
        $blocked=$trades<30?array_values(array_intersect($schema,['confirmation_candles','deviation','roc_threshold'])):[];
        $gateSnapshot = $this->gates->snapshot($result, (int) $performance->rolling_forward_wins);
        $gateDoctor = $this->gateDoctor($failure, $gateSnapshot['deficits'], $dominantRejection, $mistakes);
        return AgentDiagnosis::updateOrCreate(
            ['model_market_performance_id'=>$performance->id,'lab_agent_id'=>$agent?->id],
            ['primary_failure'=>$failure,'evidence'=>[...compact('pf','dd','ruin','trades','flatSignals','acceptedEntries','dominantRejection'),'entry_funnel'=>$funnel,'failures'=>$failures,'mistakes'=>$mistakes,'gate_snapshot'=>$gateSnapshot,'deficits'=>$gateSnapshot['deficits'],'gate_doctor'=>$gateDoctor],
                'recommended_mutations'=>$recommended,'blocked_mutations'=>$blocked,
                'explanation'=>"Diagnosis {$failure}: ".implode(', ', $failures?:['no_failure'])."; PF {$pf}, DD {$dd}%, ruin {$ruin}%, trades {$trades}.",
                'confidence'=>min(95,50+(int)($performance->rolling_windows_count*10))]
        );
    }

    private function gateDoctor(string $failure, array $deficits, ?string $dominantRejection, array $mistakes): array
    {
        [$gate, $deficit, $bundle] = match ($failure) {
            'no_trade', 'signal_starvation', 'over_filtering', 'insufficient_trades' => ['trade_count', (float) ($deficits['trade_deficit'] ?? 0), 'trade_frequency_bundle'],
            'negative_edge', 'weak_profit_factor', 'stop_too_tight' => ['profit_factor', (float) ($deficits['pf_deficit'] ?? 0), 'profit_factor_bundle'],
            'ruin_risk', 'excessive_drawdown' => ['drawdown', max((float) ($deficits['drawdown_excess'] ?? 0), (float) ($deficits['ruin_excess'] ?? 0)), 'drawdown_bundle'],
            'overfit' => ['overfit', 1.0, 'architecture_bundle'],
            default => ['none', 0.0, 'observe'],
        };
        return [
            'failed_gate' => $gate, 'deficit' => round($deficit, 4),
            'trade_deficit' => (int) ($deficits['trade_deficit'] ?? 0),
            'main_cause' => $dominantRejection ?: ($mistakes[0] ?? $failure),
            'recommended_bundle' => $bundle,
            'confidence' => $failure === 'no_critical_failure' ? .5 : .78,
        ];
    }
}
