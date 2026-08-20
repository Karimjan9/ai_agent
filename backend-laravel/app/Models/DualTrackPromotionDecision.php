<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackPromotionDecision extends Model
{
    protected $fillable = ['decision_key', 'symbol', 'timeframe', 'cell_key', 'requested_lane', 'status', 'allowed', 'reasons', 'evidence', 'evidence_hash', 'expires_at', 'promotion_evidence'];
    protected $casts = ['allowed' => 'boolean', 'reasons' => 'array', 'evidence' => 'array', 'expires_at' => 'datetime', 'promotion_evidence' => 'boolean'];
}
