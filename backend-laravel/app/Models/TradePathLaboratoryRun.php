<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradePathLaboratoryRun extends Model
{
    protected $fillable = ['run_key', 'model_version_id', 'symbol', 'timeframe', 'composition_id', 'entry_hash', 'status', 'paths', 'attribution', 'evidence', 'settled_at'];
    protected $casts = ['paths' => 'array', 'attribution' => 'array', 'evidence' => 'array', 'settled_at' => 'datetime'];
}
