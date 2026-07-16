<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketDiscovery extends Model
{
    protected $fillable = [
        'title',
        'discovery',
        'market_species_id',
        'market_state',
        'confidence_score',
        'evidence_count',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function marketSpecies(): BelongsTo
    {
        return $this->belongsTo(MarketSpecies::class);
    }
}
