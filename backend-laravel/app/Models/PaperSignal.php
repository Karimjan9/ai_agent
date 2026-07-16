<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class PaperSignal extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'model_market_performance_id', 'model_version_id', 'signal_market_snapshot_id',
        'symbol', 'timeframe', 'candle_time', 'decision', 'price', 'stop_loss',
        'take_profit', 'confidence', 'market_regime', 'volatility_regime', 'payload', 'payload_hash',
    ];

    protected $casts = ['candle_time' => 'datetime', 'payload' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Paper signals are immutable.'));
        static::deleting(fn () => throw new LogicException('Paper signals are immutable.'));
    }

    public function marketPerformance(): BelongsTo { return $this->belongsTo(ModelMarketPerformance::class, 'model_market_performance_id'); }
    public function modelVersion(): BelongsTo { return $this->belongsTo(ModelVersion::class); }
    public function marketSnapshot(): BelongsTo { return $this->belongsTo(SignalMarketSnapshot::class, 'signal_market_snapshot_id'); }
    public function order(): HasOne { return $this->hasOne(PaperOrder::class); }
    public function outcome(): HasOne { return $this->hasOne(PaperSignalOutcome::class); }
}
