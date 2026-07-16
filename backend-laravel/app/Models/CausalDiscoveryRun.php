<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CausalDiscoveryRun extends Model
{
    protected $fillable = ['status', 'started_at', 'finished_at', 'edges_created', 'effects_estimated', 'interventions_created', 'experiments_created', 'summary', 'metrics'];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metrics' => 'array',
    ];
}
