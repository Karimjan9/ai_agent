<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabLearningConsumptionEvent extends Model
{
    protected $fillable = [
        'event_id', 'lab_learning_insight_id', 'lab_generation_id', 'symbol', 'timeframe',
        'strategy_family', 'role', 'target', 'evidence_quality', 'causal_prior_allowed',
        'selected_keys', 'payload', 'recorded_at',
    ];

    protected $casts = [
        'causal_prior_allowed' => 'boolean', 'selected_keys' => 'array', 'payload' => 'array',
        'recorded_at' => 'datetime',
    ];

    public function insight(): BelongsTo
    {
        return $this->belongsTo(LabLearningInsight::class, 'lab_learning_insight_id');
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(LabGeneration::class, 'lab_generation_id');
    }
}
