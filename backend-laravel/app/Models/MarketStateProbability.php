<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketStateProbability extends Model
{
    protected $fillable = [
        'market_state_snapshot_id',
        'state',
        'probability',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(MarketStateSnapshot::class, 'market_state_snapshot_id');
    }
}
