<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabParentContextScore extends Model
{
    protected $fillable = [
        'symbol', 'timeframe', 'strategy_family', 'parent_model_version_id',
        'skill_key', 'context_key', 'regime', 'session_utc_hour', 'volume_state',
        'cost_stress', 'trust_score', 'incremental_value', 'success_count',
        'failure_count', 'uncertainty_count', 'last_evidence_at', 'status', 'metadata',
    ];

    protected $casts = [
        'trust_score' => 'float',
        'incremental_value' => 'float',
        'metadata' => 'array',
        'last_evidence_at' => 'datetime',
    ];

    public function parentModel(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class, 'parent_model_version_id');
    }
}
