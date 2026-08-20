<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackGenomeArchiveEvent extends Model
{
    protected $fillable = ['event_key', 'archive_key', 'symbol', 'timeframe', 'lane', 'cell_key', 'behavior_cell', 'genome_hash', 'fitness_score', 'novelty_score', 'event_type', 'genes', 'evidence', 'promotion_evidence'];
    protected $casts = ['fitness_score' => 'float', 'novelty_score' => 'float', 'genes' => 'array', 'evidence' => 'array', 'promotion_evidence' => 'boolean'];
}
