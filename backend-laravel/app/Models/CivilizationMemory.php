<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CivilizationMemory extends Model
{
    protected $fillable = [
        'memory_key',
        'memory_type',
        'title',
        'summary',
        'impact_score',
        'source_type',
        'source_id',
        'tags',
        'evidence',
        'status',
    ];

    protected $casts = [
        'tags' => 'array',
        'evidence' => 'array',
    ];
}
