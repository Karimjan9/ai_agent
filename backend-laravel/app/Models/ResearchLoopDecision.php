<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ResearchLoopDecision extends Model
{
    protected $fillable = [
        'decision_key', 'symbol', 'timeframe', 'action', 'status', 'priority',
        'evidence_hash', 'command', 'queue', 'arguments', 'reason_codes',
        'evidence_snapshot', 'contract', 'dispatched_at', 'completed_at',
    ];

    protected $casts = [
        'arguments' => 'array',
        'reason_codes' => 'array',
        'evidence_snapshot' => 'array',
        'contract' => 'array',
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (ResearchLoopDecision $decision): void {
            $mutable = ['status', 'dispatched_at', 'completed_at', 'updated_at'];
            if (array_diff(array_keys($decision->getDirty()), $mutable) !== []) {
                throw new LogicException('Research loop decision core is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Research loop decisions are immutable.'));
    }
}
