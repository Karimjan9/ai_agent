<?php
namespace App\Services;
use App\Models\LabAgent;
use App\Models\StrategyScore;
use App\Models\TrainingSession;
use App\Services\MarketData\CandlePayloadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
class LabAgentEvaluationService
{
    public function __construct(private CandlePayloadService $candles,private MarketChampionService $champions){}
    public function evaluate(LabAgent $agent):void
    {
        $agent->load('modelVersion','generation');$model=$agent->modelVersion;$dataset=storage_path("app/lab-datasets/{$agent->symbol}_{$agent->timeframe}.csv");if(!is_file($dataset))throw new RuntimeException('Lab dataset export topilmadi.');
        $response=Http::timeout(1800)->acceptJson()->withHeaders(['X-Internal-Token'=>(string)config('services.internal_api.token')])->post(rtrim(config('services.ai_service.url'),'/').'/api/backtest/run-all',[
            'symbol'=>$agent->symbol,'timeframe'=>$agent->timeframe,'strategy'=>'all','evaluation_mode'=>'full',
            'strategies'=>[['strategy'=>$model->strategy,'base_strategy'=>data_get($model->metadata,'base_strategy')?:$agent->strategy_family.'_v1','version'=>$model->version,'parameters'=>$model->parameters??[]]],
            'initial_balance'=>10000,'risk_per_trade'=>1,'dataset_path'=>$dataset,
            'execution'=>['spread_points'=>str_starts_with($agent->symbol,'XAU')?20:12,'point_size'=>str_starts_with($agent->symbol,'XAU')?0.01:0.00001,
                'commission_percent'=>0.01,'slippage_points'=>2,'swap_per_day_percent'=>0.002,'allowed_sessions_utc'=>['1-22'],'intrabar_policy'=>'conservative','max_gap_multiple'=>96,
                'reject_unexpected_gaps'=>true,'stop_loss_percent'=>0.5,'take_profit_percent'=>1.0,'max_leverage'=>5]
        ]);
        if($response->failed())throw new RuntimeException($response->body());$item=data_get($response->json(),'leaderboard.0');if(!$item)throw new RuntimeException('Empty lab agent result.');
        DB::transaction(function()use($agent,$model,$item){$result=$item['result']??[];$session=TrainingSession::create([
            'title'=>"{$agent->symbol} Lab G{$agent->generation->generation} {$model->name}",'symbol'=>$agent->symbol,'timeframe'=>$agent->timeframe,
            'agents_count'=>1,'best_strategy'=>$model->strategy,'best_score'=>$item['score'],'worst_strategy'=>$model->strategy,'worst_score'=>$item['score'],
            'total_trades'=>$result['total_trades']??0,'average_winrate'=>$result['winrate']??0,'average_profit'=>$result['net_profit_percent']??0,
            'average_drawdown'=>$result['max_drawdown_percent']??$result['max_drawdown']??0,
            'average_profit_factor'=>$result['profit_factor']??0,
            'average_stability_score'=>$result['stability_score']??0,
            'status'=>'completed','started_at'=>now(),'finished_at'=>now(),'raw_leaderboard'=>[$item]]);
            StrategyScore::create(['training_session_id'=>$session->id,'symbol'=>$agent->symbol,'timeframe'=>$agent->timeframe,'strategy'=>$model->strategy,
                'parameters'=>$model->parameters??[],'score'=>$item['score'],'train_score'=>$item['train_score']??0,'validation_score'=>$item['validation_score']??0,
                'forward_score'=>$item['forward_score']??0,'robustness_score'=>$item['robustness_score']??0,'is_overfit'=>$item['is_overfit']??false,
                'mc_worst_drawdown_percent'=>data_get($result,'monte_carlo.worst_drawdown_percent'),'mc_risk_of_ruin_percent'=>data_get($result,'monte_carlo.risk_of_ruin_percent'),
                'total_trades'=>$result['total_trades']??0,'wins'=>$result['wins']??0,'losses'=>$result['losses']??0,'winrate'=>$result['winrate']??0,
                'net_profit_percent'=>$result['net_profit_percent']??0,'max_drawdown_percent'=>$result['max_drawdown_percent']??0,'profit_factor'=>$result['profit_factor']??0,
                'stability_score'=>$result['stability_score']??0,'equity_curve'=>$result['equity_curve']??[],'regime_performance'=>$result['regime_performance']??[],
                'volatility_performance'=>$result['volatility_performance']??[],'raw_result'=>$result]);
            $result['forward_score']=$item['forward_score']??0;$result['forward_window_scores']=$item['forward_window_scores']??[];$result['rolling_windows_count']=$item['rolling_windows_count']??0;
            $result['train_score']=$item['train_score']??0;$result['validation_score']=$item['validation_score']??0;$result['is_overfit']=$item['is_overfit']??false;
            $model->update(['best_score'=>max((float)$model->best_score,(float)$item['score']),'best_winrate'=>$result['winrate']??0,'best_profit'=>$result['net_profit_percent']??0,'best_drawdown'=>$result['max_drawdown_percent']??0,'metadata'=>array_merge($model->metadata??[],['last_result'=>$result])]);
            $this->champions->evaluate($model->strategy,$agent->symbol,$agent->timeframe,(int)$item['score'],$result);
        });
        $generation=$agent->generation()->with('agents')->first();if($generation->agents->whereIn('lifecycle_status',['draft','queued','training','full_queued'])->isEmpty())$generation->update(['status'=>'completed','completed_at'=>now()]);
    }

    /** Fast, pair-local filter. Promotion never happens from this result. */
    public function screen(LabAgent $agent): void
    {
        $agent->load('modelVersion', 'generation');
        $model = $agent->modelVersion;
        $rows = $this->candles->candlesForBacktest($agent->symbol, $agent->timeframe, 5000);
        if (count($rows) < 500) {
            throw new RuntimeException('Screening uchun yetarli recent candle topilmadi.');
        }

        $response = Http::timeout(300)->acceptJson()->withHeaders(['X-Internal-Token'=>(string)config('services.internal_api.token')])->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/run-all', [
            'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe,
            'strategy' => $model->strategy, 'evaluation_mode' => 'incremental',
            'strategies' => [[
                'strategy' => $model->strategy,
                'base_strategy' => data_get($model->metadata, 'base_strategy') ?: $agent->strategy_family.'_v1',
                'version' => $model->version, 'parameters' => $model->parameters ?? [],
            ]],
            'initial_balance' => 10000, 'risk_per_trade' => 1, 'candles' => $rows,
        ]);
        if ($response->failed()) throw new RuntimeException($response->body());
        $item = data_get($response->json(), 'leaderboard.0');
        if (! $item) throw new RuntimeException('Empty screening result.');
        $result = $item['result'] ?? [];
        $agent->update([
            'lifecycle_status' => 'screened',
            'train_score' => $item['train_score'] ?? $item['score'] ?? 0,
            'validation_score' => $item['validation_score'] ?? $item['score'] ?? 0,
            'forward_score' => $item['forward_score'] ?? $item['score'] ?? 0,
            'sample_count' => $result['total_trades'] ?? 0,
            'profit_factor' => $result['profit_factor'] ?? 0,
            'max_drawdown' => $result['max_drawdown_percent'] ?? $result['max_drawdown'] ?? 0,
            'risk_of_ruin' => data_get($result, 'monte_carlo.risk_of_ruin_percent'),
            'decision_reason' => 'Incremental screening completed; awaiting global full-validation selection.',
        ]);

        $generation = $agent->generation()->with('agents')->first();
        if ($generation->agents->whereIn('lifecycle_status', ['draft', 'queued', 'screening'])->isEmpty()) {
            $generation->update(['status' => 'screened']);
        }
    }
}
