<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouncilVote extends Model
{
    protected $fillable = [
        'council_decision_id',
        'civilization_agent_id',
        'vote',
        'weight',
        'confidence_score',
        'reason',
        'evidence',
    ];

    protected $casts = [
        'evidence' => 'array',
    ];

    public function decision(): BelongsTo
    {
        return $this->belongsTo(CouncilDecision::class, 'council_decision_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(CivilizationAgent::class, 'civilization_agent_id');
    }
}
