<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchExperimentReceipt extends Model
{
    protected $fillable = ['receipt_key', 'source_type', 'source_id', 'canonical_learning_outbox_id', 'symbol', 'laboratory_timeframe', 'execution_timeframe', 'contract_version', 'rule_version', 'contract_hash', 'evidence_hash', 'classification', 'subject_revision', 'evidence_revision', 'payload', 'terminal_reason'];
    protected $casts = ['payload' => 'array', 'terminal_reason' => 'array'];

    /** New receipts preserve the numeric representation used by their owner hash. */
    protected function asJson($value, $flags = 0)
    {
        return parent::asJson($value, $flags | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(ResearchExperimentWorkItem::class);
    }
}
