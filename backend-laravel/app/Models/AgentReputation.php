<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentReputation extends Model
{
    protected $fillable = [
        'strategy',
        'reputation_score',
        'stability_score',
        'trust_score',
        'calibration_score',
        'survival_score',
        'sessions_count',
        'last_training_session_id',
        'reasons',
    ];

    protected $casts = [
        'reasons' => 'array',
    ];

    public function lastTrainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'last_training_session_id');
    }
}
