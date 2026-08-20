<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackCellStatistic extends Model
{
    protected $fillable = ['stat_key', 'symbol', 'timeframe', 'cell_key', 'lane', 'settled_count', 'known_count', 'wins', 'action_count', 'risk_violation_count', 'reward_sum', 'reward_sq_sum', 'regret_sum', 'last_observed_at'];
    protected $casts = ['last_observed_at' => 'datetime', 'reward_sum' => 'float', 'reward_sq_sum' => 'float', 'regret_sum' => 'float'];
}
