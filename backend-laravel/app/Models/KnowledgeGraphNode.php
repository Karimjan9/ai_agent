<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeGraphNode extends Model
{
    protected $fillable = [
        'node_type',
        'node_key',
        'label',
        'summary',
        'source_type',
        'source_id',
        'confidence_score',
        'evidence_count',
        'metadata',
        'last_seen_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_seen_at' => 'datetime',
    ];

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(KnowledgeGraphEdge::class, 'source_node_id');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(KnowledgeGraphEdge::class, 'target_node_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(KnowledgeClaim::class, 'primary_node_id');
    }
}
