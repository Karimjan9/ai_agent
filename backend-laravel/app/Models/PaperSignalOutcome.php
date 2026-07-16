<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PaperSignalOutcome extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['paper_signal_id', 'paper_order_id', 'outcome', 'exit_price', 'profit_percent', 'exit_reason', 'payload'];
    protected $casts = ['payload' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Paper outcomes are immutable.'));
        static::deleting(fn () => throw new LogicException('Paper outcomes are immutable.'));
    }

    public function signal(): BelongsTo { return $this->belongsTo(PaperSignal::class, 'paper_signal_id'); }
    public function order(): BelongsTo { return $this->belongsTo(PaperOrder::class); }
}
