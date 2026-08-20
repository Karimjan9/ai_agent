<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackOrganismHealthSnapshot extends Model
{
    protected $fillable = [
        'health_key', 'dual_track_run_id', 'symbol', 'timeframe', 'cell_key', 'lane',
        'metrics', 'health_score', 'status', 'evidence', 'promotion_evidence',
    ];

    protected $casts = ['metrics' => 'array', 'health_score' => 'float', 'evidence' => 'array', 'promotion_evidence' => 'boolean'];
}
