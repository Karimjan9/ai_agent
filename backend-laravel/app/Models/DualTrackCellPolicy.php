<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackCellPolicy extends Model
{
    protected $fillable = [
        'policy_key', 'symbol', 'timeframe', 'cell_key', 'mode', 'recommended_lane',
        'active_lane', 'status', 'sample_count', 'minimum_samples', 'confidence_margin',
        'disagreement_value', 'lane_statistics', 'risk_bounds', 'policy', 'policy_hash',
        'last_outcome_at', 'certified_at', 'promotion_evidence',
    ];

    protected $casts = [
        'sample_count' => 'integer', 'minimum_samples' => 'integer',
        'confidence_margin' => 'float', 'disagreement_value' => 'float',
        'lane_statistics' => 'array', 'risk_bounds' => 'array', 'policy' => 'array',
        'last_outcome_at' => 'datetime', 'certified_at' => 'datetime',
        'promotion_evidence' => 'boolean',
    ];
}
