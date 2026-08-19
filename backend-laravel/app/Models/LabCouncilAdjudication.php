<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabCouncilAdjudication extends Model
{
    protected $fillable = [
        'adjudication_key', 'disagreement_id', 'decision', 'evidence_run_id', 'replay_hash',
        'window_keys', 'role_votes', 'evidence', 'approved_by', 'approval_reason',
        'promotion_evidence',
    ];

    protected $casts = [
        'window_keys' => 'array', 'role_votes' => 'array', 'evidence' => 'array',
        'promotion_evidence' => 'boolean',
    ];

    public function disagreement(): BelongsTo
    {
        return $this->belongsTo(LabCouncilDisagreement::class, 'disagreement_id');
    }
}
