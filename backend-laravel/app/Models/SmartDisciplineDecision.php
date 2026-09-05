<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SmartDisciplineDecision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'decision_key', 'model_market_performance_id', 'paper_signal_id', 'paper_order_id',
        'symbol', 'timeframe', 'phase', 'state', 'decision', 'classification',
        'setup_quality_score', 'process_adherence_score', 'risk_multiplier',
        'reason_codes', 'gate_results', 'metrics', 'decided_at',
    ];

    protected $casts = [
        'setup_quality_score' => 'float',
        'process_adherence_score' => 'float',
        'risk_multiplier' => 'float',
        'reason_codes' => 'array',
        'gate_results' => 'array',
        'metrics' => 'array',
        'decided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Smart discipline decisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Smart discipline decisions are immutable.'));
    }

    public function marketPerformance(): BelongsTo
    {
        return $this->belongsTo(ModelMarketPerformance::class, 'model_market_performance_id');
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(PaperSignal::class, 'paper_signal_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PaperOrder::class, 'paper_order_id');
    }
}
