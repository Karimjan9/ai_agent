<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackGeneCemetery extends Model
{
    protected $fillable = [
        'cemetery_key', 'symbol', 'timeframe', 'lane', 'cell_key', 'genome_hash', 'parent_genome_hash',
        'failure_regime', 'reason_code', 'death_evidence', 'status', 'resurrection_eligible_at', 'resurrected_at',
    ];

    protected $casts = ['death_evidence' => 'array', 'resurrection_eligible_at' => 'datetime', 'resurrected_at' => 'datetime'];
}
