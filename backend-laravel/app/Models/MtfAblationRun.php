<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class MtfAblationRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'model_market_performance_id', 'pilot_id', 'symbol', 'regime_timeframe',
        'entry_timeframe', 'run_key', 'data_hash', 'execution_hash', 'status',
        'variants', 'snapshot_reference', 'promotion_evidence', 'completed_at',
    ];

    protected $casts = [
        'variants' => 'array',
        'snapshot_reference' => 'array',
        'promotion_evidence' => 'boolean',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('MTF ablation runs are immutable.'));
        static::deleting(fn () => throw new LogicException('MTF ablation runs are immutable.'));
    }
}
