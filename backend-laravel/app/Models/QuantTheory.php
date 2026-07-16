<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuantTheory extends Model
{
    protected $fillable = [
        'theory_generation_run_id',
        'theory_key',
        'title',
        'thesis',
        'theory_type',
        'status',
        'confidence_score',
        'explanatory_power_score',
        'predictive_power_score',
        'evidence_count',
        'law_count',
        'causal_edge_count',
        'root_cause_count',
        'scope',
        'metadata',
    ];

    protected $casts = [
        'scope' => 'array',
        'metadata' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(TheoryGenerationRun::class, 'theory_generation_run_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(TheoryComponent::class);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(TheoryPrediction::class);
    }

    public function evolutionEvents(): HasMany
    {
        return $this->hasMany(TheoryEvolutionEvent::class);
    }
}
