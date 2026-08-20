<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackGeneProof extends Model
{
    protected $fillable = ['proof_key', 'model_market_performance_id', 'symbol', 'timeframe', 'cell_key', 'sample_count', 'bootstrap_lower_bound', 'deflated_sharpe_probability', 'pbo_probability', 'status', 'evidence'];
    protected $casts = ['bootstrap_lower_bound' => 'float', 'deflated_sharpe_probability' => 'float', 'pbo_probability' => 'float', 'evidence' => 'array'];
}
