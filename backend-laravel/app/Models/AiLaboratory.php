<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class AiLaboratory extends Model
{
    protected $fillable = ['symbol', 'name', 'timeframe', 'strategy_families', 'is_active', 'lifecycle_mode'];
    protected $casts = ['strategy_families' => 'array', 'is_active' => 'boolean'];
    public function generations(): HasMany { return $this->hasMany(LabGeneration::class); }
}
