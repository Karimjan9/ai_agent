<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackEvidenceWorkItem extends Model
{
    protected $fillable = ['work_key', 'dual_track_run_id', 'dual_track_outcome_id', 'symbol', 'timeframe', 'cell_key', 'work_type', 'status', 'priority', 'attempts', 'payload', 'result', 'last_error', 'available_at', 'leased_at', 'completed_at'];
    protected $casts = ['payload' => 'array', 'result' => 'array', 'available_at' => 'datetime', 'leased_at' => 'datetime', 'completed_at' => 'datetime'];
}
