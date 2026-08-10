<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentProgressCard extends Model
{
    protected $fillable = [
        'lab_agent_id', 'model_version_id', 'symbol', 'timeframe', 'strategy_family',
        'tactic_id', 'tactic_contract',
        'stage', 'status', 'primary_failure', 'changed_gene', 'repair_attempt',
        'parent_model_version_id', 'parent_diff', 'gates_passed', 'failure_codes',
        'next_action', 'stage_history', 'frozen_at', 'last_evaluated_at',
        'last_evidence_run_id', 'last_result_hash',
    ];

    protected $casts = [
        'repair_attempt' => 'integer',
        'tactic_contract' => 'array',
        'parent_diff' => 'array',
        'gates_passed' => 'array',
        'failure_codes' => 'array',
        'stage_history' => 'array',
        'frozen_at' => 'datetime',
        'last_evaluated_at' => 'datetime',
    ];

    public function labAgent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class);
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }
}
