<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabLearningLanePair extends Model
{
    protected $fillable = [
        'pair_key', 'lab_generation_id', 'candidate_agent_id', 'control_agent_id',
        'candidate_response_map_id', 'control_response_map_id', 'symbol', 'timeframe',
        'strategy_family', 'target', 'specialist_role', 'baseline_source', 'status',
        'candidate_evidence_run_id', 'control_evidence_run_id', 'independent_window_key',
        'candidate_data_hash', 'control_data_hash', 'candidate_execution_hash',
        'control_execution_hash', 'pair_integrity_status', 'same_generation',
        'candidate_metrics', 'control_metrics', 'target_delta', 'non_target_regression',
        'failure_signature', 'metadata',
    ];

    protected $casts = [
        'candidate_metrics' => 'array', 'control_metrics' => 'array',
        'target_delta' => 'array', 'non_target_regression' => 'array',
        'failure_signature' => 'array', 'metadata' => 'array',
        'same_generation' => 'boolean',
    ];

    public function generation(): BelongsTo
    {
        return $this->belongsTo(LabGeneration::class, 'lab_generation_id');
    }

    public function candidateAgent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class, 'candidate_agent_id');
    }

    public function controlAgent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class, 'control_agent_id');
    }

    public function candidateResponseMap(): BelongsTo
    {
        return $this->belongsTo(LabMutationResponseMap::class, 'candidate_response_map_id');
    }

    public function controlResponseMap(): BelongsTo
    {
        return $this->belongsTo(LabMutationResponseMap::class, 'control_response_map_id');
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(LabLearningLaneDispatch::class, 'pair_id');
    }
}
