<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabLifecycleCycle extends Model
{
    protected $fillable = [
        'cycle_id', 'symbol', 'timeframe', 'status', 'stage', 'summary',
        'context', 'started_at', 'heartbeat_at', 'finished_at',
    ];

    protected $casts = [
        'context' => 'array', 'started_at' => 'datetime',
        'heartbeat_at' => 'datetime', 'finished_at' => 'datetime',
    ];
}
