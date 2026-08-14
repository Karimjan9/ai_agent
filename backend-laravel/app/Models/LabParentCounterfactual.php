<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabParentCounterfactual extends Model
{
    protected $fillable = [
        'candidate_agent_id', 'candidate_model_version_id', 'parent_model_version_id',
        'autonomous_model_version_id', 'ablation_model_version_id', 'symbol', 'timeframe',
        'strategy_family', 'context_key', 'snapshot_hash', 'execution_hash', 'status',
        'autonomous_score', 'mentored_score', 'ablated_score', 'parent_incremental_value',
        'evidence_run_ids', 'payload', 'evaluated_at',
    ];

    protected $casts = [
        'autonomous_score' => 'float',
        'mentored_score' => 'float',
        'ablated_score' => 'float',
        'parent_incremental_value' => 'float',
        'evidence_run_ids' => 'array',
        'payload' => 'array',
        'evaluated_at' => 'datetime',
    ];

    public function candidateAgent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class, 'candidate_agent_id');
    }

    public function parentModel(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class, 'parent_model_version_id');
    }
}
