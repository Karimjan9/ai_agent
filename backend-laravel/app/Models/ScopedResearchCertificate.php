<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Immutable registration and diagnostic-assessment records, never authority flags. */
class ScopedResearchCertificate extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'certificate_key', 'protocol', 'scope', 'record_type', 'parent_certificate_id',
        'source_type', 'source_id', 'source_hash', 'design_hash', 'payload_hash',
        'server_seal', 'payload', 'preregistered_at', 'recorded_at',
    ];

    protected $casts = [
        'payload' => 'array', 'preregistered_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime', 'source_id' => 'integer',
        'parent_certificate_id' => 'integer',
    ];

    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, $flags | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('SCOPED_CERTIFICATE_APPEND_ONLY'));
        static::deleting(fn () => throw new LogicException('SCOPED_CERTIFICATE_APPEND_ONLY'));
    }
}
