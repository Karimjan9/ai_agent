<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RealityExperiment extends Model
{
    protected $fillable = ['reality_score_id', 'source_type', 'source_id', 'experiment_key', 'title', 'mode', 'status', 'planned_samples', 'observed_samples', 'success_rate', 'confidence_score', 'hypothesis', 'success_criteria', 'metadata'];

    protected $casts = [
        'success_criteria' => 'array',
        'metadata' => 'array',
    ];

    public function score(): BelongsTo
    {
        return $this->belongsTo(RealityScore::class, 'reality_score_id');
    }
}
