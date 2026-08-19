<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/** Shared output and exit-code contract for monitor/recovery commands. */
abstract class OperationalCommand extends Command
{
    /** @param array<string, mixed> $payload */
    protected function writeJson(array $payload, bool $pretty = false): void
    {
        $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0)));
    }

    /** @param array<string, mixed> $payload @param list<string> $except */
    protected function writeMetrics(array $payload, array $except = []): void
    {
        $rows = (new Collection($payload))->except($except)->map(fn ($value, $key): array => [
            (string) $key,
            is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ])->values()->all();
        $this->table(['Metric', 'Value'], $rows);
    }

    protected function statusExitCode(string $status, bool $strict): int
    {
        return $strict && $status === 'critical' ? self::FAILURE : self::SUCCESS;
    }
}
