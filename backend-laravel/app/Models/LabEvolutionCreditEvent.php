<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabEvolutionCreditEvent extends Model
{
    protected $fillable = [
        'lab_agent_id', 'model_version_id', 'parent_model_version_id', 'symbol', 'timeframe',
        'strategy_family', 'event_type', 'context_key', 'amount', 'status',
        'evidence_fingerprint', 'payload', 'recorded_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'payload' => 'array',
        'recorded_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class, 'lab_agent_id');
    }
}
