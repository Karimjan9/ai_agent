<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class MtfStrategyResearchRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'model_market_performance_id', 'pilot_id', 'symbol', 'regime_timeframe',
        'entry_timeframe', 'hypothesis_key', 'strategy_identity', 'strategy_family',
        'run_key', 'data_hash', 'parameter_hash', 'execution_hash', 'status',
        'failure_class', 'research_contract', 'parameters', 'result',
        'promotion_evidence', 'completed_at',
    ];

    protected $casts = [
        'research_contract' => 'array',
        'parameters' => 'array',
        'result' => 'array',
        'promotion_evidence' => 'boolean',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('MTF strategy research runs are immutable.'));
        static::deleting(fn () => throw new LogicException('MTF strategy research runs are immutable.'));
    }
}
