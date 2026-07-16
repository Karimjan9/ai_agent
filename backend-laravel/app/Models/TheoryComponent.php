<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TheoryComponent extends Model
{
    protected $fillable = ['quant_theory_id', 'component_type', 'source_type', 'source_id', 'contribution_score', 'polarity', 'summary', 'metadata'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function theory(): BelongsTo
    {
        return $this->belongsTo(QuantTheory::class, 'quant_theory_id');
    }
}
