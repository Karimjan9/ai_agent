<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentBelief extends Model
{
    protected $fillable = [
        'strategy',
        'belief_key',
        'belief_label',
        'score',
        'sample_size',
        'confirmed_count',
        'failed_count',
        'confidence_interval_low',
        'confidence_interval_high',
        'regime',
        'last_evidence_at',
        'evidence_summary',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_evidence_at' => 'datetime',
    ];
}
