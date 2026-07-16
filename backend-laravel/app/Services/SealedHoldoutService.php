<?php
namespace App\Services;
use App\Models\ModelMarketPerformance;
use App\Models\SealedHoldoutRelease;
use App\Services\MarketData\CandlePayloadService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
class SealedHoldoutService
{
    public function __construct(private CandlePayloadService $candles,private MarketChampionService $champions){}
    public function release(ModelMarketPerformance $performance): SealedHoldoutRelease
    {
        if($performance->paper_status!=='passed')throw new RuntimeException('Paper gate hali o‘tilmagan.');
        $existing=SealedHoldoutRelease::where('model_market_performance_id',$performance->id)->first();
        if($existing)return $existing;
        $rows=$this->candles->candlesForBacktest($performance->symbol,$performance->timeframe);
        $hash=hash('sha256',json_encode([count($rows),$rows[0]['time']??null,$rows[array_key_last($rows)]['time']??null]));
        $release=SealedHoldoutRelease::create(['model_market_performance_id'=>$performance->id,'dataset_hash'=>$hash,'status'=>'running','opened_at'=>now()]);
        $model=$performance->modelVersion;
        $response=Http::timeout(1200)->acceptJson()->withHeaders(['X-Internal-Token'=>(string)config('services.internal_api.token')])->post(rtrim(config('services.ai_service.url'),'/').'/api/holdout/run',[
            'symbol'=>$performance->symbol,'timeframe'=>$performance->timeframe,'strategy'=>$model->strategy,
            'base_strategy'=>data_get($model->metadata,'base_strategy')?:$performance->strategy_family.'_v1',
            'parameters'=>$model->parameters??[],'candles'=>$rows,'initial_balance'=>10000,'risk_per_trade'=>1,
            'execution'=>['spread_points'=>str_starts_with($performance->symbol,'XAU')?20:12,'point_size'=>str_starts_with($performance->symbol,'XAU')?0.01:0.00001,
                'commission_percent'=>0.01,'slippage_points'=>2,'swap_per_day_percent'=>0.002,'allowed_sessions_utc'=>['1-22'],'intrabar_policy'=>'conservative','max_gap_multiple'=>96,
                'reject_unexpected_gaps'=>true,'stop_loss_percent'=>0.5,'take_profit_percent'=>1.0,'max_leverage'=>5]
        ]);
        if($response->failed()){$release->update(['status'=>'failed','result'=>['error'=>$response->body()],'completed_at'=>now()]);throw new RuntimeException('Holdout service failed: '.$response->body());}
        $payload=$response->json(); $release->update(['status'=>'completed','score'=>$payload['score']??0,'result'=>$payload,'completed_at'=>now()]);
        $this->champions->finalizeHoldout($performance,$payload); return $release->fresh();
    }
}
