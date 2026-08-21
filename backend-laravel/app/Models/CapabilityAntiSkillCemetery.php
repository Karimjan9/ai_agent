<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CapabilityAntiSkillCemetery extends Model
{
    protected $table = 'capability_anti_skill_cemetery';

    protected $fillable = ['cemetery_key', 'symbol', 'timeframe', 'state_key', 'strategy_id', 'tactic_id', 'failure_mode', 'status', 'failures', 'evidence', 'buried_at'];

    protected $casts = ['evidence' => 'array', 'buried_at' => 'datetime'];
}
