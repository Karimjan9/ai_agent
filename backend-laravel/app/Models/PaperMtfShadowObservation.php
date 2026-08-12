<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaperMtfShadowObservation extends Model
{
    protected $fillable = [
        'model_market_performance_id', 'paper_signal_id', 'pilot_id', 'lane', 'scenario_key',
        'symbol', 'timeframe', 'candle_time', 'decision', 'price', 'stop_loss', 'take_profit',
        'confidence', 'h1_context_hash', 'h1_closed_at', 'idempotency_key', 'payload_hash',
        'payload', 'outcome', 'profit_percent', 'exit_reason', 'outcome_payload',
        'promotion_evidence', 'observed_at',
    ];

    protected $casts = [
        'candle_time' => 'datetime',
        'h1_closed_at' => 'datetime',
        'confidence' => 'float',
        'price' => 'float',
        'stop_loss' => 'float',
        'take_profit' => 'float',
        'profit_percent' => 'float',
        'payload' => 'array',
        'outcome_payload' => 'array',
        'promotion_evidence' => 'boolean',
        'observed_at' => 'datetime',
    ];

    public function marketPerformance(): BelongsTo
    {
        return $this->belongsTo(ModelMarketPerformance::class, 'model_market_performance_id');
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(PaperSignal::class, 'paper_signal_id');
    }
}
