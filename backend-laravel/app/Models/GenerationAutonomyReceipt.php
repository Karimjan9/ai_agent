<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class GenerationAutonomyReceipt extends Model
{
    protected $fillable = [
        'receipt_key', 'lab_generation_id', 'arbiter_decision_id',
        'successor_generation_id', 'state', 'receipt_hash', 'payload', 'observed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'observed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Generation autonomy receipts are immutable.'));
        static::deleting(fn () => throw new LogicException('Generation autonomy receipts are append-only.'));
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(LabGeneration::class, 'lab_generation_id');
    }

    public function successor(): BelongsTo
    {
        return $this->belongsTo(LabGeneration::class, 'successor_generation_id');
    }

    public function arbiterDecision(): BelongsTo
    {
        return $this->belongsTo(ResearchLoopDecision::class, 'arbiter_decision_id');
    }
}
