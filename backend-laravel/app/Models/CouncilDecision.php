<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CouncilDecision extends Model
{
    protected $fillable = [
        'proposal_key',
        'proposed_by_agent_id',
        'title',
        'proposal_type',
        'status',
        'final_decision',
        'expected_value_score',
        'risk_score',
        'knowledge_gap_score',
        'quorum_score',
        'consensus_score',
        'rationale',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(CivilizationAgent::class, 'proposed_by_agent_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(CouncilVote::class);
    }
}
