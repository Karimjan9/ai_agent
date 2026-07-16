<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RealityValidationEvent extends Model
{
    protected $fillable = ['reality_score_id', 'event_type', 'previous_status', 'new_status', 'previous_reality_score', 'new_reality_score', 'evidence_summary', 'metadata'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function score(): BelongsTo
    {
        return $this->belongsTo(RealityScore::class, 'reality_score_id');
    }
}
