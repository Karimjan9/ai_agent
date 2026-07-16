<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenomeGene extends Model
{
    protected $fillable = [
        'strategy_genome_id',
        'gene_key',
        'gene_value',
        'value_type',
        'observed_fitness',
    ];

    protected $casts = [
        'gene_value' => 'array',
    ];

    public function strategyGenome(): BelongsTo
    {
        return $this->belongsTo(StrategyGenome::class);
    }
}
