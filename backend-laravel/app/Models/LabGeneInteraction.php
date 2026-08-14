<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabGeneInteraction extends Model
{
    protected $fillable = [
        'interaction_key', 'symbol', 'timeframe', 'family', 'specialist_role', 'target',
        'genes', 'mentor_ids', 'status', 'baseline_metrics', 'observed_metrics',
        'target_delta', 'non_target_regression', 'evidence', 'promotion_evidence',
    ];

    protected $casts = [
        'genes' => 'array',
        'mentor_ids' => 'array',
        'baseline_metrics' => 'array',
        'observed_metrics' => 'array',
        'evidence' => 'array',
        'target_delta' => 'float',
        'non_target_regression' => 'boolean',
        'promotion_evidence' => 'boolean',
    ];
}
