<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackGenomeArchive extends Model
{
    protected $fillable = [
        'archive_key', 'symbol', 'timeframe', 'lane', 'cell_key', 'behavior_cell', 'genome_hash',
        'model_version_id', 'genes', 'phenotype', 'fitness_score', 'novelty_score', 'evidence_count',
        'status', 'death_reason', 'evidence', 'resurrected_at',
    ];

    protected $casts = ['genes' => 'array', 'phenotype' => 'array', 'fitness_score' => 'float', 'novelty_score' => 'float', 'evidence' => 'array', 'resurrected_at' => 'datetime'];
}
