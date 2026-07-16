<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketGenome extends Model
{
    protected $fillable = [
        'market_state_snapshot_id',
        'market_species_id',
        'symbol',
        'timeframe',
        'time',
        'genome_hash',
        'vector',
        'trend',
        'panic',
        'compression',
        'momentum',
        'liquidity_proxy',
    ];

    protected $casts = [
        'time' => 'datetime',
        'vector' => 'array',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(MarketStateSnapshot::class, 'market_state_snapshot_id');
    }

    public function marketSpecies(): BelongsTo
    {
        return $this->belongsTo(MarketSpecies::class);
    }

    public function similarityMatches(): HasMany
    {
        return $this->hasMany(MarketSimilarityMatch::class, 'current_market_genome_id');
    }
}
