<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchExperimentWorkItem extends Model
{
    protected $fillable = ['work_key', 'research_experiment_receipt_id', 'symbol', 'timeframe', 'work_type', 'status', 'priority', 'dependency_key', 'attempts', 'lease_token', 'fence_version', 'lease_expires_at', 'heartbeat_at', 'payload', 'result', 'last_error', 'completed_at'];
    protected $casts = ['payload' => 'array', 'result' => 'array', 'lease_expires_at' => 'datetime', 'heartbeat_at' => 'datetime', 'completed_at' => 'datetime'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ResearchExperimentReceipt::class, 'research_experiment_receipt_id');
    }
}
