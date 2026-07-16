<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuantLawConflict extends Model
{
    protected $fillable = [
        'law_a_id',
        'law_b_id',
        'conflict_type',
        'severity_score',
        'status',
        'summary',
        'evidence',
    ];

    protected $casts = [
        'evidence' => 'array',
    ];

    public function lawA(): BelongsTo
    {
        return $this->belongsTo(QuantLaw::class, 'law_a_id');
    }

    public function lawB(): BelongsTo
    {
        return $this->belongsTo(QuantLaw::class, 'law_b_id');
    }
}
