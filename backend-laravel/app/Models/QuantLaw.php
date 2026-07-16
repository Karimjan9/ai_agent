<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuantLaw extends Model
{
    protected $fillable = [
        'quant_law_candidate_id',
        'law_key',
        'title',
        'statement',
        'law_type',
        'status',
        'confidence_score',
        'universality_score',
        'effect_size',
        'evidence_count',
        'strategy_count',
        'species_count',
        'session_count',
        'trade_count',
        'first_seen_at',
        'last_validated_at',
        'scope',
        'metadata',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_validated_at' => 'datetime',
        'scope' => 'array',
        'metadata' => 'array',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(QuantLawCandidate::class, 'quant_law_candidate_id');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(QuantLawEvidence::class);
    }

    public function graphEdges(): HasMany
    {
        return $this->hasMany(QuantLawGraphEdge::class);
    }

    public function evolutionEvents(): HasMany
    {
        return $this->hasMany(QuantLawEvolutionEvent::class);
    }
}
