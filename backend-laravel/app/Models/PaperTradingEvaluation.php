<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PaperTradingEvaluation extends Model
{
    protected $fillable = ['model_market_performance_id', 'status', 'sample_count', 'profit_factor', 'max_drawdown', 'net_profit_percent', 'metrics', 'started_at', 'completed_at', 'evidence_status', 'invalidated_at', 'invalidation_reason'];
    protected $casts = ['metrics' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'invalidated_at' => 'datetime'];
    public function marketPerformance(): BelongsTo { return $this->belongsTo(ModelMarketPerformance::class); }
}
