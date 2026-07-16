<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CausalCounterfactual extends Model
{
    protected $fillable = ['causal_edge_id', 'question', 'baseline_value', 'intervention_value', 'estimated_delta', 'confidence_score', 'result_summary', 'metadata'];

    protected $casts = ['metadata' => 'array'];

    public function edge(): BelongsTo
    {
        return $this->belongsTo(CausalEdge::class, 'causal_edge_id');
    }
}
