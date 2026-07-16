<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FutureScenario extends Model
{
    protected $fillable = [
        'future_simulation_run_id',
        'scenario_key',
        'scenario_label',
        'simulated_count',
        'probability',
        'expected_return',
        'risk_score',
        'confidence_score',
        'state_path',
        'drivers',
    ];

    protected $casts = [
        'state_path' => 'array',
        'drivers' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(FutureSimulationRun::class, 'future_simulation_run_id');
    }
}
