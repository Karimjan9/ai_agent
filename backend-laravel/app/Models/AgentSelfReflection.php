<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentSelfReflection extends Model
{
    protected $fillable = [
        'training_session_id',
        'strategy_score_id',
        'agent_psychology_snapshot_id',
        'strategy',
        'reflection',
        'observations',
        'suggested_action',
        'stress',
        'adaptation_pressure',
    ];

    protected $casts = [
        'observations' => 'array',
    ];

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    public function strategyScore(): BelongsTo
    {
        return $this->belongsTo(StrategyScore::class);
    }

    public function psychologySnapshot(): BelongsTo
    {
        return $this->belongsTo(AgentPsychologySnapshot::class, 'agent_psychology_snapshot_id');
    }
}
