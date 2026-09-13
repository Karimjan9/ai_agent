<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CooperativeModuleSpeciesMember extends Model
{
    protected $fillable = [
        'member_key', 'symbol', 'timeframe', 'species', 'component_key', 'context_cell_key',
        'model_version_id', 'lab_agent_id', 'genome', 'evidence', 'authority_level', 'status',
    ];

    protected $casts = ['genome' => 'array', 'evidence' => 'array'];
}
