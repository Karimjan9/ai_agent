<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentPsychologySnapshot extends Model
{
    protected $fillable = [
        'training_session_id',
        'strategy_score_id',
        'strategy',
        'confidence',
        'stress',
        'trust',
        'adaptation_pressure',
        'stability',
        'learning_rate',
        'state',
        'metrics',
    ];

    protected $casts = [
        'metrics' => 'array',
    ];

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    public function strategyScore(): BelongsTo
    {
        return $this->belongsTo(StrategyScore::class);
    }

    public function selfReflections(): HasMany
    {
        return $this->hasMany(AgentSelfReflection::class);
    }

    public function evolutionTriggers(): HasMany
    {
        return $this->hasMany(EvolutionTrigger::class);
    }
}
