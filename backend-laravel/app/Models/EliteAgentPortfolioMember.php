<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EliteAgentPortfolioMember extends Model
{
    protected $fillable = [
        'elite_agent_portfolio_id', 'model_market_performance_id', 'role',
        'target_regime', 'target_volatility', 'target_direction', 'risk_weight', 'parameter_hash', 'evidence',
    ];

    protected $casts = ['evidence' => 'array'];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(EliteAgentPortfolio::class, 'elite_agent_portfolio_id');
    }

    public function performance(): BelongsTo
    {
        return $this->belongsTo(ModelMarketPerformance::class, 'model_market_performance_id');
    }
}
