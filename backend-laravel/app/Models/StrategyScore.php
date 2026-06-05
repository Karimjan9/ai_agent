<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyScore extends Model
{
    protected $fillable = [
        'training_session_id',
        'symbol',
        'timeframe',
        'strategy',
        'score',
        'total_trades',
        'wins',
        'losses',
        'winrate',
        'net_profit_percent',
        'max_drawdown_percent',
        'profit_factor',
        'raw_result',
    ];

    protected $casts = [
        'raw_result' => 'array',
    ];

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }
}
