<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GenomeDiscovery extends Model
{
    protected $fillable = [
        'title',
        'discovery',
        'gene_key',
        'scope',
        'confidence_score',
        'evidence_count',
        'status',
        'metadata',
    ];

    protected $casts = [
        'scope' => 'array',
        'metadata' => 'array',
    ];
}
