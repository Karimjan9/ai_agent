<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentMemory extends Model
{
    protected $fillable = [
        'strategy',
        'memory_type',
        'market_regime',
        'volatility_regime',
        'market_species',
        'outcome',
        'training_session_id',
        'summary',
        'lesson',
        'strength',
        'confidence_score',
        'last_matched_at',
        'occurrences',
        'source_type',
        'source_id',
        'metadata',
    ];

    protected $casts = [
        'last_matched_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }
}
