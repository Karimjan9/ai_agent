<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpportunityFunnelEntry extends Model
{
    protected $fillable = ['opportunity_key', 'model_version_id', 'symbol', 'timeframe', 'composition_id', 'stage', 'decision', 'rejected_reason', 'funnel', 'evidence_snapshot', 'expected_value_before_filter', 'expected_value_after_filter', 'shadow_outcome', 'available_at', 'decided_at'];
    protected $casts = ['funnel' => 'array', 'evidence_snapshot' => 'array', 'shadow_outcome' => 'array', 'available_at' => 'datetime', 'decided_at' => 'datetime'];
}
