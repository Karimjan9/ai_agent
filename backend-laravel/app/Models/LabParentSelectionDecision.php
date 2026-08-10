<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabParentSelectionDecision extends Model
{
    protected $fillable = [
        'lab_generation_id', 'lab_agent_id', 'symbol', 'timeframe', 'strategy_family',
        'origin', 'target', 'island_key', 'mode', 'candidate_count', 'selected_count',
        'selected_parent_model_version_ids', 'candidate_scores', 'policy',
        'diversity_score', 'progress_score', 'exploration_ratio', 'promotion_evidence',
    ];

    protected $casts = [
        'selected_parent_model_version_ids' => 'array',
        'candidate_scores' => 'array',
        'policy' => 'array',
        'diversity_score' => 'float',
        'progress_score' => 'float',
        'exploration_ratio' => 'float',
        'promotion_evidence' => 'boolean',
    ];

    public function generation(): BelongsTo
    {
        return $this->belongsTo(LabGeneration::class, 'lab_generation_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class, 'lab_agent_id');
    }
}
