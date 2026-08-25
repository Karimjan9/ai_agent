<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiskHysteresisState extends Model
{
    protected $fillable = ['state_key', 'symbol', 'timeframe', 'state', 'risk_multiplier', 'transition_reason', 'metrics', 'changed_at'];
    protected $casts = ['risk_multiplier' => 'float', 'metrics' => 'array', 'changed_at' => 'datetime'];
}
