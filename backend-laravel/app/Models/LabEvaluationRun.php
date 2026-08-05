<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabEvaluationRun extends Model
{
    protected $fillable = [
        'run_id', 'lab_generation_id', 'lab_agent_id', 'model_version_id', 'phase', 'mode',
        'attempt', 'queue', 'job_uuid', 'request_id', 'status', 'started_at', 'finished_at',
        'duration_ms', 'worker_name', 'worker_pid', 'request_hash', 'response_hash', 'data_hash',
        'code_hash', 'parameter_hash', 'trade_ledger_hash', 'error_class', 'error_message',
        'request_meta', 'response_meta', 'metrics', 'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime', 'finished_at' => 'datetime',
        'request_meta' => 'array', 'response_meta' => 'array', 'metrics' => 'array', 'metadata' => 'array',
    ];

    public function generation(): BelongsTo { return $this->belongsTo(LabGeneration::class, 'lab_generation_id'); }
    public function agent(): BelongsTo { return $this->belongsTo(LabAgent::class, 'lab_agent_id'); }
    public function modelVersion(): BelongsTo { return $this->belongsTo(ModelVersion::class, 'model_version_id'); }
}
