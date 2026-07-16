<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CivilizationAgent extends Model
{
    protected $fillable = [
        'agent_key',
        'display_name',
        'role_key',
        'role_label',
        'domain',
        'status',
        'credits_balance',
        'reputation_score',
        'contribution_score',
        'trust_score',
        'vote_weight',
        'capabilities',
        'objectives',
        'metadata',
        'last_active_at',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'objectives' => 'array',
        'metadata' => 'array',
        'last_active_at' => 'datetime',
    ];

    public function creditEvents(): HasMany
    {
        return $this->hasMany(CivilizationCreditEvent::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(CouncilVote::class);
    }

    public function ownedGoals(): HasMany
    {
        return $this->hasMany(CivilizationGoal::class, 'owner_agent_id');
    }
}
