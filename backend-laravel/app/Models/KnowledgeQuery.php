<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeQuery extends Model
{
    protected $fillable = [
        'question',
        'answer',
        'matched_node_ids',
        'matched_edge_ids',
        'confidence_score',
        'reasoning',
        'metadata',
    ];

    protected $casts = [
        'matched_node_ids' => 'array',
        'matched_edge_ids' => 'array',
        'reasoning' => 'array',
        'metadata' => 'array',
    ];
}
