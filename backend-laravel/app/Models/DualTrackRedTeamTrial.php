<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackRedTeamTrial extends Model
{
    protected $fillable = [
        'trial_key', 'dual_track_run_id', 'symbol', 'timeframe', 'cell_key', 'target_lane',
        'adversary_type', 'status', 'damage_score', 'challenge', 'result', 'promotion_evidence',
    ];

    protected $casts = ['damage_score' => 'float', 'challenge' => 'array', 'result' => 'array', 'promotion_evidence' => 'boolean'];
}
