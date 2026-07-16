<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FutureTimelineForecast extends Model
{
    protected $fillable = [
        'future_simulation_run_id',
        'horizon_candles',
        'bull_probability',
        'range_probability',
        'panic_probability',
        'reversal_probability',
        'confidence_score',
        'drivers',
    ];

    protected $casts = [
        'drivers' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(FutureSimulationRun::class, 'future_simulation_run_id');
    }
}
