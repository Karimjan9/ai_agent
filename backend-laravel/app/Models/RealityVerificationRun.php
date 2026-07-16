<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RealityVerificationRun extends Model
{
    protected $fillable = ['status', 'started_at', 'finished_at', 'items_scored', 'certified_count', 'failed_count', 'cemetery_count', 'skeptic_reports_count', 'summary', 'metrics'];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metrics' => 'array',
    ];

    public function scores(): HasMany
    {
        return $this->hasMany(RealityScore::class);
    }
}
