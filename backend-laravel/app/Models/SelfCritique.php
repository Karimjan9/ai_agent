<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SelfCritique extends Model
{
    protected $fillable = [
        'meta_audit_run_id',
        'title',
        'critique',
        'evidence_summary',
        'recommended_action',
        'severity_score',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function metaAuditRun(): BelongsTo
    {
        return $this->belongsTo(MetaAuditRun::class);
    }
}
