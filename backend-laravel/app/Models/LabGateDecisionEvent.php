<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabGateDecisionEvent extends Model
{
    protected $fillable = [
        'current_decision_id', 'model_market_performance_id', 'lab_generation_id', 'lab_agent_id',
        'run_id', 'stage', 'decision', 'revision', 'attribution_status', 'reason_codes',
        'metrics', 'payload', 'recorded_at',
    ];

    protected $casts = ['reason_codes' => 'array', 'metrics' => 'array', 'payload' => 'array', 'recorded_at' => 'datetime'];

    public function decision(): BelongsTo { return $this->belongsTo(CandidateGateDecision::class, 'current_decision_id'); }
    public function agent(): BelongsTo { return $this->belongsTo(LabAgent::class, 'lab_agent_id'); }
    public function generation(): BelongsTo { return $this->belongsTo(LabGeneration::class, 'lab_generation_id'); }
}
