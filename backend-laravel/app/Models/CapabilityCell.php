<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CapabilityCell extends Model
{
    protected $fillable = ['cell_key', 'symbol', 'timeframe', 'regime', 'session', 'strategy_id', 'tactic_id', 'risk_regime', 'execution_environment', 'regime_probability', 'transition_hazard', 'state_confidence', 'state_posterior'];

    protected $casts = ['state_posterior' => 'array'];
}
