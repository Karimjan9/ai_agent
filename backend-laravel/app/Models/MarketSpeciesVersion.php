<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketSpeciesVersion extends Model
{
    protected $fillable = [
        'market_species_id',
        'version',
        'signature',
        'confidence_score',
        'sample_size',
    ];

    protected $casts = [
        'signature' => 'array',
    ];

    public function marketSpecies(): BelongsTo
    {
        return $this->belongsTo(MarketSpecies::class);
    }
}
