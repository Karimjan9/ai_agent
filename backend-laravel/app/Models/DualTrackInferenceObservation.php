<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackInferenceObservation extends Model
{
    protected $fillable = [
        'observation_key', 'dual_track_run_id', 'symbol', 'timeframe', 'cell_key', 'lane',
        'process_id', 'snapshot_hash', 'context_hash', 'prompt_hash', 'output_hash',
        'reasoning_budget', 'output', 'context', 'evidence', 'status', 'promotion_evidence',
    ];

    protected $casts = ['output' => 'array', 'context' => 'array', 'evidence' => 'array', 'promotion_evidence' => 'boolean'];
}
