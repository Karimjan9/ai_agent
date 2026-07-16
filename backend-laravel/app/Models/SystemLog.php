<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemLog extends Model
{
    protected $fillable = ['log_type', 'level', 'component', 'action', 'status', 'source_type', 'source_id', 'message', 'context', 'occurred_at'];

    protected $casts = [
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];
}
