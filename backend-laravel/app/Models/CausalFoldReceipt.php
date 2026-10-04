<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CausalFoldReceipt extends Model
{
    protected $fillable = [
        'receipt_key', 'agent_learning_causal_experiment_id', 'lab_generation_id',
        'fold_index', 'fold_count', 'status', 'attempt_count', 'lease_token',
        'request_hash', 'response_hash', 'dataset_hash', 'execution_hash',
        'request_payload', 'response_payload', 'error_code', 'error_message',
        'started_at', 'completed_at', 'observed_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'observed_at' => 'datetime',
    ];

    /** Do not lose 1.0 -> 1 while persisting a pre-hashed request/response. */
    protected function asJson($value, $flags = 0)
    {
        return parent::asJson($value, $flags | JSON_PRESERVE_ZERO_FRACTION);
    }

    protected static function booted(): void
    {
        static::updating(function (CausalFoldReceipt $receipt): void {
            if ((string) $receipt->getOriginal('status') === 'completed'
                && array_diff(array_keys($receipt->getDirty()), ['updated_at']) !== []) {
                throw new LogicException('Completed causal fold receipts are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Causal fold receipts are append-only.'));
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(AgentLearningCausalExperiment::class, 'agent_learning_causal_experiment_id');
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(LabGeneration::class);
    }
}
