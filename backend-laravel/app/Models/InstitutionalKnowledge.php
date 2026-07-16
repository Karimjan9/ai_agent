<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstitutionalKnowledge extends Model
{
    protected $table = 'institutional_knowledge';

    protected $fillable = [
        'knowledge_key',
        'title',
        'knowledge_type',
        'summary',
        'confidence_score',
        'evidence_count',
        'preservation_status',
        'status',
        'source_type',
        'source_id',
        'scope',
        'metadata',
    ];

    protected $casts = [
        'scope' => 'array',
        'metadata' => 'array',
    ];
}
