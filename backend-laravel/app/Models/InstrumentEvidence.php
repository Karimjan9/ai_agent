<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstrumentEvidence extends Model
{
    protected $fillable = ['evidence_key', 'trading_instrument_id', 'symbol', 'timeframe', 'state_key', 'outcome_state', 'source_type', 'source_key', 'metrics', 'control_metrics', 'metadata', 'observed_at'];

    protected $casts = ['metrics' => 'array', 'control_metrics' => 'array', 'metadata' => 'array', 'observed_at' => 'datetime'];

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(TradingInstrument::class, 'trading_instrument_id');
    }
}
