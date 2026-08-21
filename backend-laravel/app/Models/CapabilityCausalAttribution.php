<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CapabilityCausalAttribution extends Model
{
    protected $fillable = ['attribution_key', 'paper_order_id', 'paper_signal_outcome_id', 'symbol', 'timeframe', 'primary_cause', 'contributions', 'evidence', 'attributed_at'];

    protected $casts = ['contributions' => 'array', 'evidence' => 'array', 'attributed_at' => 'datetime'];
}
