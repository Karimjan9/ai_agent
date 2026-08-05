<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabEvidenceArtifact extends Model
{
    protected $fillable = [
        'artifact_id', 'run_id', 'lab_generation_id', 'lab_agent_id', 'artifact_type', 'sha256',
        'byte_size', 'content_encoding', 'storage_path', 'payload', 'metadata', 'recorded_at',
    ];

    protected $casts = ['payload' => 'array', 'metadata' => 'array', 'recorded_at' => 'datetime'];

    public function generation(): BelongsTo { return $this->belongsTo(LabGeneration::class, 'lab_generation_id'); }
    public function agent(): BelongsTo { return $this->belongsTo(LabAgent::class, 'lab_agent_id'); }
}
