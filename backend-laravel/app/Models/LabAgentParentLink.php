<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Canonical many-to-many parent contribution for one laboratory agent.
 *
 * parent_a/parent_b remain compatibility projections for older consumers;
 * this relation is the complete graph when a crossover copies different
 * skills from more than two parent models.
 */
class LabAgentParentLink extends Model
{
    protected $fillable = [
        'lab_agent_id',
        'parent_model_version_id',
        'relation_type',
        'contribution_key',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class, 'lab_agent_id');
    }

    public function parentModel(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class, 'parent_model_version_id');
    }
}
