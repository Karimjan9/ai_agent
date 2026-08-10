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
        'evidence_status',
        'invalidated_at',
        'invalidation_reason',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'raw_leaderboard' => 'array',
        'metrics' => 'array',
        'invalidated_at' => 'datetime',
    ];

    public function strategyScores(): HasMany
    {
        return $this->hasMany(StrategyScore::class);
    }

    public function agentHypotheses(): HasMany
    {
        return $this->hasMany(AgentHypothesis::class);
    }

    public function scientistJournals(): HasMany
    {
        return $this->hasMany(ScientistJournal::class);
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

    public function memories(): HasMany
    {
        return $this->hasMany(AgentMemory::class);
    }

    public function internalDebates(): HasMany
    {
        return $this->hasMany(InternalDebate::class);
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

    public function evolutionProposals(): HasMany
    {
        return $this->hasMany(EvolutionProposal::class);
    }

    public function trainingLogs(): HasMany
    {
        return $this->hasMany(TrainingLog::class);
    }
}
