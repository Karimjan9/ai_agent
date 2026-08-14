<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabFailureRepairAnchor extends Model
{
    protected $fillable = [
        'anchor_key',
        'source_lab_agent_id',
        'source_model_version_id',
        'source_lab_generation_id',
        'symbol',
        'timeframe',
        'strategy_family',
        'failure_class',
        'failure_reason',
        'failure_target',
        'status',
        'parameter_snapshot',
        'parameter_fingerprint',
        'parameter_diff',
        'evidence',
    ];

    protected $casts = [
        'parameter_snapshot' => 'array',
        'parameter_diff' => 'array',
        'evidence' => 'array',
    ];

    public function sourceAgent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class, 'source_lab_agent_id');
    }

    public function sourceModelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class, 'source_model_version_id');
    }

    public function sourceGeneration(): BelongsTo
    {
        return $this->belongsTo(LabGeneration::class, 'source_lab_generation_id');
    }
}
