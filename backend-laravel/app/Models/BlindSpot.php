<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlindSpot extends Model
{
    protected $fillable = [
        'meta_audit_run_id',
        'spot_key',
        'label',
        'priority_score',
        'status',
        'reason',
        'coverage',
        'suggested_research',
    ];

    protected $casts = [
        'coverage' => 'array',
        'suggested_research' => 'array',
    ];

    public function metaAuditRun(): BelongsTo
    {
        return $this->belongsTo(MetaAuditRun::class);
    }
}
