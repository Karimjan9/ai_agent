<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TheoryPrediction extends Model
{
    protected $fillable = ['quant_theory_id', 'prediction_key', 'target_metric', 'baseline_value', 'intervention_value', 'predicted_delta', 'confidence_score', 'horizon', 'status', 'rationale', 'metadata'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function theory(): BelongsTo
    {
        return $this->belongsTo(QuantTheory::class, 'quant_theory_id');
    }
}
