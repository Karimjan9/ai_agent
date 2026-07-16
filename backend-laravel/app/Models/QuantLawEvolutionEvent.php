<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuantLawEvolutionEvent extends Model
{
    protected $fillable = [
        'quant_law_id',
        'event_type',
        'previous_confidence',
        'new_confidence',
        'delta',
        'reason',
        'evidence',
    ];

    protected $casts = [
        'evidence' => 'array',
    ];

    public function law(): BelongsTo
    {
        return $this->belongsTo(QuantLaw::class, 'quant_law_id');
    }
}
