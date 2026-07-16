<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeAudit extends Model
{
    protected $fillable = [
        'meta_audit_run_id',
        'knowledge_claim_id',
        'audit_type',
        'original_confidence',
        'audited_confidence',
        'decay_amount',
        'verdict',
        'recommended_action',
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

    public function knowledgeClaim(): BelongsTo
    {
        return $this->belongsTo(KnowledgeClaim::class);
    }
}
