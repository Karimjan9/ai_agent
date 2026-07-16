<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MetaAuditRun extends Model
{
    protected $fillable = [
        'status',
        'started_at',
        'finished_at',
        'knowledge_health_score',
        'audited_claims',
        'decayed_beliefs',
        'contradictions_found',
        'unknown_zones_found',
        'blind_spots_found',
        'summary',
        'metrics',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metrics' => 'array',
    ];

    public function knowledgeAudits(): HasMany
    {
        return $this->hasMany(KnowledgeAudit::class);
    }

    public function beliefDecayEvents(): HasMany
    {
        return $this->hasMany(BeliefDecayEvent::class);
    }

    public function contradictions(): HasMany
    {
        return $this->hasMany(KnowledgeContradiction::class);
    }

    public function unknownZones(): HasMany
    {
        return $this->hasMany(UnknownZone::class);
    }

    public function blindSpots(): HasMany
    {
        return $this->hasMany(BlindSpot::class);
    }

    public function healthScore(): HasOne
    {
        return $this->hasOne(KnowledgeHealthScore::class);
    }

    public function selfCritiques(): HasMany
    {
        return $this->hasMany(SelfCritique::class);
    }
}
