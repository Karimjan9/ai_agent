<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackEvaluatorCalibration extends Model
{
    protected $fillable = [
        'calibration_key', 'evaluator', 'cell_key', 'sample_count', 'correct_count',
        'false_positive_count', 'false_negative_count', 'brier_score', 'calibration_error',
        'reputation_score', 'bins', 'evidence', 'status', 'last_observed_at',
    ];

    protected $casts = [
        'sample_count' => 'integer', 'correct_count' => 'integer',
        'false_positive_count' => 'integer', 'false_negative_count' => 'integer',
        'brier_score' => 'float', 'calibration_error' => 'float',
        'reputation_score' => 'float', 'bins' => 'array', 'evidence' => 'array',
        'last_observed_at' => 'datetime',
    ];
}
