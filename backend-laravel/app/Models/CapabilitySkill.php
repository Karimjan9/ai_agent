<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CapabilitySkill extends Model
{
    protected $fillable = ['capability_cell_id', 'skill_key', 'symbol', 'timeframe', 'state_key', 'strategy_id', 'tactic_id', 'status', 'data_hash', 'execution_hash', 'independent_windows', 'positive_windows', 'non_target_regression', 'independently_confirmed', 'contract', 'evidence', 'compiled_at', 'last_validated_at', 'expires_at', 'last_success_at', 'drift_score', 'reference_state_distribution', 'current_state_distribution', 'performance_decay', 'revalidation_required'];

    protected $casts = ['non_target_regression' => 'boolean', 'independently_confirmed' => 'boolean', 'revalidation_required' => 'boolean', 'contract' => 'array', 'evidence' => 'array', 'compiled_at' => 'datetime', 'last_validated_at' => 'datetime', 'expires_at' => 'datetime', 'last_success_at' => 'datetime', 'reference_state_distribution' => 'array', 'current_state_distribution' => 'array'];
}
