<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuantLawEvidence extends Model
{
    protected $table = 'quant_law_evidences';

    protected $fillable = [
        'quant_law_candidate_id',
        'quant_law_id',
        'source_type',
        'source_id',
        'strategy',
        'market_species',
        'evidence_type',
        'effect_direction',
        'effect_size',
        'confidence_score',
        'sample_size',
        'summary',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(QuantLawCandidate::class, 'quant_law_candidate_id');
    }

    public function law(): BelongsTo
    {
        return $this->belongsTo(QuantLaw::class, 'quant_law_id');
    }
}
