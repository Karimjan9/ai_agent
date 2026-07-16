<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtinctionEvent extends Model
{
    protected $fillable = [
        'strategy_genome_id',
        'training_session_id',
        'reason_code',
        'reason',
        'evidence',
        'extinct_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'extinct_at' => 'datetime',
    ];

    public function strategyGenome(): BelongsTo
    {
        return $this->belongsTo(StrategyGenome::class);
    }

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }
}
