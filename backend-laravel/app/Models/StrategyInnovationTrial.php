<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StrategyInnovationTrial extends Model
{
    protected $fillable = ['trial_key', 'lab_agent_id', 'playbook_composition_id', 'status', 'instrument_keys', 'control_contract', 'behavior_contract', 'evidence', 'settled_at'];

    protected $casts = ['instrument_keys' => 'array', 'control_contract' => 'array', 'behavior_contract' => 'array', 'evidence' => 'array', 'settled_at' => 'datetime'];
}
