<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignalMarketSnapshot extends Model
{
    protected $fillable = ['signal_type', 'signal_key', 'strategy', 'symbol', 'timeframe', 'signal', 'confidence', 'market_state_snapshot_id', 'market_species', 'trend_score', 'volatility_score', 'liquidity_score', 'momentum_score', 'memory_match_score', 'snapshot', 'hypothesis'];

    protected $casts = [
        'snapshot' => 'array',
    ];

    public function marketStateSnapshot(): BelongsTo
    {
        return $this->belongsTo(MarketStateSnapshot::class, 'market_state_snapshot_id');
    }

    public function memoryMatches(): HasMany
    {
        return $this->hasMany(AgentMemoryMatch::class);
    }
}
