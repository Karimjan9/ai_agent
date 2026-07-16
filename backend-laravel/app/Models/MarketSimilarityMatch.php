<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketSimilarityMatch extends Model
{
    protected $fillable = [
        'current_market_genome_id',
        'matched_market_genome_id',
        'similarity_score',
        'lesson',
    ];

    public function currentGenome(): BelongsTo
    {
        return $this->belongsTo(MarketGenome::class, 'current_market_genome_id');
    }

    public function matchedGenome(): BelongsTo
    {
        return $this->belongsTo(MarketGenome::class, 'matched_market_genome_id');
    }
}
