<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\LabAgent;
use App\Models\MutationMemory;
use App\Models\PaperTradingEvaluation;
use App\Services\MarketData\MarketReadinessService;
use Illuminate\Support\Facades\DB;

class MarketChampionService
{
    public function __construct(
        private StrategyParameterSchemaService $schemas,
        private AgentDiagnosisService $diagnoses,
        private MarketReadinessService $marketReadiness,
        private PaperEvidenceReadinessService $paperEvidence,
    ) {}

    public function evaluate(string $strategy, string $symbol, string $timeframe, int $fitness, array $result): ModelMarketPerformance
    {
        return DB::transaction(function () use ($strategy, $symbol, $timeframe, $fitness, $result): ModelMarketPerformance {
            $model = ModelVersion::query()->where('strategy', $strategy)->where('evidence_status', 'valid')->lockForUpdate()->firstOrFail();
            $family = $this->schemas->family($strategy);
            $champion = ModelMarketPerformance::query()
                ->where(compact('symbol', 'timeframe'))
                ->where('strategy_family', $family)
                ->where('evidence_status', 'valid')
                ->where('status', 'champion')
                ->lockForUpdate()
                ->first();

            $windowScores = array_values($result['forward_window_scores'] ?? []);
            $championScores = array_values(data_get($champion?->metrics, 'forward_window_scores', []));
            $wins = $champion
                ? collect($windowScores)->filter(fn ($score, $i) => isset($championScores[$i]) && $score > $championScores[$i])->count()
                : count($windowScores);
            $forward = (float) ($result['forward_score'] ?? 0);
            $sampleCount = (int) ($result['total_trades'] ?? 0);

            $performance = ModelMarketPerformance::query()->updateOrCreate(
                ['model_version_id' => $model->id, 'symbol' => $symbol, 'timeframe' => $timeframe],
                [
                    'strategy_family' => $family, 'fitness' => $fitness, 'forward_score' => $forward,
                    'sample_count' => $sampleCount, 'rolling_windows_count' => count($windowScores),
                    'rolling_forward_wins' => $wins, 'metrics' => $result,
                    'status' => $champion?->model_version_id === $model->id ? 'champion' : 'challenger',
                    'champion_slot' => $champion?->model_version_id === $model->id ? 'champion' : null,
                ],
            );

            if ($champion?->id === $performance->id) {
                $performance->update(['status' => 'champion', 'champion_slot' => 'champion']);
            } elseif ($this->backtestGatesPass($performance, $champion, $result)) {
                $performance->update(['status' => 'forward_validated', 'champion_slot' => null]);
                PaperTradingEvaluation::firstOrCreate(
                    ['model_market_performance_id' => $performance->id, 'status' => 'pending'],
                    ['started_at' => now()],
                );
                if ($performance->paper_status === 'passed') {
                    $this->promote($performance, $champion, $model);
                }
            } elseif (! $champion || $champion->id !== $performance->id) {
                $stagnation = $performance->consecutive_no_improvement + 1;
                $failedStatus = (bool) ($result['is_overfit'] ?? false)
                    ? 'overfit'
                    : (((float) ($result['profit_factor'] ?? 0) < 0.8
                        || (float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 0) > 30)
                        ? 'rejected'
                        : ($stagnation >= 3 ? 'stagnated' : 'challenger'));
                $performance->update([
                    'consecutive_no_improvement' => $stagnation,
                    'status' => $failedStatus,
                    'champion_slot' => null,
                ]);
            }

            $this->updateLabAgentAndMemory($performance->fresh(), $champion, $result);
            $this->diagnoses->diagnose($performance->fresh(), $result);

            return $performance->fresh();
        });
    }

    public function recordPaperResult(ModelMarketPerformance $performance, array $metrics): ModelMarketPerformance
    {
        return DB::transaction(function () use ($performance, $metrics): ModelMarketPerformance {
            $performance = ModelMarketPerformance::query()->where('evidence_status', 'valid')->lockForUpdate()->findOrFail($performance->id);
            $sampleCount = (int) ($metrics['sample_count'] ?? 0);
            $profitFactor = (float) ($metrics['profit_factor'] ?? 0);
            $drawdown = (float) ($metrics['max_drawdown'] ?? 100);
            $minimumSamples = max(50, (int) config('services.promotion.paper_min_samples', 50));
            $passed = $sampleCount >= $minimumSamples && $profitFactor >= 1.3 && $drawdown <= 15
                && (float) ($metrics['net_profit_percent'] ?? 0) > 0;
            $status = $passed ? 'passed' : ($sampleCount >= $minimumSamples ? 'failed' : 'running');
            $performance->update([
                'paper_status' => $status, 'paper_sample_count' => $sampleCount,
                'paper_profit_factor' => $profitFactor, 'paper_max_drawdown' => $drawdown,
                'status' => $passed ? 'paper' : ($status === 'failed' ? 'rejected' : 'forward_validated'),
            ]);
            PaperTradingEvaluation::updateOrCreate(
                ['model_market_performance_id' => $performance->id, 'status' => $status],
                ['sample_count' => $sampleCount, 'profit_factor' => $profitFactor, 'max_drawdown' => $drawdown,
                    'net_profit_percent' => $metrics['net_profit_percent'] ?? 0, 'metrics' => $metrics,
                    'started_at' => now(), 'completed_at' => $status === 'running' ? null : now()],
            );

            $agent = LabAgent::where('model_version_id', $performance->model_version_id)->latest()->first();
            $agent?->update(['lifecycle_status' => $performance->fresh()->status, 'decision_reason' => $passed ? 'Paper trading gate passed.' : 'Paper trading evidence insufficient or failed.']);
            return $performance->fresh();
        });
    }

    public function finalizeHoldout(ModelMarketPerformance $performance, array $holdout): ModelMarketPerformance
    {
        return DB::transaction(function() use($performance,$holdout){
            $performance=ModelMarketPerformance::query()->where('evidence_status', 'valid')->lockForUpdate()->findOrFail($performance->id);
            $result=$holdout['result']??[]; $score=(float)($holdout['score']??0);
            $passed=$performance->paper_status==='passed' && $score>=50
                && (float)($result['profit_factor']??0)>=1.3
                && (float)($result['max_drawdown_percent']??100)<=15
                && (float)data_get($result,'monte_carlo.risk_of_ruin_percent',100)<=10
                && (int)($result['total_trades']??0)>=30;
            $performance->update(['holdout_status'=>$passed?'passed':'failed','holdout_score'=>$score,
                'status'=>$passed?'paper':'rejected']);
            if($passed && $this->marketReadiness->promotionReady() && $this->paperEvidence->ready()){$champion=ModelMarketPerformance::where('symbol',$performance->symbol)->where('timeframe',$performance->timeframe)
                ->where('evidence_status', 'valid')
                ->where('strategy_family',$performance->strategy_family)->where('status','champion')->lockForUpdate()->first();
                if($this->backtestGatesPass($performance,$champion,$performance->metrics??[]))$this->promote($performance,$champion,$performance->modelVersion);}
            LabAgent::where('model_version_id',$performance->model_version_id)->update(['lifecycle_status'=>$performance->fresh()->status,
                'decision_reason'=>$passed?'Sealed holdout and paper gates passed.':'Sealed holdout failed.']);
            return $performance->fresh();
        });
    }

    private function backtestGatesPass(ModelMarketPerformance $candidate, ?ModelMarketPerformance $champion, array $result): bool
    {
        $requiredWins = 3;
        $forwardGain = $champion ? $candidate->forward_score - $champion->forward_score : $candidate->forward_score;

        return $forwardGain >= ($champion ? 5 : 0)
            && (float) ($result['profit_factor'] ?? 0) >= 1.3
            && (float) ($result['max_drawdown_percent'] ?? $result['max_drawdown'] ?? 100) <= 15
            && (float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 100) <= 10
            && ! (bool) ($result['is_overfit'] ?? true)
            && $candidate->sample_count >= 30
            && $candidate->rolling_windows_count >= $requiredWins
            && $candidate->rolling_forward_wins >= $requiredWins;
    }

    private function promote(ModelMarketPerformance $candidate, ?ModelMarketPerformance $champion, ModelVersion $model): void
    {
        if (! $this->marketReadiness->promotionReady() || ! $this->paperEvidence->ready()) {
            return;
        }
        if ($candidate->evidence_status !== 'valid' || $model->evidence_status !== 'valid') {
            return;
        }
        if ($champion && $champion->id !== $candidate->id) {
            $champion->update(['status' => 'archived', 'champion_slot' => null, 'archived_at' => now()]);
            LabAgent::where('model_version_id', $champion->model_version_id)->update(['lifecycle_status' => 'archived']);
        }
        $candidate->update(['status' => 'champion', 'champion_slot' => 'champion', 'promoted_at' => now(), 'consecutive_no_improvement' => 0]);
        $model->update(['status' => 'active', 'promoted_at' => now()]);
    }

    private function updateLabAgentAndMemory(ModelMarketPerformance $performance, ?ModelMarketPerformance $champion, array $result): void
    {
        $agent = LabAgent::where('model_version_id', $performance->model_version_id)->latest()->first();
        if (! $agent) return;
        $delta = $champion ? $performance->forward_score - $champion->forward_score : $performance->forward_score;
        $reason = match ($performance->status) {
            'forward_validated' => 'Backtest gates passed; paper trading required.',
            'overfit' => 'Train-forward gap indicates overfit.',
            'rejected' => 'Risk, profitability or sample gate failed.',
            'stagnated' => 'Three evaluations without improvement.',
            default => 'Evaluation recorded.',
        };
        $agent->update([
            'lifecycle_status' => $performance->status, 'train_score' => $result['train_score'] ?? null,
            'validation_score' => $result['validation_score'] ?? null, 'forward_score' => $performance->forward_score,
            'champion_improvement' => $delta, 'rolling_wins' => $performance->rolling_forward_wins,
            'sample_count' => $performance->sample_count, 'profit_factor' => $result['profit_factor'] ?? null,
            'max_drawdown' => $result['max_drawdown_percent'] ?? $result['max_drawdown'] ?? null,
            'risk_of_ruin' => data_get($result, 'monte_carlo.risk_of_ruin_percent'), 'decision_reason' => $reason,
        ]);
        $regime = collect($result['regime_performance'] ?? [])->sortByDesc('profit_percent')->keys()->first();
        foreach ($agent->parameter_diff ?? [] as $key => $change) {
            MutationMemory::updateOrCreate([
                'lab_agent_id' => $agent->id, 'parameter_key' => $key,
            ], [
                'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe, 'strategy_family' => $agent->strategy_family,
                'old_value' => ['value' => $change['old'] ?? null], 'new_value' => ['value' => $change['new'] ?? null],
                'forward_delta' => $delta, 'market_regime' => $regime,
                'outcome' => $delta >= 5 ? 'beneficial' : ($delta <= -5 ? 'harmful' : 'neutral'),
                'confidence' => min(100, 50 + $performance->rolling_windows_count * 10),
                'decision' => $delta >= 5 ? 'Foydali mutation; keyingi generationda ustuvor.' : ($delta <= -5 ? 'Zararli mutation; shu yo‘nalishni cheklash.' : 'Neutral mutation; qo‘shimcha evidence kerak.'),
            ]);
        }
    }
}
