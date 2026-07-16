<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentMemoryMatch extends Model
{
    protected $fillable = ['agent_memory_id', 'signal_market_snapshot_id', 'strategy', 'symbol', 'timeframe', 'similarity_score', 'lesson', 'match_context'];

    protected $casts = [
        'match_context' => 'array',
    ];

    public function memory(): BelongsTo
    {
        return $this->belongsTo(AgentMemory::class, 'agent_memory_id');
    }

    public function signalSnapshot(): BelongsTo
    {
        return $this->belongsTo(SignalMarketSnapshot::class, 'signal_market_snapshot_id');
    }
}
