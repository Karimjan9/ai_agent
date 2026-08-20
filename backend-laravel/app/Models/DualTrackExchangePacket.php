<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackExchangePacket extends Model
{
    protected $fillable = [
        'packet_key', 'dual_track_run_id', 'symbol', 'timeframe', 'cell_key',
        'source_lane', 'target_lane', 'packet_type', 'protocol_version', 'payload',
        'integrity_hash', 'status', 'outcome_status', 'evidence', 'promotion_evidence',
        'delivery_status', 'revalidation_hash', 'revalidated_at', 'expires_at',
    ];

    protected $casts = ['payload' => 'array', 'evidence' => 'array', 'promotion_evidence' => 'boolean', 'revalidated_at' => 'datetime', 'expires_at' => 'datetime'];
}
