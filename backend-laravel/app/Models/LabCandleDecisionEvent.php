<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabCandleDecisionEvent extends Model
{
    protected $fillable = [
        'decision_id', 'run_id', 'lab_generation_id', 'lab_agent_id', 'candle_time', 'candle_index',
        'event_type', 'action', 'accepted', 'rejection_code', 'market_regime', 'volatility_regime',
        'confidence', 'price', 'features', 'state', 'payload_hash', 'payload', 'recorded_at',
    ];

    protected $casts = ['accepted' => 'boolean', 'features' => 'array', 'state' => 'array', 'payload' => 'array', 'recorded_at' => 'datetime'];

    public function agent(): BelongsTo { return $this->belongsTo(LabAgent::class, 'lab_agent_id'); }
}
