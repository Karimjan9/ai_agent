<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscoveryQualityScore extends Model
{
    protected $fillable = ['source_type', 'source_id', 'title', 'correlation_score', 'causality_score', 'quality_score', 'verdict', 'metadata'];

    protected $casts = ['metadata' => 'array'];
}
