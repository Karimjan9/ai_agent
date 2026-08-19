<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabCausalControlPlan extends Model
{
    protected $fillable = [
        'plan_key', 'pair_id', 'candidate_response_map_id', 'symbol', 'timeframe',
        'strategy_family', 'target', 'specialist_role', 'dataset_hash', 'execution_hash',
        'temporal_window_key', 'status', 'control_generation_id', 'control_agent_id',
        'control_evidence_run_id', 'contract', 'metadata', 'promotion_evidence', 'applied_at',
    ];

    protected $casts = [
        'contract' => 'array', 'metadata' => 'array',
        'promotion_evidence' => 'boolean', 'applied_at' => 'datetime',
    ];

    public function pair(): BelongsTo
    {
        return $this->belongsTo(LabLearningLanePair::class, 'pair_id');
    }

    public function candidateResponseMap(): BelongsTo
    {
        return $this->belongsTo(LabMutationResponseMap::class, 'candidate_response_map_id');
    }

    public function controlGeneration(): BelongsTo
    {
        return $this->belongsTo(LabGeneration::class, 'control_generation_id');
    }

    public function controlAgent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class, 'control_agent_id');
    }
}
