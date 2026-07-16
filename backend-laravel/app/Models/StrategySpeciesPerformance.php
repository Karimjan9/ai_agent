<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategySpeciesPerformance extends Model
{
    protected $table = 'strategy_species_performance';

    protected $fillable = [
        'market_species_id',
        'strategy_score_id',
        'training_session_id',
        'strategy',
        'species_code',
        'species_name',
        'trades',
        'winrate',
        'profit_percent',
        'confidence_score',
        'evidence',
    ];

    protected $casts = [
        'evidence' => 'array',
    ];

    public function marketSpecies(): BelongsTo
    {
        return $this->belongsTo(MarketSpecies::class);
    }

    public function strategyScore(): BelongsTo
    {
        return $this->belongsTo(StrategyScore::class);
    }

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }
}
