<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable record of one control-versus-playbook M5 replay. */
class MtfPlaybookFrozenControlRun extends Model
{
    protected $fillable = [
        'run_key', 'protocol', 'research_model_id', 'symbol', 'entry_timeframe',
        'related_symbol', 'data_hash', 'execution_hash', 'control_parameter_hash',
        'candidate_parameter_hash', 'status', 'required_streams', 'dataset_manifest',
        'control_result', 'candidate_result', 'comparison', 'reason_codes',
        'promotion_evidence', 'completed_at',
    ];

    protected $casts = [
        'required_streams' => 'array',
        'dataset_manifest' => 'array',
        'control_result' => 'array',
        'candidate_result' => 'array',
        'comparison' => 'array',
        'reason_codes' => 'array',
        'promotion_evidence' => 'boolean',
        'completed_at' => 'datetime',
    ];
}
