<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Existing descendant trial owner; scoped proofs use distinct diagnostic states. */
class DescendantValueTrial extends Model
{
    protected $fillable = [
        'trial_key', 'mentor_model_version_id', 'child_model_version_id',
        'symbol', 'timeframe', 'strategy_family', 'window_key', 'status',
        'evidence', 'settled_at',
    ];

    protected $casts = ['evidence' => 'array', 'settled_at' => 'datetime'];

    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, $flags | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
