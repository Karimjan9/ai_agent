<?php
namespace App\Services;
use App\Models\Candle;
use App\Models\MarketDriftSnapshot;
use App\Models\Symbol;
class MarketDriftDetectionService
{
    public function detect(string $symbol,string $timeframe='H1'): ?MarketDriftSnapshot
    {
        $symbolId=Symbol::where('code',$symbol)->value('id'); if(!$symbolId)return null;
        $closes=Candle::where('symbol_id',$symbolId)->where('timeframe',$timeframe)->orderByDesc('time')->limit(2501)->pluck('close')->reverse()->values()->map(fn($v)=>(float)$v);
        if($closes->count()<1000)return null;
        $returns=$closes->zip($closes->slice(1))->map(fn($pair)=>$pair[0]?(($pair[1]-$pair[0])/$pair[0]):0)->values();
        $recent=$returns->take(-500)->values(); $baseline=$returns->slice(0,$returns->count()-500)->values();
        $baseMean=(float)$baseline->avg(); $recentMean=(float)$recent->avg();
        $baseStd=$this->std($baseline->all(),$baseMean); $recentStd=$this->std($recent->all(),$recentMean);
        $psi=$this->psi($baseline->all(),$recent->all()); $ratio=$baseStd>0?$recentStd/$baseStd:1;
        $status=$psi>=0.25||$ratio>=1.5||$ratio<=0.67?'drift':($psi>=0.1?'warning':'stable');
        return MarketDriftSnapshot::create(['symbol'=>$symbol,'timeframe'=>$timeframe,'psi_score'=>round($psi,4),
            'volatility_ratio'=>round($ratio,4),'mean_return_shift'=>round(abs($recentMean-$baseMean),6),'status'=>$status,
            'metrics'=>['baseline_samples'=>$baseline->count(),'recent_samples'=>$recent->count(),'baseline_std'=>$baseStd,'recent_std'=>$recentStd], 'detected_at'=>now()]);
    }
    private function std(array $v,float $m):float{return $v?sqrt(array_sum(array_map(fn($x)=>($x-$m)**2,$v))/count($v)):0;}
    private function psi(array $base,array $recent):float
    {
        sort($base); $n=count($base); $score=0;
        for($i=0;$i<10;$i++){ $low=$i===0?-INF:$base[(int)floor($n*$i/10)]; $high=$i===9?INF:$base[(int)floor($n*($i+1)/10)-1];
            $bp=max(0.0001,count(array_filter($base,fn($x)=>$x>=$low&&$x<=$high))/$n);
            $rp=max(0.0001,count(array_filter($recent,fn($x)=>$x>=$low&&$x<=$high))/count($recent)); $score+=($rp-$bp)*log($rp/$bp); }
        return $score;
    }
}
