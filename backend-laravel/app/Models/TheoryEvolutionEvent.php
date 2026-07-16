<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TheoryEvolutionEvent extends Model
{
    protected $fillable = ['quant_theory_id', 'event_type', 'previous_status', 'new_status', 'previous_confidence', 'new_confidence', 'reason', 'evidence'];

    protected $casts = [
        'evidence' => 'array',
    ];

    public function theory(): BelongsTo
    {
        return $this->belongsTo(QuantTheory::class, 'quant_theory_id');
    }
}
