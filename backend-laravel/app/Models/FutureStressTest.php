<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FutureStressTest extends Model
{
    protected $fillable = [
        'future_simulation_run_id',
        'stress_key',
        'stress_label',
        'impact_score',
        'survival_rate',
        'confidence_score',
        'risk_level',
        'planning_note',
        'parameters',
    ];

    protected $casts = [
        'parameters' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(FutureSimulationRun::class, 'future_simulation_run_id');
    }
}
