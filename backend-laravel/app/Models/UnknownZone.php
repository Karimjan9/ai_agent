<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnknownZone extends Model
{
    protected $fillable = [
        'meta_audit_run_id',
        'symbol',
        'timeframe',
        'market_state',
        'market_species',
        'similarity_score',
        'uncertainty_score',
        'status',
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
}
