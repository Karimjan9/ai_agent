<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuantLawGraphEdge extends Model
{
    protected $fillable = [
        'quant_law_id',
        'source_label',
        'target_label',
        'relation_type',
        'polarity',
        'confidence_score',
        'evidence_count',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function law(): BelongsTo
    {
        return $this->belongsTo(QuantLaw::class, 'quant_law_id');
    }
}
