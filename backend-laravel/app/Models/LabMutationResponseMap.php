<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only observation of one declared mutation against its frozen
 * baseline.  This is a response map, not a promotion table: a row can teach
 * the next compiler, but it can never open a gate by itself.
 */
class LabMutationResponseMap extends Model
{
    protected $fillable = [
        'response_key', 'stage', 'status', 'symbol', 'timeframe',
        'strategy_family', 'target', 'parameter_key', 'direction', 'sibling_kind',
        'lab_agent_id', 'model_version_id', 'repair_anchor_id', 'evidence_run_id',
        'temporal_window_key', 'old_value', 'new_value', 'baseline_metrics',
        'observed_metrics', 'target_delta', 'non_target_regression',
        'regime_result', 'forward_confirmation', 'metadata',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'baseline_metrics' => 'array',
        'observed_metrics' => 'array',
        'target_delta' => 'array',
        'non_target_regression' => 'array',
        'regime_result' => 'array',
        'forward_confirmation' => 'array',
        'metadata' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class, 'lab_agent_id');
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class, 'model_version_id');
    }

    public function repairAnchor(): BelongsTo
    {
        return $this->belongsTo(LabFailureRepairAnchor::class, 'repair_anchor_id');
    }
}
