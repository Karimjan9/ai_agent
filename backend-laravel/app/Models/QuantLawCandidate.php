<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class QuantLawCandidate extends Model
{
    protected $fillable = [
        'quant_law_discovery_run_id',
        'candidate_key',
        'title',
        'observation',
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
        'scope',
        'metadata',
        'last_seen_at',
    ];

    protected $casts = [
        'scope' => 'array',
        'metadata' => 'array',
        'last_seen_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(QuantLawDiscoveryRun::class, 'quant_law_discovery_run_id');
    }

    public function law(): HasOne
    {
        return $this->hasOne(QuantLaw::class);
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(QuantLawEvidence::class);
    }
}
