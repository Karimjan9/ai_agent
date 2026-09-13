<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResearchIdeaInboxEntry extends Model
{
    protected $fillable = [
        'idea_key', 'symbol', 'timeframe', 'source_type', 'source_reference', 'title',
        'hypothesis', 'compatibility_contract', 'executable_contract', 'bounded_genes',
        'status', 'assigned_block_key', 'evidence_receipt',
    ];

    protected $casts = [
        'compatibility_contract' => 'array',
        'executable_contract' => 'array',
        'bounded_genes' => 'array',
        'evidence_receipt' => 'array',
    ];
}
