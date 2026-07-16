<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketProviderHealth extends Model
{
    protected $table = 'market_provider_health';

    protected $fillable = [
        'provider',
        'symbol',
        'timeframe',
        'last_candle_at',
        'last_seen_at',
        'status',
        'age_seconds',
        'stale_after_seconds',
        'lost_after_seconds',
        'alert_sent',
        'alert_sent_at',
        'auto_recovery_attempted',
        'auto_recovery_attempted_at',
        'message',
        'metrics',
    ];

    protected $casts = [
        'last_candle_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'alert_sent' => 'boolean',
        'alert_sent_at' => 'datetime',
        'auto_recovery_attempted' => 'boolean',
        'auto_recovery_attempted_at' => 'datetime',
        'metrics' => 'array',
    ];
}
