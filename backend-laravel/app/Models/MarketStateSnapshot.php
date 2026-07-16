<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MarketStateSnapshot extends Model
{
    protected $fillable = [
        'symbol_id',
        'candle_id',
        'market_species_id',
        'symbol',
        'timeframe',
        'time',
        'market_state',
        'liquidity_state',
        'momentum_state',
        'structure_state',
        'confidence_score',
        'trend_score',
        'panic_score',
        'compression_score',
        'expansion_score',
        'momentum_score',
        'liquidity_proxy_score',
        'features',
        'explanation',
    ];

    protected $casts = [
        'time' => 'datetime',
        'features' => 'array',
    ];

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    public function candle(): BelongsTo
    {
        return $this->belongsTo(Candle::class);
    }

    public function marketSpecies(): BelongsTo
    {
        return $this->belongsTo(MarketSpecies::class);
    }

    public function probabilities(): HasMany
    {
        return $this->hasMany(MarketStateProbability::class);
    }

    public function genome(): HasOne
    {
        return $this->hasOne(MarketGenome::class);
    }
}
