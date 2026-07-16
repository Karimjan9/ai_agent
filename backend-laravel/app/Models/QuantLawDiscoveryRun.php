<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuantLawDiscoveryRun extends Model
{
    protected $fillable = [
        'status',
        'started_at',
        'finished_at',
        'candidates_created',
        'laws_promoted',
        'conflicts_found',
        'summary',
        'metrics',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metrics' => 'array',
    ];

    public function candidates(): HasMany
    {
        return $this->hasMany(QuantLawCandidate::class);
    }

    public function driverRankings(): HasMany
    {
        return $this->hasMany(UniversalDriverRanking::class);
    }
}
