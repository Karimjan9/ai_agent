<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TheoryBattle extends Model
{
    protected $fillable = ['theory_a_id', 'theory_b_id', 'battle_key', 'status', 'winner_theory_id', 'confidence_gap', 'summary', 'evidence'];

    protected $casts = [
        'evidence' => 'array',
    ];

    public function theoryA(): BelongsTo
    {
        return $this->belongsTo(QuantTheory::class, 'theory_a_id');
    }

    public function theoryB(): BelongsTo
    {
        return $this->belongsTo(QuantTheory::class, 'theory_b_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(QuantTheory::class, 'winner_theory_id');
    }
}
