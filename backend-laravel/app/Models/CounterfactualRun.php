<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CounterfactualRun extends Model
{
    protected $fillable = [
        'agent_hypothesis_id',
        'strategy_score_id',
        'training_session_id',
        'scenario_name',
        'intervention',
        'baseline_result',
        'alternative_result',
        'delta_percent',
        'verdict',
        'explanation',
    ];

    protected $casts = [
        'intervention' => 'array',
        'baseline_result' => 'array',
        'alternative_result' => 'array',
    ];

    public function agentHypothesis(): BelongsTo
    {
        return $this->belongsTo(AgentHypothesis::class);
    }

    public function strategyScore(): BelongsTo
    {
        return $this->belongsTo(StrategyScore::class);
    }

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }
}
