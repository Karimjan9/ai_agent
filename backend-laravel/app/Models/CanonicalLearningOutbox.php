<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable, idempotent hand-off between replay evidence and learning truth. */
class CanonicalLearningOutbox extends Model
{
    protected $table = 'canonical_learning_outbox';

    protected $fillable = [
        'idempotency_key', 'kind', 'status', 'pair_id', 'evidence_run_id',
        'data_hash', 'execution_hash', 'attempts', 'last_error', 'payload',
        'processed_at',
    ];

    protected $casts = ['payload' => 'array', 'processed_at' => 'datetime'];
}
