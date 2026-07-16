<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenomeLineage extends Model
{
    protected $fillable = [
        'parent_genome_id',
        'child_genome_id',
        'lineage_type',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function parentGenome(): BelongsTo
    {
        return $this->belongsTo(StrategyGenome::class, 'parent_genome_id');
    }

    public function childGenome(): BelongsTo
    {
        return $this->belongsTo(StrategyGenome::class, 'child_genome_id');
    }
}
