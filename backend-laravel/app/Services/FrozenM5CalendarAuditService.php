<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** Successful original calendar computation, keyed by every immutable input. */
class FrozenM5CalendarAuditService
{
    private static array $verified = [];

    public function inspect(string $source, array $arguments): array
    {
        $script = base_path('scripts/audit-frozen-m5-gap-source.py');
        $sourceHash = is_file($source) ? hash_file('sha256', $source) : false;
        $python = (new ExecutableFinder)->find('python');
        if (! $sourceHash || ! is_file($script) || ! $python || ! is_file($python)) {
            throw new RuntimeException('FROZEN_CALENDAR_ORIGINAL_INPUT_UNAVAILABLE');
        }
        $key = hash('sha256', json_encode([realpath($source), $sourceHash, $arguments,
            hash_file('sha256', $script), realpath($python), hash_file('sha256', $python),
            app(LabImmutableEvidenceService::class)->codeHash()], JSON_THROW_ON_ERROR));
        if (isset(self::$verified[$key])) return self::$verified[$key];
        $result = $this->execute($source, $arguments);
        if (($result['source_csv_sha256'] ?? null) !== $sourceHash
            || hash_file('sha256', $source) !== $sourceHash) {
            throw new RuntimeException('FROZEN_CALENDAR_ORIGINAL_SOURCE_DRIFT');
        }
        if (count(self::$verified) >= 16) array_shift(self::$verified);
        return self::$verified[$key] = $result;
    }

    protected function execute(string $source, array $arguments): array
    {
        $process = new Process(['python', '-B', base_path('scripts/audit-frozen-m5-gap-source.py'), $source, ...$arguments]);
        $process->setTimeout(120);
        $process->mustRun();
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
}
