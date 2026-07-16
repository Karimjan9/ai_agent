<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceHealthCheck extends Model
{
    protected $fillable = ['service_key', 'service_label', 'status', 'health_score', 'last_ok_at', 'last_checked_at', 'stale_after_seconds', 'message', 'metrics'];

    protected $casts = [
        'last_ok_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'metrics' => 'array',
    ];
}
