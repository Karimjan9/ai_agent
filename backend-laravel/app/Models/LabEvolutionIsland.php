<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabEvolutionIsland extends Model
{
    protected $fillable = [
        'symbol', 'timeframe', 'strategy_family', 'island_key',
        'local_champion_model_version_id', 'archive_counts', 'diversity_score',
        'progress_score', 'stagnation_generations', 'status', 'metadata',
    ];

    protected $casts = [
        'archive_counts' => 'array',
        'metadata' => 'array',
        'diversity_score' => 'float',
        'progress_score' => 'float',
    ];

    public function localChampion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class, 'local_champion_model_version_id');
    }
}
