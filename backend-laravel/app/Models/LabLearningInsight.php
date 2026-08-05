<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabLearningInsight extends Model
{
    protected $fillable = [
        'insight_id', 'symbol', 'timeframe', 'strategy_family', 'scope_key', 'insight_type',
        'evidence_quality', 'causal_prior_allowed', 'confidence', 'source_hash',
        'source_generation_ids', 'source_agent_ids', 'source_run_ids', 'source_event_ids',
        'failure_signature', 'metrics', 'recommended_mutations', 'blocked_mutations',
        'conclusion', 'generated_at',
    ];

    protected $casts = [
        'causal_prior_allowed' => 'boolean',
        'source_generation_ids' => 'array', 'source_agent_ids' => 'array', 'source_run_ids' => 'array',
        'source_event_ids' => 'array', 'failure_signature' => 'array', 'metrics' => 'array',
        'recommended_mutations' => 'array', 'blocked_mutations' => 'array', 'generated_at' => 'datetime',
    ];

    public function consumptionEvents(): HasMany
    {
        return $this->hasMany(LabLearningConsumptionEvent::class, 'lab_learning_insight_id');
    }
}
