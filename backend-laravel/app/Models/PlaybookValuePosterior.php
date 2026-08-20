<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybookValuePosterior extends Model
{
    protected $fillable = ['playbook_composition_id', 'symbol', 'timeframe', 'state_key', 'observations', 'net_value', 'uncertainty', 'decay_state', 'value_vector', 'last_observed_at'];

    protected $casts = ['value_vector' => 'array', 'last_observed_at' => 'datetime'];

    public function playbook(): BelongsTo
    {
        return $this->belongsTo(PlaybookComposition::class, 'playbook_composition_id');
    }
}
