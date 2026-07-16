<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeClaim extends Model
{
    protected $fillable = [
        'primary_node_id',
        'title',
        'claim',
        'claim_type',
        'confidence_score',
        'evidence_count',
        'status',
        'scope',
        'metadata',
        'last_seen_at',
    ];

    protected $casts = [
        'scope' => 'array',
        'metadata' => 'array',
        'last_seen_at' => 'datetime',
    ];

    public function primaryNode(): BelongsTo
    {
        return $this->belongsTo(KnowledgeGraphNode::class, 'primary_node_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(KnowledgeEvidence::class);
    }
}
