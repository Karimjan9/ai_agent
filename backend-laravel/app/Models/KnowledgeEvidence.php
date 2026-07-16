<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeEvidence extends Model
{
    protected $table = 'knowledge_evidence';

    protected $fillable = [
        'knowledge_claim_id',
        'knowledge_graph_node_id',
        'knowledge_graph_edge_id',
        'source_type',
        'source_id',
        'evidence_type',
        'summary',
        'weight',
        'observed_at',
        'metadata',
    ];

    protected $casts = [
        'observed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(KnowledgeClaim::class, 'knowledge_claim_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(KnowledgeGraphNode::class, 'knowledge_graph_node_id');
    }

    public function edge(): BelongsTo
    {
        return $this->belongsTo(KnowledgeGraphEdge::class, 'knowledge_graph_edge_id');
    }
}
