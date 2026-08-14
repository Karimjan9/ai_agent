<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabFailureDojoRun extends Model
{
    protected $fillable = [
        'dojo_key', 'pair_id', 'candidate_agent_id', 'repair_anchor_id', 'symbol', 'timeframe',
        'family', 'target', 'state_signature', 'expected_action', 'status', 'score',
        'failure_signature', 'evidence', 'evaluated_at',
    ];

    protected $casts = [
        'failure_signature' => 'array',
        'evidence' => 'array',
        'score' => 'float',
        'evaluated_at' => 'datetime',
    ];
}
