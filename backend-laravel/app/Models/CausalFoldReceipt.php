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

    /** Reopen the original request without turning its empty map ports into lists. */
    public function getRequestPayloadAttribute($value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->originalRequestJsonShape(json_decode($value, false, 512, JSON_THROW_ON_ERROR));
    }

    private function originalRequestJsonShape(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            if ($properties === []) {
                return $value;
            }

            return array_map(fn (mixed $item): mixed => $this->originalRequestJsonShape($item), $properties);
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->originalRequestJsonShape($item), $value);
        }

        return $value;
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
