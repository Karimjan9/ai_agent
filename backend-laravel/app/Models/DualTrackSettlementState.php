<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackSettlementState extends Model
{
    protected $fillable = ['state_key', 'dual_track_run_id', 'paper_signal_outcome_id', 'symbol', 'timeframe', 'stage', 'attempts', 'last_error', 'completed_stages', 'payload', 'last_attempted_at', 'completed_at'];
    protected $casts = ['completed_stages' => 'array', 'payload' => 'array', 'last_attempted_at' => 'datetime', 'completed_at' => 'datetime'];
}
