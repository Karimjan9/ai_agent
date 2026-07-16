<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScientistJournal extends Model
{
    protected $fillable = [
        'training_session_id',
        'title',
        'summary',
        'observations',
        'most_failed_hypothesis',
        'conclusion',
        'metrics',
    ];

    protected $casts = [
        'observations' => 'array',
        'metrics' => 'array',
    ];

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }
}
