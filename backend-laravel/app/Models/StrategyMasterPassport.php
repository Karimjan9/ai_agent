<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StrategyMasterPassport extends Model
{
    protected $fillable = ['model_version_id', 'strategy_id', 'mastery_stage', 'status', 'target_regimes', 'metrics', 'evidence', 'assessed_at'];

    protected $casts = ['target_regimes' => 'array', 'metrics' => 'array', 'evidence' => 'array', 'assessed_at' => 'datetime'];
}
