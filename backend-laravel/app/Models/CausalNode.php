<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CausalNode extends Model
{
    protected $fillable = ['node_key', 'label', 'node_type', 'description', 'confidence_score', 'metadata'];

    protected $casts = ['metadata' => 'array'];
}
