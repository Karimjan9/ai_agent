<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabCouncilDisagreement extends Model
{
    protected $fillable = [
        'event_key', 'symbol', 'timeframe', 'family', 'h1_context_hash', 'decision_at',
        'regime', 'specialist_votes', 'risk_decision', 'council_decision', 'disagreement',
        'outcome_status', 'outcome_score', 'evidence', 'promotion_evidence',
    ];

    protected $casts = [
        'decision_at' => 'datetime',
        'specialist_votes' => 'array',
        'disagreement' => 'array',
        'evidence' => 'array',
        'outcome_score' => 'float',
        'promotion_evidence' => 'boolean',
    ];

    public function adjudications(): HasMany
    {
        return $this->hasMany(LabCouncilAdjudication::class, 'disagreement_id');
    }
}
