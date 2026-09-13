<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CooperativeExperimentSettlement extends Model
{
    protected $fillable = [
        'settlement_key', 'block_key', 'lab_generation_id', 'block_type', 'context_cell_key',
        'arm_results', 'component_effects', 'pareto_vectors', 'outcome_status',
        'evidence_complete', 'promotion_evidence',
    ];

    protected $casts = [
        'arm_results' => 'array', 'component_effects' => 'array', 'pareto_vectors' => 'array',
        'evidence_complete' => 'boolean', 'promotion_evidence' => 'boolean',
    ];
}
