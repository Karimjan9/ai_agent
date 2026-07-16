<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvolutionProposal extends Model
{
    protected $fillable = [
        'training_session_id',
        'model_version_id',
        'parent_model_version_id',
        'applied_model_version_id',
        'strategy',
        'symbol',
        'timeframe',
        'strategy_family',
        'current_version',
        'proposed_version',
        'current_score',
        'main_problem',
        'reason',
        'proposal',
        'old_parameters',
        'new_parameters',
        'status',
        'open_status',
        'approved_at',
        'applied_at',
    ];

    protected $casts = [
        'old_parameters' => 'array',
        'new_parameters' => 'array',
        'approved_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }
}
