<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabTrialLedger extends Model
{
    protected $table = 'lab_trial_ledger';

    protected $fillable = [
        'lab_generation_id', 'lab_agent_id', 'model_version_id', 'symbol', 'timeframe',
        'strategy_family', 'stage', 'run_id', 'parameter_hash', 'data_manifest_hash',
        'execution_hash', 'trial_index', 'trial_count', 'score', 'observed_sharpe',
        'selection_penalty_points', 'selection_adjusted_score', 'status', 'metrics', 'evaluated_at',
    ];

    protected $casts = [
        'metrics' => 'array',
        'evaluated_at' => 'datetime',
    ];
}
