<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningProtocolEpochLink extends Model
{
    protected $fillable = [
        'link_key', 'protocol_epoch', 'entity_type', 'entity_id', 'lab_generation_id',
        'symbol', 'timeframe', 'data_hash', 'execution_hash', 'source_link_hash',
        'linkage_status', 'eligible_for_v2_denominator', 'evidence',
    ];

    protected $casts = ['eligible_for_v2_denominator' => 'boolean', 'evidence' => 'array'];
}
