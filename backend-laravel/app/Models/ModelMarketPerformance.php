<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelMarketPerformance extends Model
{
    protected $table = 'model_market_performance';

    protected $fillable = [
        'model_version_id', 'symbol', 'timeframe', 'strategy_family', 'status', 'paper_status', 'holdout_status', 'champion_slot',
        'fitness', 'forward_score', 'holdout_score', 'sample_count', 'rolling_windows_count',
        'paper_sample_count', 'paper_profit_factor', 'paper_max_drawdown',
        'rolling_forward_wins', 'consecutive_no_improvement', 'metrics',
        'promoted_at', 'archived_at',
        'evidence_status', 'invalidated_at', 'invalidation_reason',
    ];

    protected $casts = [
        'metrics' => 'array',
        'promoted_at' => 'datetime',
        'archived_at' => 'datetime',
        'invalidated_at' => 'datetime',
    ];

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }

    public function paperEvaluations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaperTradingEvaluation::class);
    }
}
