<?php

namespace App\Services;

use RuntimeException;

/** Stores runtime-only secrets outside PM2 metadata and source-controlled config. */
class ProtectedSecretFileService
{
    private const FILES = [
        'internal_api' => 'internal-api.token',
        'alpha_vantage' => 'alpha-vantage-api.key',
        'currents_api' => 'currents-api.key',
    ];

    public function path(string $name): string
    {
        if (! array_key_exists($name, self::FILES)) throw new RuntimeException('Unsupported protected secret name.');
        if ($name === 'internal_api') {
            $configured = trim((string) env('INTERNAL_API_TOKEN_FILE', ''));
            if ($configured !== '') return $configured;
        }
        return storage_path('app/secrets/'.self::FILES[$name]);
    }

    public function put(string $name, string $value): void
    {
        $value = trim($value);
        if ($value === '') throw new RuntimeException('Refusing to persist an empty secret.');
        $path = $this->path($name);
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException('Could not create protected secret directory.');
        }
        $temporary = $path.'.tmp';
        file_put_contents($temporary, $value."\n", LOCK_EX);
        @chmod($temporary, 0600);
        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not atomically persist protected secret.');
        }
        @chmod($path, 0600);
    }
}
