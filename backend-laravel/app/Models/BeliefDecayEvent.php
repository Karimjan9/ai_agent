<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeliefDecayEvent extends Model
{
    protected $fillable = [
        'meta_audit_run_id',
        'agent_belief_id',
        'strategy',
        'belief_key',
        'original_score',
        'decayed_score',
        'decay_amount',
        'reason_code',
        'reason',
        'evidence',
    ];

    protected $casts = [
        'evidence' => 'array',
    ];

    public function metaAuditRun(): BelongsTo
    {
        return $this->belongsTo(MetaAuditRun::class);
    }

    public function agentBelief(): BelongsTo
    {
        return $this->belongsTo(AgentBelief::class);
    }
}
