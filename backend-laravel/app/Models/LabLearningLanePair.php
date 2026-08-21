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

    /**
     * The only predicate that may unlock canonical learning.  Historical
     * "screen_paired" rows are intentionally not grandfathered in: a control
     * must carry the frozen-control contract as well as matching seals.
     */
    public function isVerifiedControlPair(): bool
    {
        $control = $this->controlResponseMap;
        $contract = (array) data_get($control?->metadata, 'control_contract', []);

        return (string) $this->pair_integrity_status === 'verified'
            && (bool) $this->same_generation
            && (int) $this->lab_generation_id > 0
            && (int) $this->control_agent_id > 0
            && (int) $this->control_response_map_id > 0
            && $control !== null
            && (string) $control->status === 'control'
            && (string) data_get($contract, 'protocol') === 'frozen_control_v2'
            && data_get($contract, 'control_only') === true
            && (string) data_get($contract, 'role') === 'control'
            && (int) data_get($contract, 'generation_id') === (int) $this->lab_generation_id
            && filled($this->candidate_data_hash)
            && filled($this->control_data_hash)
            && filled($this->candidate_execution_hash)
            && filled($this->control_execution_hash)
            && hash_equals((string) $this->candidate_data_hash, (string) $this->control_data_hash)
            && hash_equals((string) $this->candidate_execution_hash, (string) $this->control_execution_hash)
            && hash_equals((string) $this->control_data_hash, (string) data_get($contract, 'data_hash'))
            && hash_equals((string) $this->control_execution_hash, (string) data_get($contract, 'execution_hash'));
    }
}
