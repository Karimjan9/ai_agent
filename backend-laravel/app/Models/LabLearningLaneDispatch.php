<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabLearningLaneDispatch extends Model
{
    protected $fillable = [
        'dispatch_key', 'pair_id', 'lab_generation_id', 'lab_agent_id', 'symbol',
        'timeframe', 'strategy_family', 'target', 'specialist_role', 'status',
        'stage', 'micro_status', 'micro_attempts', 'micro_completed_at', 'micro_metadata',
        'queue_batch_id', 'selection_score', 'metadata', 'selected_at', 'queued_at',
        'completed_at',
    ];

    protected $casts = [
        'selection_score' => 'float', 'metadata' => 'array', 'micro_metadata' => 'array',
        'micro_attempts' => 'integer', 'micro_completed_at' => 'datetime',
        'selected_at' => 'datetime', 'queued_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function pair(): BelongsTo
    {
        return $this->belongsTo(LabLearningLanePair::class, 'pair_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class, 'lab_agent_id');
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(LabGeneration::class, 'lab_generation_id');
    }
}
