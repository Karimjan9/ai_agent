<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvolutionGeneration extends Model
{
    protected $fillable = [
        'family',
        'generation',
        'genomes_count',
        'best_fitness',
        'average_fitness',
        'best_genome_id',
    ];

    public function bestGenome(): BelongsTo
    {
        return $this->belongsTo(StrategyGenome::class, 'best_genome_id');
    }
}
