<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentLearningPolicy extends Model
{
    protected $fillable = ['policy_id', 'policy_key', 'version', 'symbol', 'timeframe', 'strategy_family', 'state', 'parent_policy_id', 'definition', 'evidence', 'activated_at', 'retired_at'];
    protected $casts = ['definition' => 'array', 'evidence' => 'array', 'activated_at' => 'datetime', 'retired_at' => 'datetime'];
}
