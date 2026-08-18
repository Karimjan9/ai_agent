<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabTemporalAblationRun extends Model
{
    protected $fillable = [
        'ai_laboratory_id', 'lab_generation_id', 'model_version_id',
        'symbol', 'timeframe', 'protocol', 'run_key', 'hypothesis_hash',
        'data_identity_hash', 'execution_hash', 'status', 'decision',
        'window_count', 'variant_count', 'window_manifest', 'results',
        'reason_codes', 'mutation_credit_allowed', 'promotion_evidence',
        'completed_at',
    ];

    protected $casts = [
        'window_manifest' => 'array',
        'results' => 'array',
        'reason_codes' => 'array',
        'mutation_credit_allowed' => 'boolean',
        'promotion_evidence' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(AiLaboratory::class, 'ai_laboratory_id');
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(LabGeneration::class, 'lab_generation_id');
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class, 'model_version_id');
    }
}
