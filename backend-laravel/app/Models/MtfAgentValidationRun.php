<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Durable owner and settlement boundary for a powered MTF prior. */
class MtfAgentValidationRun extends Model
{
    protected $fillable = [
        'run_key', 'protocol', 'source_run_id', 'model_version_id', 'symbol',
        'entry_timeframe', 'status', 'attempts', 'data_hash', 'execution_hash',
        'candidate_parameter_hash', 'control_parameter_hash', 'dataset_manifest',
        'validation_contract', 'candidate_result', 'control_result', 'paired_summary',
        'causal_accounting', 'reason_codes', 'last_error', 'promotion_evidence',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'dataset_manifest' => 'array', 'validation_contract' => 'array',
        'candidate_result' => 'array', 'control_result' => 'array',
        'paired_summary' => 'array', 'causal_accounting' => 'array',
        'reason_codes' => 'array', 'promotion_evidence' => 'boolean',
        'started_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(MtfPlaybookFrozenControlRun::class, 'source_run_id');
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }
}
