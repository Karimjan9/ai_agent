<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DualTrackOutcome extends Model
{
    protected $fillable = [
        'outcome_key', 'dual_track_run_id', 'symbol', 'timeframe', 'task_type', 'cell_key',
        'lane', 'decision', 'outcome_status', 'actual_outcome', 'reward', 'profit_percent',
        'risk_percent', 'regret', 'confidence', 'correct', 'evidence', 'metadata',
        'observed_at', 'settled_at', 'promotion_evidence',
    ];

    protected $casts = [
        'reward' => 'float', 'profit_percent' => 'float', 'risk_percent' => 'float',
        'regret' => 'float', 'confidence' => 'float', 'correct' => 'boolean',
        'evidence' => 'array', 'metadata' => 'array', 'observed_at' => 'datetime',
        'settled_at' => 'datetime', 'promotion_evidence' => 'boolean',
    ];

    public function run(): BelongsTo { return $this->belongsTo(DualTrackRun::class, 'dual_track_run_id'); }
}
