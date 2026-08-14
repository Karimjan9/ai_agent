<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabCouncilAblationRun extends Model
{
    protected $fillable = [
        'symbol', 'timeframe', 'council_key', 'member_role',
        'excluded_member_model_version_id', 'full_council_model_version_id',
        'context_key', 'snapshot_hash', 'execution_hash', 'status', 'incremental_delta',
        'evidence_run_id', 'metrics', 'payload', 'promotion_evidence', 'completed_at',
    ];

    protected $casts = [
        'incremental_delta' => 'float',
        'metrics' => 'array',
        'payload' => 'array',
        'promotion_evidence' => 'boolean',
        'completed_at' => 'datetime',
    ];
}
