<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StrategyScore extends Model
{
    protected $fillable = [
        'training_session_id',
        'symbol',
        'timeframe',
        'strategy',
        'parameters',
        'score',
        'train_score',
        'validation_score',
        'forward_score',
        'robustness_score',
        'is_overfit',
        'mc_worst_profit_percent',
        'mc_avg_profit_percent',
        'mc_best_profit_percent',
        'mc_worst_drawdown_percent',
        'mc_avg_drawdown_percent',
        'mc_risk_of_ruin_percent',
        'mc_worst_equity_curve',
        'mc_best_equity_curve',
        'total_trades',
        'wins',
        'losses',
        'winrate',
        'net_profit_percent',
        'max_drawdown_percent',
        'profit_factor',
        'average_win_percent',
        'average_loss_percent',
        'risk_reward_ratio',
        'max_consecutive_losses',
        'stability_score',
        'equity_curve',
        'regime_performance',
        'volatility_performance',
        'raw_result',
        'evidence_status',
        'invalidated_at',
        'invalidation_reason',
    ];

    protected $casts = [
        'parameters' => 'array',
        'is_overfit' => 'boolean',
        'equity_curve' => 'array',
        'regime_performance' => 'array',
        'volatility_performance' => 'array',
        'mc_worst_equity_curve' => 'array',
        'mc_best_equity_curve' => 'array',
        'raw_result' => 'array',
        'invalidated_at' => 'datetime',
    ];

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    public function dnaProfile(): HasOne
    {
        return $this->hasOne(StrategyDnaProfile::class);
    }

    public function agentHypotheses(): HasMany
    {
        return $this->hasMany(AgentHypothesis::class);
    }

    public function counterfactualRuns(): HasMany
    {
        return $this->hasMany(CounterfactualRun::class);
    }

    public function psychologySnapshots(): HasMany
    {
        return $this->hasMany(AgentPsychologySnapshot::class);
    }

    public function selfReflections(): HasMany
    {
        return $this->hasMany(AgentSelfReflection::class);
    }

    public function evolutionTriggers(): HasMany
    {
        return $this->hasMany(EvolutionTrigger::class);
    }

    public function strategyGenomes(): HasMany
    {
        return $this->hasMany(StrategyGenome::class);
    }

    public function fitnessEvaluations(): HasMany
    {
        return $this->hasMany(FitnessEvaluation::class);
    }
}
