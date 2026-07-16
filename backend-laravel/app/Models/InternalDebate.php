<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InternalDebate extends Model
{
    protected $fillable = [
        'training_session_id',
        'symbol',
        'timeframe',
        'final_decision',
        'consensus_score',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    public function arguments(): HasMany
    {
        return $this->hasMany(DebateArgument::class);
    }
}
