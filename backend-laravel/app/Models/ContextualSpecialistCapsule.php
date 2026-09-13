<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContextualSpecialistCapsule extends Model
{
    protected $fillable = [
        'capsule_key', 'symbol', 'timeframe', 'context_cell_key', 'model_version_id',
        'lab_agent_id', 'identity', 'components', 'activation_contract', 'pareto_vector',
        'evidence', 'authority_level', 'status', 'outside_scope_activation_count', 'replaces_capsule_id',
    ];

    protected $casts = [
        'identity' => 'array', 'components' => 'array', 'activation_contract' => 'array',
        'pareto_vector' => 'array', 'evidence' => 'array',
        'outside_scope_activation_count' => 'integer',
    ];
}
