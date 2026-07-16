<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkepticReport extends Model
{
    protected $fillable = ['reality_score_id', 'source_type', 'source_id', 'report_key', 'verdict', 'false_discovery_risk', 'objections', 'suggested_tests', 'metadata'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function score(): BelongsTo
    {
        return $this->belongsTo(RealityScore::class, 'reality_score_id');
    }
}
