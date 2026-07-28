<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperConfidenceCalibration extends Model
{
    protected $fillable = ['scope_key', 'model_market_performance_id', 'symbol', 'timeframe', 'strategy_family', 'market_regime', 'sample_count', 'brier_score', 'reliability_error', 'bins', 'calibrated_at'];
    protected $casts = ['bins' => 'array', 'calibrated_at' => 'datetime'];
}
