<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeatureValueCatalog extends Model
{
    protected $table = 'feature_value_catalog';

    protected $fillable = ['feature_key', 'layer', 'unit', 'formula_version', 'definition', 'eligible_lanes', 'lookahead_safe'];

    protected $casts = ['definition' => 'array', 'eligible_lanes' => 'array', 'lookahead_safe' => 'boolean'];
}
