<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackDriftState extends Model
{
    protected $fillable = ['state_key', 'symbol', 'timeframe', 'cell_key', 'lane', 'state', 'baseline_mean', 'cusum_positive', 'cusum_negative', 'last_value', 'sample_count', 'warning_count', 'last_change_at', 'evidence'];
    protected $casts = ['baseline_mean' => 'float', 'cusum_positive' => 'float', 'cusum_negative' => 'float', 'last_value' => 'float', 'last_change_at' => 'datetime', 'evidence' => 'array'];
}
