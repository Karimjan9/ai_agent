<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CausalRootCause extends Model
{
    protected $fillable = ['causal_edge_id', 'cause_key', 'title', 'summary', 'impact_score', 'confidence_score', 'rank', 'status', 'metadata'];

    protected $casts = ['metadata' => 'array'];

    public function edge(): BelongsTo
    {
        return $this->belongsTo(CausalEdge::class, 'causal_edge_id');
    }
}
