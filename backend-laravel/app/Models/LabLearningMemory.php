<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabLearningMemory extends Model
{
    protected $fillable = [
        'memory_key', 'symbol', 'timeframe', 'family', 'specialist_role', 'target',
        'state_signature', 'gene', 'direction', 'memory_type', 'status', 'trial_count',
        'success_count', 'failure_count', 'score', 'confidence', 'blocked_until',
        'metadata', 'last_observed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'blocked_until' => 'datetime',
        'last_observed_at' => 'datetime',
        'score' => 'float',
        'confidence' => 'float',
    ];
}
