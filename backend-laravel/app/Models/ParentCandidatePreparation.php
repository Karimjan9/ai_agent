<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParentCandidatePreparation extends Model
{
    protected $table = 'lab_parent_candidate_preparations';

    protected $fillable = [
        'preparation_key', 'model_version_id', 'lab_agent_id', 'symbol', 'timeframe',
        'strategy_family', 'council_role', 'status', 'idea_type', 'idea',
        'required_evidence', 'source_metrics', 'promotion_evidence', 'evaluated_at',
    ];

    protected $casts = [
        'idea' => 'array', 'required_evidence' => 'array', 'source_metrics' => 'array',
        'promotion_evidence' => 'boolean', 'evaluated_at' => 'datetime',
    ];
}
