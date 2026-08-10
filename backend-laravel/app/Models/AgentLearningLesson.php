<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentLearningLesson extends Model
{
    protected $fillable = [
        'lesson_id', 'lesson_hash', 'lab_agent_id', 'model_version_id',
        'symbol', 'timeframe', 'strategy_family', 'lesson_type', 'status',
        'failure_class', 'parameter_key', 'state_cluster_id', 'regime',
        'volatility', 'transition_state', 'spread_liquidity_state', 'veto_reason',
        'outcome', 'independent_window_count', 'confirmation_count',
        'lower_confidence_bound', 'source_run_ids', 'evidence', 'observed_at',
        'expires_at',
    ];

    protected $casts = [
        'source_run_ids' => 'array', 'evidence' => 'array',
        'lower_confidence_bound' => 'float', 'observed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function labAgent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class);
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }
}
