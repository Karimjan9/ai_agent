<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemEvent extends Model
{
    protected $fillable = ['event_type', 'event_key', 'source_type', 'source_id', 'agent', 'symbol', 'timeframe', 'market_state_snapshot_id', 'severity', 'summary', 'payload', 'occurred_at'];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function marketSnapshot(): BelongsTo
    {
        return $this->belongsTo(MarketStateSnapshot::class, 'market_state_snapshot_id');
    }
}
