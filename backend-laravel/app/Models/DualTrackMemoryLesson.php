<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackMemoryLesson extends Model
{
    protected $fillable = [
        'lesson_key', 'layer', 'status', 'symbol', 'timeframe', 'cell_key', 'lane',
        'memory_namespace', 'learning_objective', 'failure_signature', 'failure_class',
        'statement', 'lesson', 'sample_count', 'confidence', 'reward_components',
        'source_run_id', 'source_outcome_id', 'evidence', 'transfer_policy',
        'promotion_policy', 'verified_at', 'expires_at', 'promotion_evidence',
    ];

    protected $casts = [
        'sample_count' => 'integer', 'confidence' => 'float', 'reward_components' => 'array',
        'evidence' => 'array', 'transfer_policy' => 'array', 'promotion_policy' => 'array',
        'verified_at' => 'datetime', 'expires_at' => 'datetime', 'promotion_evidence' => 'boolean',
    ];
}
