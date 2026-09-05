<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FullStackPlaybookPassport extends Model
{
    protected $fillable = ['passport_key', 'lab_agent_id', 'model_version_id', 'edge_genesis_passport_id', 'symbol', 'timeframe', 'packet_key', 'arm', 'mastery_stage', 'status', 'data_hash', 'execution_hash', 'playbook', 'evidence', 'procedural_score', 'economic_score', 'assessed_at'];

    protected $casts = ['playbook' => 'array', 'evidence' => 'array', 'procedural_score' => 'float', 'economic_score' => 'float', 'assessed_at' => 'datetime'];
}
