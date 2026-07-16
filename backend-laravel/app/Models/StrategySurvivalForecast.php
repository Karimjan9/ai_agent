<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategySurvivalForecast extends Model
{
    protected $fillable = [
        'future_simulation_run_id',
        'strategy_score_id',
        'strategy',
        'current_confidence',
        'future_confidence',
        'survival_probability',
        'future_robustness',
        'recommended_action',
        'scenario_breakdown',
        'planning_adjustments',
    ];

    protected $casts = [
        'scenario_breakdown' => 'array',
        'planning_adjustments' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(FutureSimulationRun::class, 'future_simulation_run_id');
    }

    public function strategyScore(): BelongsTo
    {
        return $this->belongsTo(StrategyScore::class);
    }
}
