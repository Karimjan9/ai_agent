<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeHealthScore extends Model
{
    protected $fillable = [
        'meta_audit_run_id',
        'overall_score',
        'fresh_discoveries_score',
        'aging_discoveries_score',
        'contradiction_score',
        'unknown_zone_score',
        'blind_spot_score',
        'components',
    ];

    protected $casts = [
        'components' => 'array',
    ];

    public function metaAuditRun(): BelongsTo
    {
        return $this->belongsTo(MetaAuditRun::class);
    }
}
