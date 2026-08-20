<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiskSentinelDecision extends Model
{
    protected $fillable = ['decision_key', 'paper_signal_id', 'model_market_performance_id', 'symbol', 'timeframe', 'decision', 'reason_code', 'equity', 'risk_budget_percent', 'position_size_multiple', 'plan', 'decided_at'];

    protected $casts = ['plan' => 'array', 'decided_at' => 'datetime'];
}
