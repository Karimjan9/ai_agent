<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackMemoryLesson extends Model
{
    protected $fillable = [
        'lesson_key', 'layer', 'status', 'symbol', 'timeframe', 'cell_key', 'lane',
        'failure_signature', 'statement', 'lesson', 'sample_count', 'confidence',
        'source_run_id', 'source_outcome_id', 'evidence', 'verified_at', 'expires_at',
        'promotion_evidence',
    ];

    protected $casts = [
        'sample_count' => 'integer', 'confidence' => 'float', 'evidence' => 'array',
        'verified_at' => 'datetime', 'expires_at' => 'datetime', 'promotion_evidence' => 'boolean',
    ];
}
