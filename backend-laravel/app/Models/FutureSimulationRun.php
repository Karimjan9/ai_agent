<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FutureSimulationRun extends Model
{
    protected $fillable = [
        'market_state_snapshot_id',
        'market_genome_id',
        'market_species_id',
        'symbol',
        'timeframe',
        'scenario_count',
        'max_horizon_candles',
        'random_seed',
        'status',
        'current_confidence',
        'future_confidence',
        'planning_bias',
        'current_market_vector',
        'knowledge_prior_summary',
        'summary',
    ];

    protected $casts = [
        'current_market_vector' => 'array',
        'knowledge_prior_summary' => 'array',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(MarketStateSnapshot::class, 'market_state_snapshot_id');
    }

    public function marketGenome(): BelongsTo
    {
        return $this->belongsTo(MarketGenome::class);
    }

    public function marketSpecies(): BelongsTo
    {
        return $this->belongsTo(MarketSpecies::class);
    }

    public function scenarios(): HasMany
    {
        return $this->hasMany(FutureScenario::class);
    }

    public function probabilityNodes(): HasMany
    {
        return $this->hasMany(FutureProbabilityNode::class);
    }

    public function timelineForecasts(): HasMany
    {
        return $this->hasMany(FutureTimelineForecast::class);
    }

    public function survivalForecasts(): HasMany
    {
        return $this->hasMany(StrategySurvivalForecast::class);
    }

    public function stressTests(): HasMany
    {
        return $this->hasMany(FutureStressTest::class);
    }
}
