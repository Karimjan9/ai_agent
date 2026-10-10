<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Original capture boundaries and ingress receipts are append-only evidence. */
class ResearchExposureCaptureRecord extends Model
{
    public $timestamps = false;

    protected $fillable = ['record_key', 'certificate_id', 'record_type', 'evaluation_run_id',
        'payload_hash', 'server_seal', 'payload', 'recorded_at'];

    protected $casts = ['payload' => 'array', 'recorded_at' => 'immutable_datetime',
        'certificate_id' => 'integer', 'evaluation_run_id' => 'integer'];

    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, $flags | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** Keep empty wire dictionaries distinct from lists when reopening sealed originals. */
    public function fromJson($value, $asObject = false)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode($value, false, 512, JSON_THROW_ON_ERROR);

        return $asObject ? $decoded : $this->originalJsonShape($decoded);
    }

    private function originalJsonShape(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            if ($properties === []) {
                return $value;
            }

            return array_map(fn (mixed $item): mixed => $this->originalJsonShape($item), $properties);
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->originalJsonShape($item), $value);
        }

        return $value;
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('RESEARCH_EXPOSURE_RECORD_APPEND_ONLY'));
        static::deleting(fn () => throw new LogicException('RESEARCH_EXPOSURE_RECORD_APPEND_ONLY'));
    }
}
