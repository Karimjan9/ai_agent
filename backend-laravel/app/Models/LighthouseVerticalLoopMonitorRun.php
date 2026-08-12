<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LighthouseVerticalLoopMonitorRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'lab_generation_id', 'symbol', 'timeframe', 'generation', 'stage',
        'status', 'health_score', 'report', 'checked_at',
    ];

    protected $casts = [
        'report' => 'array',
        'checked_at' => 'datetime',
    ];
}
