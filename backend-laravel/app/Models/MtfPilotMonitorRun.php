<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class MtfPilotMonitorRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'pilot_id', 'symbol', 'status', 'health_score', 'lookback_hours',
        'latest_h1_open_at', 'latest_h1_closed_at', 'latest_m15_open_at',
        'latest_m15_closed_at', 'report', 'checked_at',
    ];

    protected $casts = [
        'health_score' => 'float',
        'latest_h1_open_at' => 'datetime',
        'latest_h1_closed_at' => 'datetime',
        'latest_m15_open_at' => 'datetime',
        'latest_m15_closed_at' => 'datetime',
        'report' => 'array',
        'checked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('MTF monitor snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('MTF monitor snapshots are immutable.'));
    }
}
