<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackMemberCredit extends Model
{
    protected $fillable = [
        'credit_key', 'dual_track_run_id', 'dual_track_outcome_id', 'symbol', 'timeframe',
        'cell_key', 'member_key', 'role', 'full_reward', 'ablated_reward', 'marginal_credit',
        'credit_type', 'status', 'evidence', 'promotion_evidence',
    ];

    protected $casts = ['full_reward' => 'float', 'ablated_reward' => 'float', 'marginal_credit' => 'float', 'evidence' => 'array', 'promotion_evidence' => 'boolean'];
}
