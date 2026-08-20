<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackLaneCredit extends Model
{
    protected $fillable = [
        'credit_key', 'dual_track_run_id', 'dual_track_outcome_id', 'symbol', 'timeframe',
        'cell_key', 'lane', 'agent_key', 'credit_type', 'reward', 'counterfactual_delta',
        'components', 'evidence', 'promotion_evidence',
    ];

    protected $casts = [
        'reward' => 'float', 'counterfactual_delta' => 'float', 'components' => 'array',
        'evidence' => 'array', 'promotion_evidence' => 'boolean',
    ];
}
