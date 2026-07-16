<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeContradiction extends Model
{
    protected $fillable = [
        'meta_audit_run_id',
        'claim_a_id',
        'claim_b_id',
        'contradiction_type',
        'severity_score',
        'status',
        'summary',
        'evidence',
    ];

    protected $casts = [
        'evidence' => 'array',
    ];

    public function metaAuditRun(): BelongsTo
    {
        return $this->belongsTo(MetaAuditRun::class);
    }

    public function claimA(): BelongsTo
    {
        return $this->belongsTo(KnowledgeClaim::class, 'claim_a_id');
    }

    public function claimB(): BelongsTo
    {
        return $this->belongsTo(KnowledgeClaim::class, 'claim_b_id');
    }
}
