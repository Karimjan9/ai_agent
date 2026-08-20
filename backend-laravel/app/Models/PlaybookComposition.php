<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlaybookComposition extends Model
{
    protected $fillable = ['playbook_key', 'label', 'symbol', 'timeframe', 'promotion_state', 'instrument_keys', 'preconditions', 'metadata'];

    protected $casts = ['instrument_keys' => 'array', 'preconditions' => 'array', 'metadata' => 'array'];
}
