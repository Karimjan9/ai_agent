<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * External knowledge is a research prior only. It can never be promoted as
 * local trading evidence or used as a runtime trading authority.
 */
class KnowledgePrior extends Model
{
    protected $fillable = [
        'prior_id', 'source_class', 'symbol_scope', 'knowledge_tier',
        'hypothesis', 'temporal_roles', 'risk_contract', 'management_contract',
        'falsification_contract', 'authority_contract', 'external_score',
        'influence_weight', 'status', 'retired_at',
    ];

    protected $casts = [
        'hypothesis' => 'array', 'temporal_roles' => 'array', 'risk_contract' => 'array',
        'management_contract' => 'array', 'falsification_contract' => 'array',
        'authority_contract' => 'array', 'external_score' => 'float',
        'influence_weight' => 'float', 'retired_at' => 'datetime',
    ];
}
