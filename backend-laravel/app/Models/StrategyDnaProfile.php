<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyDnaProfile extends Model
{
    protected $fillable = [
        'strategy_score_id',
        'aggression_score',
        'trend_dependency',
        'range_dependency',
        'volatility_sensitivity',
        'adaptability_score',
        'recovery_score',
        'survival_score',
        'dna_summary',
    ];

    public function strategyScore(): BelongsTo
    {
        return $this->belongsTo(StrategyScore::class);
    }
}
