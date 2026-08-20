<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackDiversityMetric extends Model
{
    protected $fillable = [
        'metric_key', 'dual_track_run_id', 'symbol', 'timeframe', 'cell_key',
        'behavioral_distance', 'confidence_distance', 'decision_agreement_rate',
        'useful_dissent_rate', 'memory_overlap_rate', 'council_redundancy_rate',
        'sample_count', 'status', 'evidence', 'promotion_evidence',
    ];

    protected $casts = [
        'behavioral_distance' => 'float', 'confidence_distance' => 'float',
        'decision_agreement_rate' => 'float', 'useful_dissent_rate' => 'float',
        'memory_overlap_rate' => 'float', 'council_redundancy_rate' => 'float',
        'sample_count' => 'integer', 'evidence' => 'array', 'promotion_evidence' => 'boolean',
    ];
}
