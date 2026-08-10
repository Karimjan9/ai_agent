<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentProfessionalExam extends Model
{
    protected $fillable = [
        'exam_id', 'exam_hash', 'lab_agent_id', 'model_version_id', 'parent_model_version_id',
        'symbol', 'timeframe', 'strategy_family', 'exam_type', 'status', 'challenge_version',
        'state_cluster_id', 'challenge_digest', 'metrics', 'evidence', 'source_run_ids',
        'promotion_evidence', 'observed_at', 'expires_at',
    ];

    protected $casts = [
        'metrics' => 'array', 'evidence' => 'array', 'source_run_ids' => 'array',
        'promotion_evidence' => 'boolean', 'observed_at' => 'datetime', 'expires_at' => 'datetime',
    ];

    public function labAgent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class);
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }

    public function parentModelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class, 'parent_model_version_id');
    }
}
