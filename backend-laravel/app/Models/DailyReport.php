<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyReport extends Model
{
    protected $fillable = [
        'report_date',
        'symbol',
        'timeframe',
        'strategy',
        'total_backtests',
        'total_trades',
        'total_wins',
        'total_losses',
        'average_winrate',
        'average_profit',
        'top_mistakes',
        'ai_conclusion',
        'next_training_plan',
        'backtest_run_id',
        'metrics',
        'conclusion',
        'recommendations',
        'source',
        'evidence_run_ids',
    ];

    protected $casts = [
        'report_date' => 'date',
        'top_mistakes' => 'array',
        'metrics' => 'array',
        'recommendations' => 'array',
        'evidence_run_ids' => 'array',
    ];
}
