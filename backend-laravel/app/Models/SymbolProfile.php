<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SymbolProfile extends Model
{
    protected $fillable = [
        'market_symbol_id',
        'symbol',
        'timeframe',
        'category',
        'best_session',
        'worst_session',
        'best_strategy',
        'worst_strategy',
        'current_regime',
        'news_sensitivity_score',
        'volatility_profile_score',
        'trend_cleanliness_score',
        'winrate',
        'profit_factor',
        'signals_count',
        'paper_trades_count',
        'observations_count',
        'confidence_score',
        'summary',
        'session_stats',
        'strategy_stats',
        'metadata',
    ];

    protected $casts = [
        'session_stats' => 'array',
        'strategy_stats' => 'array',
        'metadata' => 'array',
    ];

    public function marketSymbol(): BelongsTo
    {
        return $this->belongsTo(MarketSymbol::class);
    }
}
