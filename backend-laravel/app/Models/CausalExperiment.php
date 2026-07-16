<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CausalExperiment extends Model
{
    protected $fillable = ['causal_edge_id', 'experiment_key', 'title', 'hypothesis', 'status', 'control_group', 'experimental_group', 'expected_information_gain', 'success_criteria', 'metadata'];

    protected $casts = [
        'success_criteria' => 'array',
        'metadata' => 'array',
    ];

    public function edge(): BelongsTo
    {
        return $this->belongsTo(CausalEdge::class, 'causal_edge_id');
    }
}
