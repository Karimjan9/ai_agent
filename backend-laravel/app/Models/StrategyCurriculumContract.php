<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StrategyCurriculumContract extends Model
{
    protected $fillable = ['contract_key', 'lab_agent_id', 'model_version_id', 'strategy_id', 'strategy_version', 'tactic_id', 'mastery_lane', 'training_stage', 'tactic_mastery_stage', 'allowed_instruments', 'forbidden_instruments', 'target_regimes', 'target_sessions', 'entry_contract', 'exit_contract', 'sizing_contract', 'risk_contract', 'control_contract', 'control_pair_key', 'innovation_budget', 'state'];

    protected $casts = ['allowed_instruments' => 'array', 'forbidden_instruments' => 'array', 'target_regimes' => 'array', 'target_sessions' => 'array', 'entry_contract' => 'array', 'exit_contract' => 'array', 'sizing_contract' => 'array', 'risk_contract' => 'array', 'control_contract' => 'array'];
}
