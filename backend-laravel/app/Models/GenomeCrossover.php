<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenomeCrossover extends Model
{
    protected $fillable = [
        'parent_a_genome_id',
        'parent_b_genome_id',
        'child_genome_id',
        'child_strategy',
        'combined_genes',
        'rationale',
        'status',
    ];

    protected $casts = [
        'combined_genes' => 'array',
    ];

    public function parentA(): BelongsTo
    {
        return $this->belongsTo(StrategyGenome::class, 'parent_a_genome_id');
    }

    public function parentB(): BelongsTo
    {
        return $this->belongsTo(StrategyGenome::class, 'parent_b_genome_id');
    }

    public function childGenome(): BelongsTo
    {
        return $this->belongsTo(StrategyGenome::class, 'child_genome_id');
    }
}
