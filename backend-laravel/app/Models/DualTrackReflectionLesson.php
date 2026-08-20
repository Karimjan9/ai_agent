<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackReflectionLesson extends Model
{
    protected $fillable = [
        'reflection_key', 'dual_track_outcome_id', 'symbol', 'timeframe', 'cell_key', 'lane',
        'failure_class', 'reflection', 'independent_confirmations', 'status', 'evidence', 'promoted_at',
    ];

    protected $casts = ['independent_confirmations' => 'integer', 'evidence' => 'array', 'promoted_at' => 'datetime'];
}
