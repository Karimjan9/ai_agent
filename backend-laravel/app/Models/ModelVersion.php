<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelVersion extends Model
{
    protected $fillable = [
        'name',
        'strategy',
        'version',
        'generation',
        'status',
        'best_score',
        'best_winrate',
        'best_profit',
        'best_drawdown',
        'description',
        'change_log',
        'parameters',
        'promoted_at',
        'metadata',
        'evidence_status',
        'invalidated_at',
        'invalidation_reason',
    ];

    protected $casts = [
        'parameters' => 'array',
        'metadata' => 'array',
        'promoted_at' => 'datetime',
        'invalidated_at' => 'datetime',
    ];

    public function evolutionProposals(): HasMany
    {
        return $this->hasMany(EvolutionProposal::class);
    }

    public function strategyGenomes(): HasMany
    {
        return $this->hasMany(StrategyGenome::class);
    }

    public function marketPerformances(): HasMany
    {
        return $this->hasMany(ModelMarketPerformance::class);
    }

    public function labAgents(): HasMany
    {
        return $this->hasMany(LabAgent::class);
    }
}
