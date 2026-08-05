<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScreeningLearningOutbox extends Model
{
    protected $table = 'screening_learning_outbox';
    protected $fillable = ['lab_agent_id', 'model_version_id', 'screen_result', 'forward_score', 'status', 'attempts', 'last_error', 'available_at', 'processed_at'];
    protected $casts = ['screen_result' => 'array', 'available_at' => 'datetime', 'processed_at' => 'datetime'];
}
