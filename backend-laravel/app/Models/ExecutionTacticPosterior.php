<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExecutionTacticPosterior extends Model
{
    protected $fillable = ['tactic_key', 'symbol', 'timeframe', 'state_key', 'observations', 'net_expectancy', 'uncertainty', 'mastery_stage', 'value_vector', 'last_observed_at'];

    protected $casts = ['value_vector' => 'array', 'last_observed_at' => 'datetime'];
}
