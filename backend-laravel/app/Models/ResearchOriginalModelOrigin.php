<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Server-created finite untrained programme origin; never a legacy-history declaration. */
class ResearchOriginalModelOrigin extends Model
{
    public $timestamps = false;

    protected $fillable = ['origin_key', 'lab_generation_id', 'payload_hash', 'server_seal', 'payload', 'recorded_at'];

    protected $casts = ['lab_generation_id' => 'integer', 'payload' => 'array', 'recorded_at' => 'immutable_datetime'];

    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, $flags | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ORIGINAL_MODEL_ORIGIN_APPEND_ONLY'));
        static::deleting(fn () => throw new LogicException('ORIGINAL_MODEL_ORIGIN_APPEND_ONLY'));
    }
}
