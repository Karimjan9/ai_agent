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
        'parameters',
        'score',
        'total_trades',
        'wins',
        'losses',
        'winrate',
        'net_profit_percent',
        'max_drawdown_percent',
        'profit_factor',
        'average_win_percent',
        'average_loss_percent',
        'risk_reward_ratio',
        'max_consecutive_losses',
        'stability_score',
        'equity_curve',
        'regime_performance',
        'volatility_performance',
        'raw_result',
    ];

    protected $casts = [
        'parameters' => 'array',
        'equity_curve' => 'array',
        'regime_performance' => 'array',
        'volatility_performance' => 'array',
        'raw_result' => 'array',
    ];

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }
}
