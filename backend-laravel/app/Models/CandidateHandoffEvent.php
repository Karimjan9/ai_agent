<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateHandoffEvent extends Model
{
    protected $fillable = ['lab_generation_id', 'lab_agent_id', 'stage', 'status', 'terminal_reason', 'payload', 'recorded_at'];

    protected $casts = ['payload' => 'array', 'recorded_at' => 'datetime'];

    public function generation(): BelongsTo
    {
        return $this->belongsTo(LabGeneration::class, 'lab_generation_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class, 'lab_agent_id');
    }

    /**
     * A retryable constructor admission must not monopolise the arbiter while
     * it is waiting for fresh data or another owner to finish. Invalid legacy
     * timestamps are deliberately treated as due so they can be repaired by
     * the command instead of becoming permanently invisible.
     */
    public function targetedGenerationRetryDue(): bool
    {
        $nextRetryAt = trim((string) data_get($this->payload, 'targeted_retry.next_retry_at', ''));
        if ($nextRetryAt === '') {
            return true;
        }

        try {
            return CarbonImmutable::now('UTC')->greaterThanOrEqualTo(CarbonImmutable::parse($nextRetryAt)->utc());
        } catch (\Throwable) {
            return true;
        }
    }
}
