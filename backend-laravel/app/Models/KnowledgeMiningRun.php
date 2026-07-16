<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeMiningRun extends Model
{
    protected $fillable = [
        'status',
        'started_at',
        'finished_at',
        'nodes_created',
        'edges_created',
        'claims_created',
        'summary',
        'metrics',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metrics' => 'array',
    ];
}
