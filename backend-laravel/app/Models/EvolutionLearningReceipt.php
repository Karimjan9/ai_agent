<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvolutionLearningReceipt extends Model
{
    protected $fillable = ['receipt_key', 'claim_key', 'lab_agent_id', 'lab_generation_id', 'source_type', 'source_key', 'symbol', 'timeframe', 'component', 'action', 'status', 'claim', 'causal_uplift_r', 'confidence', 'support', 'scope', 'source_experiments', 'evidence', 'expires_at', 'compiled_at'];
    protected $casts = ['scope' => 'array', 'source_experiments' => 'array', 'evidence' => 'array', 'causal_uplift_r' => 'float', 'confidence' => 'float', 'expires_at' => 'datetime', 'compiled_at' => 'datetime'];
}
