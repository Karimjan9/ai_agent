<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CausalEdge extends Model
{
    protected $fillable = ['causal_discovery_run_id', 'source_node_id', 'target_node_id', 'quant_law_id', 'edge_key', 'direction', 'identification_status', 'causality_score', 'correlation_score', 'effect_size', 'evidence_count', 'rationale', 'assumptions', 'metadata'];

    protected $casts = [
        'assumptions' => 'array',
        'metadata' => 'array',
    ];

    public function sourceNode(): BelongsTo
    {
        return $this->belongsTo(CausalNode::class, 'source_node_id');
    }

    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(CausalNode::class, 'target_node_id');
    }

    public function quantLaw(): BelongsTo
    {
        return $this->belongsTo(QuantLaw::class);
    }

    public function effectEstimates(): HasMany
    {
        return $this->hasMany(CausalEffectEstimate::class);
    }
}
