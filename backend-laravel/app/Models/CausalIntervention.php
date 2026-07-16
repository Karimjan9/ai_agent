<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CausalIntervention extends Model
{
    protected $fillable = ['causal_edge_id', 'title', 'intervention_type', 'recommendation', 'expected_impact_score', 'cost_score', 'risk_score', 'status', 'parameters', 'metadata'];

    protected $casts = [
        'parameters' => 'array',
        'metadata' => 'array',
    ];

    public function edge(): BelongsTo
    {
        return $this->belongsTo(CausalEdge::class, 'causal_edge_id');
    }
}
