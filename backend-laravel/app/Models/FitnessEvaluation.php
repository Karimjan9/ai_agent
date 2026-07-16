<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FitnessEvaluation extends Model
{
    protected $fillable = [
        'strategy_genome_id',
        'strategy_score_id',
        'training_session_id',
        'fitness_score',
        'components',
        'evaluation_summary',
    ];

    protected $casts = [
        'components' => 'array',
    ];

    public function strategyGenome(): BelongsTo
    {
        return $this->belongsTo(StrategyGenome::class);
    }

    public function strategyScore(): BelongsTo
    {
        return $this->belongsTo(StrategyScore::class);
    }

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }
}
