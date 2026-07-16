<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertifiedKnowledgeItem extends Model
{
    protected $fillable = ['reality_score_id', 'source_type', 'source_id', 'certificate_key', 'title', 'grade', 'reality_score', 'issued_at', 'expires_at', 'evidence_summary', 'metadata'];

    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function score(): BelongsTo
    {
        return $this->belongsTo(RealityScore::class, 'reality_score_id');
    }
}
