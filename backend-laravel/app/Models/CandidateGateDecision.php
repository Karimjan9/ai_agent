<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateGateDecision extends Model
{
    protected $fillable = ['model_market_performance_id', 'lab_agent_id', 'stage', 'decision', 'reason_codes', 'metrics', 'evaluated_at'];
    protected $casts = ['reason_codes' => 'array', 'metrics' => 'array', 'evaluated_at' => 'datetime'];

    public function performance(): BelongsTo { return $this->belongsTo(ModelMarketPerformance::class, 'model_market_performance_id'); }
    public function labAgent(): BelongsTo { return $this->belongsTo(LabAgent::class); }
}
