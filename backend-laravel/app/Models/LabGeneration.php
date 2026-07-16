<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class LabGeneration extends Model
{
    protected $fillable = ['ai_laboratory_id', 'generation', 'trigger_type', 'trigger_context', 'data_fingerprint', 'population_size', 'status', 'started_at', 'completed_at'];
    protected $casts = ['trigger_context' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    public function laboratory(): BelongsTo { return $this->belongsTo(AiLaboratory::class, 'ai_laboratory_id'); }
    public function agents(): HasMany { return $this->hasMany(LabAgent::class); }
}
