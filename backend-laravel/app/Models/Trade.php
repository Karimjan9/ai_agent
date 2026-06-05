<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Trade extends Model
{
    protected $fillable = [
        'backtest_run_id',
        'symbol',
        'timeframe',
        'strategy',
        'direction',
        'entry_time',
        'exit_time',
        'entry_price',
        'exit_price',
        'stop_loss',
        'take_profit',
        'result',
        'profit_percent',
        'balance_after_trade',
        'mistake_type',
        'reason',
    ];

    protected $casts = [
        'entry_time' => 'datetime',
        'exit_time' => 'datetime',
    ];

    public function backtestRun(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class);
    }

    public function mistake(): HasOne
    {
        return $this->hasOne(Mistake::class);
    }
}
