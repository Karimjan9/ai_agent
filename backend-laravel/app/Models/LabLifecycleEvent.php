<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabLifecycleEvent extends Model
{
    protected $fillable = [
        'event_id', 'lab_generation_id', 'lab_agent_id', 'run_id', 'phase', 'event_type',
        'from_status', 'to_status', 'attempt', 'source', 'reason_code', 'error_class',
        'error_message', 'payload', 'occurred_at',
    ];

    protected $casts = ['payload' => 'array', 'occurred_at' => 'datetime'];

    public function generation(): BelongsTo { return $this->belongsTo(LabGeneration::class, 'lab_generation_id'); }
    public function agent(): BelongsTo { return $this->belongsTo(LabAgent::class, 'lab_agent_id'); }
}
