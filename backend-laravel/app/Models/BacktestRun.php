<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BacktestRun extends Model
{
    protected $fillable = [
        'symbol',
        'symbol_id',
        'timeframe',
        'strategy',
        'strategy_id',
        'date_from',
        'date_to',
        'from_date',
        'to_date',
        'status',
        'request_payload',
        'metrics',
        'initial_balance',
        'final_balance',
        'total_trades',
        'wins',
        'losses',
        'winrate',
        'net_profit_percent',
        'max_drawdown_percent',
        'profit_factor',
        'conclusion',
        'raw_result',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'from_date' => 'date',
        'to_date' => 'date',
        'request_payload' => 'array',
        'metrics' => 'array',
        'raw_result' => 'array',
    ];

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function mistakes(): HasMany
    {
        return $this->hasMany(Mistake::class);
    }
}
