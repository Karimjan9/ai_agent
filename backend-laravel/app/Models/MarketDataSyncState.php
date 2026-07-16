<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketDataSyncState extends Model
{
    protected $fillable = [
        'provider', 'symbol', 'timeframe', 'last_confirmed_candle_at',
        'pending_from_at', 'pending_to_at', 'status', 'retry_count',
        'last_error', 'last_attempt_at', 'last_success_at', 'recovered_at', 'metrics',
    ];

    protected $casts = [
        'last_confirmed_candle_at' => 'datetime',
        'pending_from_at' => 'datetime',
        'pending_to_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'last_success_at' => 'datetime',
        'recovered_at' => 'datetime',
        'metrics' => 'array',
    ];
}
