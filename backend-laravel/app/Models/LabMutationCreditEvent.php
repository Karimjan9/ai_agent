<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabMutationCreditEvent extends Model
{
    protected $fillable = [
        'mutation_memory_id', 'lab_generation_id', 'lab_agent_id', 'model_version_id',
        'model_market_performance_id', 'parameter_key', 'mutation_bundle_id', 'outcome',
        'forward_delta', 'parent_model_version_id', 'control_model_version_id',
        'evidence_run_ids', 'temporal_window_key', 'reconciliation_key', 'evidence_fingerprint', 'payload', 'recorded_at',
    ];

    protected $casts = ['evidence_run_ids' => 'array', 'payload' => 'array', 'recorded_at' => 'datetime'];

    public function mutationMemory(): BelongsTo { return $this->belongsTo(MutationMemory::class, 'mutation_memory_id'); }
    public function agent(): BelongsTo { return $this->belongsTo(LabAgent::class, 'lab_agent_id'); }
}
