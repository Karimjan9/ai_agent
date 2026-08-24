<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GenerationAdmissionDecision extends Model
{
    protected $fillable = [
        'decision_key', 'ai_laboratory_id', 'latest_generation_id', 'decision',
        'allowed', 'reason_codes', 'context', 'decided_at',
    ];

    protected $casts = [
        'allowed' => 'boolean', 'reason_codes' => 'array', 'context' => 'array',
        'decided_at' => 'datetime',
    ];
}
