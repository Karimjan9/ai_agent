<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabEvolutionArchiveEntry extends Model
{
    protected $fillable = [
        'symbol', 'timeframe', 'strategy_family', 'island_key', 'archive_type',
        'model_version_id', 'lab_agent_id', 'lab_generation_id', 'rank',
        'novelty_score', 'behavior_signature', 'fitness_snapshot', 'metadata', 'status',
    ];

    protected $casts = [
        'fitness_snapshot' => 'array',
        'metadata' => 'array',
        'novelty_score' => 'float',
    ];

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class, 'lab_agent_id');
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(LabGeneration::class, 'lab_generation_id');
    }
}
