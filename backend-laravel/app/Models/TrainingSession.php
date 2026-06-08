<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingSession extends Model
{
    protected $fillable = [
        'title',
        'symbol',
        'timeframe',
        'agents_count',
        'best_strategy',
        'best_score',
        'worst_strategy',
        'worst_score',
        'total_trades',
        'average_winrate',
        'average_profit',
        'average_drawdown',
        'average_profit_factor',
        'average_stability_score',
        'ai_conclusion',
        'next_training_plan',
        'raw_leaderboard',
        'status',
        'metrics',
        'notes',
    ];

    protected $casts = [
        'raw_leaderboard' => 'array',
        'metrics' => 'array',
    ];

    public function strategyScores(): HasMany
    {
        return $this->hasMany(StrategyScore::class);
    }

    public function evolutionProposals(): HasMany
    {
        return $this->hasMany(EvolutionProposal::class);
    }

    public function trainingLogs(): HasMany
    {
        return $this->hasMany(TrainingLog::class);
    }
}
