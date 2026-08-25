<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GenerationClosedLoopAudit extends Model
{
    protected $fillable = ['audit_key', 'lab_generation_id', 'status', 'replay_coverage', 'settlement_coverage', 'attribution_coverage', 'learning_decision_coverage', 'inheritance_manifest_coverage', 'report', 'audited_at'];
    protected $casts = ['report' => 'array', 'audited_at' => 'datetime'];
}
