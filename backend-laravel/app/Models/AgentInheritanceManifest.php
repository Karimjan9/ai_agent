<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentInheritanceManifest extends Model
{
    protected $fillable = ['manifest_key', 'lab_agent_id', 'lab_generation_id', 'symbol', 'timeframe', 'experiment_role', 'status', 'manifest', 'validation', 'sealed_at'];
    protected $casts = ['manifest' => 'array', 'validation' => 'array', 'sealed_at' => 'datetime'];
}
