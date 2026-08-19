<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DualTrackRun extends Model
{
    protected $fillable = [
        'run_key', 'protocol', 'symbol', 'timeframe', 'task_type', 'cell_key',
        'market_regime', 'volatility_regime', 'mode', 'status', 'selected_lane',
        'selected_decision', 'champion_decision', 'council_decision', 'disagreement_code',
        'snapshot_hash', 'input_hash', 'output_hash', 'duration_ms', 'scores',
        'champion_output', 'council_output', 'evidence', 'routing', 'metadata',
        'started_at', 'finished_at', 'promotion_evidence',
    ];

    protected $casts = [
        'scores' => 'array',
        'champion_output' => 'array',
        'council_output' => 'array',
        'evidence' => 'array',
        'routing' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'promotion_evidence' => 'boolean',
    ];

    public function outcomes(): HasMany
    {
        return $this->hasMany(DualTrackOutcome::class, 'dual_track_run_id');
    }
}
