<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentHypothesis extends Model
{
    protected $fillable = [
        'training_session_id',
        'strategy_score_id',
        'strategy',
        'symbol',
        'timeframe',
        'decision',
        'confidence',
        'market_regime',
        'volatility_regime',
        'hypothesis',
        'measurable_target',
        'horizon_candles',
        'expected_move_atr',
        'actual_outcome',
        'status',
        'evaluation_summary',
        'evidence_snapshot',
        'evaluated_at',
    ];

    protected $casts = [
        'measurable_target' => 'array',
        'actual_outcome' => 'array',
        'evidence_snapshot' => 'array',
        'evaluated_at' => 'datetime',
    ];

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    public function strategyScore(): BelongsTo
    {
        return $this->belongsTo(StrategyScore::class);
    }

    public function counterfactualRuns(): HasMany
    {
        return $this->hasMany(CounterfactualRun::class);
    }
}
