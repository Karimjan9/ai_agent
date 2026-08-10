<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VolumeShadowExperiment extends Model
{
    protected $fillable = [
        'lab_agent_id',
        'model_version_id',
        'symbol',
        'timeframe',
        'status',
        'protocol',
        'source_contract',
        'data_hash',
        'metrics',
        'promotion_evidence',
    ];

    protected $casts = [
        'metrics' => 'array',
        'promotion_evidence' => 'boolean',
    ];
}
