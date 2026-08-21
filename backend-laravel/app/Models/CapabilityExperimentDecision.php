<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CapabilityExperimentDecision extends Model
{
    protected $fillable = ['decision_key', 'lab_agent_id', 'lane', 'action', 'target_key', 'changed_axis', 'research_budget_percent', 'priority_score', 'status', 'contract', 'decided_at'];

    protected $casts = ['contract' => 'array', 'decided_at' => 'datetime'];
}
