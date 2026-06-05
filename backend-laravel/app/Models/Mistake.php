<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mistake extends Model
{
    protected $fillable = [
        'backtest_run_id',
        'trade_id',
        'mistake_type',
        'reason',
        'description',
        'suggestion',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function backtestRun(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class);
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
