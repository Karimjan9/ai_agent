<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FutureDiscovery extends Model
{
    protected $fillable = [
        'future_simulation_run_id',
        'title',
        'discovery',
        'discovery_type',
        'confidence_score',
        'evidence_count',
        'status',
        'scope',
        'metadata',
    ];

    protected $casts = [
        'scope' => 'array',
        'metadata' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(FutureSimulationRun::class, 'future_simulation_run_id');
    }
}
