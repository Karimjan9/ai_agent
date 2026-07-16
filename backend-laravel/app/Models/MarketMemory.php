<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketMemory extends Model
{
    protected $fillable = [
        'market_species_id',
        'market_state_snapshot_id',
        'symbol',
        'timeframe',
        'memory_type',
        'market_state',
        'summary',
        'lesson',
        'strength',
        'evidence',
    ];

    protected $casts = [
        'evidence' => 'array',
    ];

    public function marketSpecies(): BelongsTo
    {
        return $this->belongsTo(MarketSpecies::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(MarketStateSnapshot::class, 'market_state_snapshot_id');
    }
}
