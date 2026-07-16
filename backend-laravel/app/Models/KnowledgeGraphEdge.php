<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeGraphEdge extends Model
{
    protected $fillable = [
        'source_node_id',
        'target_node_id',
        'relation_type',
        'weight',
        'confidence_score',
        'evidence_count',
        'polarity',
        'status',
        'metadata',
        'last_seen_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_seen_at' => 'datetime',
    ];

    public function sourceNode(): BelongsTo
    {
        return $this->belongsTo(KnowledgeGraphNode::class, 'source_node_id');
    }

    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(KnowledgeGraphNode::class, 'target_node_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(KnowledgeEvidence::class);
    }
}
