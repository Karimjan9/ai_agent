<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RealityScore extends Model
{
    protected $fillable = [
        'reality_verification_run_id',
        'source_type',
        'source_id',
        'source_layer',
        'source_title',
        'original_confidence',
        'reality_score',
        'evidence_score',
        'drift_score',
        'false_discovery_risk',
        'validation_status',
        'evidence_count',
        'live_sample_count',
        'paper_sample_count',
        'backtest_sample_count',
        'last_checked_at',
        'rationale',
        'metadata',
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(RealityVerificationRun::class, 'reality_verification_run_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RealityValidationEvent::class);
    }

    public function experiment(): HasOne
    {
        return $this->hasOne(RealityExperiment::class);
    }

    public function cemeteryEntry(): HasOne
    {
        return $this->hasOne(KnowledgeCemeteryEntry::class);
    }

    public function skepticReport(): HasOne
    {
        return $this->hasOne(SkepticReport::class);
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(CertifiedKnowledgeItem::class);
    }
}
