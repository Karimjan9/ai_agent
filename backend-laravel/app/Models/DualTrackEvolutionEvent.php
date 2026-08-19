<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DualTrackEvolutionEvent extends Model
{
    protected $fillable = [
        'event_key', 'symbol', 'timeframe', 'cell_key', 'island_key', 'lane', 'event_type',
        'capability_key', 'model_version_id', 'source_parent_model_version_ids',
        'incremental_value', 'status', 'evidence', 'metadata', 'promotion_evidence',
    ];

    protected $casts = [
        'source_parent_model_version_ids' => 'array', 'incremental_value' => 'float',
        'evidence' => 'array', 'metadata' => 'array', 'promotion_evidence' => 'boolean',
    ];

    public function modelVersion(): BelongsTo { return $this->belongsTo(ModelVersion::class); }
}
