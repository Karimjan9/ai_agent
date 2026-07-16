<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketSpecies extends Model
{
    protected $fillable = [
        'code',
        'name',
        'dominant_state',
        'description',
        'danger_score',
        'opportunity_score',
        'signature',
    ];

    protected $casts = [
        'signature' => 'array',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(MarketSpeciesVersion::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(MarketStateSnapshot::class);
    }

    public function genomes(): HasMany
    {
        return $this->hasMany(MarketGenome::class);
    }
}
