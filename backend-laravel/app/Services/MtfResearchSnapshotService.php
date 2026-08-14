<?php

namespace App\Services;

use App\Models\MtfAblationRun;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Stores the exact bounded H1/M15/volume input used by an immutable control.
 *
 * Candle payloads stay out of the database and are addressed by the immutable
 * run key. The database keeps only the verified reference, so a later rolling
 * feed update cannot silently change a core/forward comparison.
 */
class MtfResearchSnapshotService
{
    public const PROTOCOL = 'xauusd_mtf_research_snapshot_v1';

    /** @return array<string, mixed> */
    public function store(
        string $runKey,
        string $symbol,
        array $h1,
        array $m15,
        array $volumeContext,
        array $execution,
        string $dataHash,
        string $executionHash,
        ?array $pilot = null,
    ): array {
        if (! preg_match('/^[a-f0-9]{64}$/', $runKey)) {
            throw new RuntimeException('MTF snapshot run key invalid.');
        }

        $directory = storage_path('app/mtf-research-snapshots');
        File::ensureDirectoryExists($directory);
        $relativePath = 'mtf-research-snapshots/'.$runKey.'.json';
        $path = storage_path('app/'.$relativePath);
        $payload = [
            'protocol' => self::PROTOCOL,
            'run_key' => $runKey,
            'symbol' => strtoupper($symbol),
            'regime_timeframe' => 'H1',
            'entry_timeframe' => 'M15',
            'data_hash' => $dataHash,
            'execution_hash' => $executionHash,
            'execution' => $execution,
            'volume_context' => $volumeContext,
            'mtf_pilot' => $pilot,
            'h1_candles' => array_values($h1),
            'm15_candles' => array_values($m15),
        ];
        $snapshotHash = $this->hash($payload);
        $payload['snapshot_hash'] = $snapshotHash;
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($encoded === false) {
            throw new RuntimeException('MTF snapshot JSON encoding failed.');
        }

        if (File::exists($path)) {
            $existing = json_decode((string) File::get($path), true);
            if (! is_array($existing) || (string) ($existing['snapshot_hash'] ?? '') !== $snapshotHash) {
                throw new RuntimeException('Immutable MTF snapshot collision detected.');
            }
        } else {
            // Atomic replace prevents a monitor/recovery process from ever
            // observing a half-written immutable snapshot.
            $temporaryPath = $path.'.tmp.'.bin2hex(random_bytes(8));
            File::put($temporaryPath, $encoded, true);
            if (! @rename($temporaryPath, $path)) {
                @unlink($temporaryPath);
                throw new RuntimeException('Immutable MTF snapshot write failed.');
            }
        }

        return $this->reference($relativePath, $payload);
    }

    /** @return array<string, mixed>|null */
    public function load(?MtfAblationRun $run): ?array
    {
        if (! $run) return null;
        $reference = (array) ($run->snapshot_reference ?? []);
        $relativePath = (string) ($reference['path'] ?? '');
        if (! preg_match('/^mtf-research-snapshots\/[a-f0-9]{64}\.json$/', $relativePath)) {
            return null;
        }

        $path = storage_path('app/'.$relativePath);
        if (! File::exists($path)) return null;
        $payload = json_decode((string) File::get($path), true);
        if (! is_array($payload)) return null;
        if ((string) ($payload['protocol'] ?? '') !== self::PROTOCOL
            || (string) ($payload['run_key'] ?? '') !== (string) $run->run_key
            || (string) ($payload['data_hash'] ?? '') !== (string) $run->data_hash
            || (string) ($payload['execution_hash'] ?? '') !== (string) $run->execution_hash
            || (string) ($payload['snapshot_hash'] ?? '') !== (string) ($reference['snapshot_hash'] ?? '')) {
            return null;
        }

        $expectedHash = (string) ($payload['snapshot_hash'] ?? '');
        unset($payload['snapshot_hash']);
        if ($expectedHash === '' || $this->hash($payload) !== $expectedHash) return null;
        $payload['snapshot_hash'] = $expectedHash;

        return $payload;
    }

    /** @return array<string, mixed> */
    private function reference(string $relativePath, array $payload): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'path' => $relativePath,
            'snapshot_hash' => (string) ($payload['snapshot_hash'] ?? ''),
            'data_hash' => (string) ($payload['data_hash'] ?? ''),
            'execution_hash' => (string) ($payload['execution_hash'] ?? ''),
            'h1_count' => count((array) ($payload['h1_candles'] ?? [])),
            'm15_count' => count((array) ($payload['m15_candles'] ?? [])),
            'h1_first' => data_get($payload, 'h1_candles.0.time'),
            'h1_last' => data_get($payload, 'h1_candles.'.(count((array) ($payload['h1_candles'] ?? [])) - 1).'.time'),
            'm15_first' => data_get($payload, 'm15_candles.0.time'),
            'm15_last' => data_get($payload, 'm15_candles.'.(count((array) ($payload['m15_candles'] ?? [])) - 1).'.time'),
        ];
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
