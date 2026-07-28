<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EconomicEvent extends Model
{
    protected $fillable = ['source', 'external_id', 'title', 'country', 'currency', 'impact', 'scheduled_at', 'actual', 'forecast', 'previous', 'payload'];
    protected $casts = ['scheduled_at' => 'datetime', 'payload' => 'array'];
}
