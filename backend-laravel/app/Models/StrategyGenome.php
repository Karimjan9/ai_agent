<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StrategyGenome extends Model
{
    protected $fillable = [
        'model_version_id',
        'strategy_score_id',
        'training_session_id',
        'strategy',
        'family',
        'version',
        'generation',
        'genome_hash',
        'genes',
        'phenotype',
        'fitness_score',
        'evolution_efficiency',
        'status',
        'death_reason',
        'born_at',
        'archived_at',
    ];

    protected $casts = [
        'genes' => 'array',
        'phenotype' => 'array',
        'born_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }

    public function strategyScore(): BelongsTo
    {
        return $this->belongsTo(StrategyScore::class);
    }

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    public function genes(): HasMany
    {
        return $this->hasMany(GenomeGene::class);
    }

    public function parentLineages(): HasMany
    {
        return $this->hasMany(GenomeLineage::class, 'child_genome_id');
    }

    public function childLineages(): HasMany
    {
        return $this->hasMany(GenomeLineage::class, 'parent_genome_id');
    }

    public function fitnessEvaluations(): HasMany
    {
        return $this->hasMany(FitnessEvaluation::class);
    }
}
