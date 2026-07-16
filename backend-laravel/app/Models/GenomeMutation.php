<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenomeMutation extends Model
{
    protected $fillable = [
        'parent_genome_id',
        'child_genome_id',
        'evolution_proposal_id',
        'mutation_type',
        'mutation_diff',
        'reason',
    ];

    protected $casts = [
        'mutation_diff' => 'array',
    ];

    public function parentGenome(): BelongsTo
    {
        return $this->belongsTo(StrategyGenome::class, 'parent_genome_id');
    }

    public function childGenome(): BelongsTo
    {
        return $this->belongsTo(StrategyGenome::class, 'child_genome_id');
    }

    public function evolutionProposal(): BelongsTo
    {
        return $this->belongsTo(EvolutionProposal::class);
    }
}
