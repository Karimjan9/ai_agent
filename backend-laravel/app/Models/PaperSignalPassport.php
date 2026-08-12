<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PaperSignalPassport extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'paper_signal_id', 'model_market_performance_id', 'pilot_id', 'lane', 'symbol',
        'primary_timeframe', 'regime_timeframe', 'entry_timeframe', 'h1_context_hash',
        'h1_closed_at', 'm15_decision_at', 'm15_strategy', 'execution_hash', 'risk_decision',
        'data_hash', 'code_hash', 'parameter_hash', 'mtf_contract_hash',
        'entry_reason', 'exit_reason', 'mtf_decision', 'h1_regime', 'h1_permission',
        'risk_multiplier', 'gate_decisions', 'counterfactuals', 'payload', 'passport_hash',
    ];

    protected $casts = [
        'h1_closed_at' => 'datetime',
        'm15_decision_at' => 'datetime',
        'risk_multiplier' => 'float',
        'gate_decisions' => 'array',
        'counterfactuals' => 'array',
        'payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Paper signal passports are immutable.'));
        static::deleting(fn () => throw new LogicException('Paper signal passports are immutable.'));
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(PaperSignal::class, 'paper_signal_id');
    }

    public function marketPerformance(): BelongsTo
    {
        return $this->belongsTo(ModelMarketPerformance::class, 'model_market_performance_id');
    }
}
