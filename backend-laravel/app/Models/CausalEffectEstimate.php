<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CausalEffectEstimate extends Model
{
    protected $fillable = ['causal_edge_id', 'estimand', 'effect_estimate', 'confidence_score', 'lower_bound', 'upper_bound', 'method', 'adjustment_set', 'metadata'];

    protected $casts = [
        'adjustment_set' => 'array',
        'metadata' => 'array',
    ];

    public function edge(): BelongsTo
    {
        return $this->belongsTo(CausalEdge::class, 'causal_edge_id');
    }
}
