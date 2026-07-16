<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeCemeteryEntry extends Model
{
    protected $fillable = ['reality_score_id', 'source_type', 'source_id', 'title', 'failure_reason', 'original_confidence', 'final_reality_score', 'status', 'failed_at', 'evidence'];

    protected $casts = [
        'failed_at' => 'datetime',
        'evidence' => 'array',
    ];

    public function score(): BelongsTo
    {
        return $this->belongsTo(RealityScore::class, 'reality_score_id');
    }
}
