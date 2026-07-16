<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnifiedQuantModel extends Model
{
    protected $fillable = ['model_key', 'title', 'thesis', 'status', 'confidence_score', 'theory_count', 'law_count', 'root_cause_count', 'components', 'metadata'];

    protected $casts = [
        'components' => 'array',
        'metadata' => 'array',
    ];
}
