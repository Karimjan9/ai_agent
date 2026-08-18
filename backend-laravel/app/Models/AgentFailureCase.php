<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentFailureCase extends Model
{
    protected $fillable = [
        'failure_case_key',
        'market_slice_hash',
        'symbol',
        'timeframe',
        'regime',
        'failure_type',
        'severity',
        'expected_safe_behavior',
        'expected_action',
        'discovered_by',
        'source_model_version_id',
        'fixed_by_model_version_id',
        'regression_status',
        'discovered_at',
        'fixed_at',
        'evidence',
    ];

    protected $casts = [
        'evidence' => 'array',
        'discovered_at' => 'datetime',
        'fixed_at' => 'datetime',
    ];

    /**
     * The legacy relational projection is VARCHAR(64). Keep every writer
     * bounded so a verbose failure explanation cannot fail the whole queue
     * job; detailed evidence belongs in the JSON evidence column.
     */
    public function setExpectedSafeBehaviorAttribute(mixed $value): void
    {
        $this->attributes['expected_safe_behavior'] = mb_substr(trim((string) $value), 0, 64);
    }

    public function setExpectedActionAttribute(mixed $value): void
    {
        $this->attributes['expected_action'] = mb_substr(trim((string) $value), 0, 64);
    }
}
