<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable provenance for a laboratory inheritance decision.
 *
 * The model metadata is the candidate's local contract. This table is the
 * queryable handoff ledger: it records which control root/parent was accepted,
 * which semantic cell was requested, and which hashes were checked. It never
 * grants promotion evidence.
 */
class LabAgentInheritanceAudit extends Model
{
    protected $fillable = [
        'lab_agent_id',
        'source_model_version_id',
        'source_agent_id',
        'protocol',
        'transition',
        'decision',
        'semantic_group_key',
        'seed_hash',
        'child_parameter_hash',
        'contract_hash',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class, 'lab_agent_id');
    }

    public function sourceModel(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class, 'source_model_version_id');
    }

    public function sourceAgent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class, 'source_agent_id');
    }
}
