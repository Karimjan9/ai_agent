<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeFact extends Model
{
    protected $fillable = [
        'title',
        'fact',
        'scope',
        'confidence_score',
        'evidence_count',
        'status',
        'source_type',
        'source_id',
        'discovered_at',
        'last_seen_at',
        'metadata',
    ];

    protected $casts = [
        'scope' => 'array',
        'metadata' => 'array',
        'discovered_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
