<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebateArgument extends Model
{
    protected $fillable = [
        'internal_debate_id',
        'strategy',
        'stance',
        'confidence',
        'argument',
        'evidence',
    ];

    protected $casts = [
        'evidence' => 'array',
    ];

    public function internalDebate(): BelongsTo
    {
        return $this->belongsTo(InternalDebate::class);
    }
}
