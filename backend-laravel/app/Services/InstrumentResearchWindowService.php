<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use App\Models\LabGeneration;
use RuntimeException;

/** Server-owned, post-paper research windows for instrument confirmation. */
class InstrumentResearchWindowService
{
    public const PROTOCOL = 'instrument_research_window_v1';

    public const TRANSPORT_PROTOCOL = 'authorized_research_transport_v1';

    /**
     * Execution admission only. Original server authorization, independence
     * and credit still have their own evidence-write guards. No wall-clock
     * signature fields: a durable retry refers to the same frozen contract.
     */
    public function bindReplayRequest(LabGeneration $generation, array $request): array
    {
        unset($request['policy_context']['authorized_research_transport']);
        $hash = (string) ($request['replay_dataset_hash'] ?? '');
        $matches = [];
        foreach ((array) config('services.instrument_policy.authorized_research_windows', []) as $manifest) {
            if (! is_array($manifest)) continue;
            $window = $this->seal((string) ($manifest['authorization_id'] ?? ''), $hash);
            if ($window !== null) $matches[$window['window_key']] = $window;
        }
        if ($matches === []) return $request; // Historical/default registry unchanged.
        if (count($matches) !== 1) throw new RuntimeException('RESEARCH_TRANSPORT_AUTHORIZATION_AMBIGUOUS');
        $window = array_values($matches)[0];
        if (($request['evaluation_mode'] ?? null) !== 'full'
            || ! app(ResearchPaperEpochContractService::class)->researchIntervalDisjointFromPaper(
                $window['start_inclusive'], $window['end_exclusive'])
            || CarbonImmutable::parse($window['start_inclusive'])->lessThan('2027-01-01T00:00:00Z')) {
            throw new RuntimeException('RESEARCH_TRANSPORT_PURPOSE_OR_PAPER_BOUNDARY_INVALID');
        }
        $persisted = LabGeneration::query()->find($generation->getKey());
        $seal = (array) data_get($persisted?->trigger_context, 'research_release', []);
        if ($seal === [] || $seal !== (array) ($request['research_release'] ?? [])
            || ! hash_equals((string) ($seal['dataset_hash'] ?? ''), $hash)) {
            throw new RuntimeException('RESEARCH_TRANSPORT_PERSISTED_RELEASE_REQUIRED');
        }
        app(ResearchReleaseSealService::class)->assertCurrent($persisted);
        $requestManifest = (array) ($request['mtf_snapshot_manifest'] ?? []);
        $frozenManifest = (array) data_get($persisted->trigger_context, 'mtf_bundle_manifest', []);
        if ($requestManifest !== [] || $frozenManifest !== []) {
            if ($this->transportJson($requestManifest) !== $this->transportJson($frozenManifest)
                || (string) ($requestManifest['bundle_hash'] ?? '') !== $hash) {
                throw new RuntimeException('RESEARCH_TRANSPORT_PERSISTED_STREAM_MANIFEST_MISMATCH');
            }
        } else {
            $price = (array) data_get($persisted->trigger_context, 'canonical_dataset_snapshots.price', []);
            if (($price['sha256'] ?? null) !== $hash || ! is_string($price['path'] ?? null)
                || $this->transportPath($price['path']) !== $this->transportPath((string) ($request['dataset_path'] ?? ''))) {
                throw new RuntimeException('RESEARCH_TRANSPORT_PERSISTED_PRIMARY_SOURCE_MISMATCH');
            }
        }
        if (! empty($request['candles']) || ! empty($request['regime_candles'])
            || array_filter((array) ($request['mtf_streams'] ?? [])) !== []
            || array_filter((array) ($request['related_mtf_streams'] ?? [])) !== []) {
            throw new RuntimeException('RESEARCH_TRANSPORT_INLINE_FORBIDDEN');
        }
        $files = $this->transportFiles($request, $window);
        $paperExclusions = [['start_inclusive' => '2026-01-01T00:00:00+00:00', 'end_exclusive' => '2027-01-01T00:00:00+00:00']];
        foreach ((array) config('services.research_paper_epochs.authorized_paper_epochs', []) as $paper) {
            if (! is_array($paper) || ($paper['approved'] ?? null) !== true) continue;
            $from = CarbonImmutable::parse((string) ($paper['start_inclusive'] ?? ''), 'UTC')->utc();
            $until = CarbonImmutable::parse((string) ($paper['end_exclusive'] ?? ''), 'UTC')->utc();
            if (! $until->greaterThan($from) || $from->lessThan('2027-01-01T00:00:00Z')
                || ($from->lessThan(CarbonImmutable::parse($window['end_exclusive']))
                    && $until->greaterThan(CarbonImmutable::parse($window['start_inclusive'])))) {
                throw new RuntimeException('RESEARCH_TRANSPORT_PAPER_OVERLAP');
            }
            $paperExclusions[] = ['start_inclusive' => $from->toIso8601String(), 'end_exclusive' => $until->toIso8601String()];
        }
        usort($paperExclusions, static fn (array $a, array $b): int => strcmp($a['start_inclusive'], $b['start_inclusive']));
        $identity = [
            'protocol' => self::TRANSPORT_PROTOCOL,
            'purpose' => 'server_authorized_research_execution',
            'generation_id' => (int) $generation->getKey(),
            'release_hash' => (string) ($seal['release_hash'] ?? ''),
            'dataset_hash' => $hash,
            'symbol' => (string) ($request['symbol'] ?? ''),
            'timeframe' => (string) ($request['timeframe'] ?? ''),
            'evaluation_mode' => 'full',
            'window' => $window,
            'files' => $files,
            'paper_exclusions' => $paperExclusions,
            'independent_evidence' => false,
            'promotion_evidence' => false,
        ];
        $key = (string) config('services.internal_api.token', '');
        if (strlen($key) < 32) throw new RuntimeException('RESEARCH_TRANSPORT_INTERNAL_KEY_UNAVAILABLE');
        $canonical = $this->transportJson($identity);
        $request['policy_context']['authorized_research_transport'] = [
            ...$identity,
            'contract_hash' => hash('sha256', $canonical),
            'hmac_sha256' => hash_hmac('sha256', self::TRANSPORT_PROTOCOL."\n".$canonical, $key),
        ];
        return $request;
    }

    /** Read/hash the same bytes; every input, including warmup/related data, is scoped. */
    private function transportFiles(array $request, array $window): array
    {
        $primary = strtoupper((string) ($request['timeframe'] ?? ''));
        $manifest = (array) ($request['mtf_snapshot_manifest'] ?? []);
        $records = (array) ($manifest['streams'] ?? []);
        if (! empty($manifest['bundle_hash'])
            && ! hash_equals((string) $manifest['bundle_hash'], (string) $request['replay_dataset_hash'])) {
            throw new RuntimeException('RESEARCH_TRANSPORT_BUNDLE_MISMATCH');
        }
        $paths = [$primary => $request['dataset_path'] ?? null];
        if (! empty($request['regime_dataset_path'])) $paths['REGIME_H1'] = $request['regime_dataset_path'];
        if (! empty($request['foundation_dataset_path'])) $paths['FOUNDATION'] = $request['foundation_dataset_path'];
        foreach ((array) ($request['mtf_dataset_paths'] ?? []) as $stream => $path) {
            $stream = strtoupper($stream);
            if (! in_array($stream, ['M1', 'M5', 'M15', 'M30', 'H1', 'H4', 'D1'], true)) throw new RuntimeException('RESEARCH_TRANSPORT_STREAM_INVALID');
            if (isset($paths[$stream]) && (! is_string($path)
                || $this->transportPath($path) !== $this->transportPath((string) $paths[$stream]))) {
                throw new RuntimeException('RESEARCH_TRANSPORT_DUPLICATE_STREAM_PATH_MISMATCH:'.$stream);
            }
            $paths[$stream] = $path;
        }
        foreach ((array) ($request['related_mtf_dataset_paths'] ?? []) as $stream => $path) {
            $stream = strtoupper($stream);
            if (! in_array($stream, ['M1', 'M5', 'M15', 'M30', 'H1', 'H4', 'D1'], true)) throw new RuntimeException('RESEARCH_TRANSPORT_STREAM_INVALID');
            $stream = 'RELATED_'.$stream;
            if (isset($paths[$stream]) && (! is_string($path)
                || $this->transportPath($path) !== $this->transportPath((string) $paths[$stream]))) {
                throw new RuntimeException('RESEARCH_TRANSPORT_DUPLICATE_STREAM_PATH_MISMATCH:'.$stream);
            }
            $paths[$stream] = $path;
        }
        if (count($paths) > 16 || empty($paths[$primary])) throw new RuntimeException('RESEARCH_TRANSPORT_SOURCE_MISSING');
        $files = []; $totalBytes = 0;
        foreach ($paths as $stream => $path) {
            $recordKey = $stream === 'REGIME_H1' ? 'H1' : $stream;
            $record = (array) ($records[$recordKey] ?? []);
            if ($records === [] && $stream === $primary) {
                $record = ['path' => $path, 'sha256' => $request['replay_dataset_hash']];
            }
            if (! is_string($path) || ! is_string($record['path'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/', (string) ($record['sha256'] ?? ''))) {
                throw new RuntimeException('RESEARCH_TRANSPORT_SOURCE_SEAL_MISSING:'.$stream);
            }
            $actual = $this->transportPath($path);
            if ($actual !== $this->transportPath($record['path'])) throw new RuntimeException('RESEARCH_TRANSPORT_PATH_MISMATCH:'.$stream);
            $size = filesize($actual);
            $remaining = 268435456 - $totalBytes;
            if ($size === false || $size <= 0 || $size > $remaining) throw new RuntimeException('RESEARCH_TRANSPORT_SOURCE_SIZE_INVALID');
            $bytes = file_get_contents($actual, length: $remaining + 1);
            if ($bytes !== false && strlen($bytes) > $remaining) throw new RuntimeException('RESEARCH_TRANSPORT_SOURCE_SIZE_INVALID');
            $totalBytes += $bytes === false ? 0 : strlen($bytes);
            if ($bytes === false || ! hash_equals($record['sha256'], hash('sha256', $bytes))) throw new RuntimeException('RESEARCH_TRANSPORT_SOURCE_HASH_MISMATCH:'.$stream);
            $handle = fopen('php://temp', 'w+'); fwrite($handle, $bytes); unset($bytes); rewind($handle);
            try {
                $header = fgetcsv($handle, escape: '');
                $timeIndex = is_array($header) ? array_search('time', $header, true) : false;
                if ($timeIndex === false) throw new RuntimeException('RESEARCH_TRANSPORT_TIME_MISSING:'.$stream);
                $rows = 0; $first = null; $last = null;
                $start = CarbonImmutable::parse($window['start_inclusive'])->utc();
                $end = CarbonImmutable::parse($window['end_exclusive'])->utc();
                while (($row = fgetcsv($handle, escape: '')) !== false) {
                    if (! is_string($row[$timeIndex] ?? null) || trim($row[$timeIndex]) === '') throw new RuntimeException('RESEARCH_TRANSPORT_TIME_INVALID:'.$stream);
                    $time = CarbonImmutable::parse($row[$timeIndex], 'UTC')->utc();
                    if ($time->lessThan($start) || ! $time->lessThan($end) || $time->greaterThan(now())
                        || ($last !== null && ! $time->greaterThan($last))) throw new RuntimeException('RESEARCH_TRANSPORT_TIME_SCOPE_INVALID:'.$stream);
                    $first ??= $time; $last = $time;
                    if (++$rows > 2000000) throw new RuntimeException('RESEARCH_TRANSPORT_ROW_BUDGET_INVALID');
                }
                if ($rows < 2) throw new RuntimeException('RESEARCH_TRANSPORT_SOURCE_EMPTY:'.$stream);
                $files[$stream] = ['path' => str_replace('\\', '/', $actual), 'sha256' => $record['sha256'],
                    'rows' => $rows, 'start_inclusive' => $first->toIso8601String(), 'last_candle_at' => $last->toIso8601String()];
            } finally { fclose($handle); }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private function transportPath(string $path): string
    {
        if (str_contains(str_replace('\\', '/', $path), '/../') || ! str_ends_with(strtolower($path), '.csv')) {
            throw new RuntimeException('RESEARCH_TRANSPORT_SOURCE_PATH_INVALID');
        }
        $resolved = realpath($path) ?: realpath(dirname(base_path()).DIRECTORY_SEPARATOR.$path);
        if ($resolved === false || ! is_file($resolved) || is_link($resolved)) throw new RuntimeException('RESEARCH_TRANSPORT_SOURCE_PATH_INVALID');
        foreach ([storage_path('app/lab-datasets'), dirname(base_path()).DIRECTORY_SEPARATOR.'datasets'] as $root) {
            $root = realpath($root);
            if ($root !== false && str_starts_with(strtolower($resolved), strtolower($root).DIRECTORY_SEPARATOR)) return $resolved;
        }
        throw new RuntimeException('RESEARCH_TRANSPORT_SOURCE_PATH_OUTSIDE_DATA_ROOT');
    }

    private function transportJson(mixed $value): string
    {
        $ordered = function (mixed $item) use (&$ordered): mixed {
            if (! is_array($item)) return $item;
            if (! array_is_list($item)) ksort($item, SORT_STRING);
            return array_map($ordered, $item);
        };
        return json_encode($ordered($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Data availability is a separate dependency, never a strategy verdict. */
    public function readiness(): array
    {
        $eligible = []; $future = [];
        foreach ((array) config('services.instrument_policy.authorized_research_windows', []) as $manifest) {
            if (! is_array($manifest)) continue;
            $receipt = $this->receipt($manifest);
            if ($receipt === null) continue;
            if (CarbonImmutable::parse($receipt['end_exclusive'])->greaterThan(now())) $future[] = $receipt;
            else $eligible[] = $receipt;
        }
        return ['protocol' => self::PROTOCOL, 'status' => $eligible === [] ? 'awaiting_authorized_research_data' : 'authorized_windows_available',
            'eligible_windows' => $eligible, 'future_windows' => $future,
            'reason_code' => $eligible === [] ? 'NO_COMPLETED_AUTHORIZED_INDEPENDENT_WINDOW' : null,
            'paper_2026_eligible' => false, 'historical_relabeling_eligible' => false,
            'promotion_evidence' => false];
    }

    /** @return array<string,string>|null */
    public function seal(string $authorizationId, string $dataHash): ?array
    {
        $matches = array_values(array_filter(
            (array) config('services.instrument_policy.authorized_research_windows', []),
            static fn (mixed $manifest): bool => is_array($manifest)
                && (string) ($manifest['authorization_id'] ?? '') === $authorizationId,
        ));
        if ($authorizationId === '' || count($matches) !== 1) {
            return null;
        }
        $receipt = $this->receipt($matches[0]);

        return $receipt !== null
            && hash_equals((string) $receipt['dataset_sha256'], strtolower($dataHash))
            && CarbonImmutable::parse($receipt['end_exclusive'])->lessThanOrEqualTo(now())
                ? $receipt : null;
    }

    /** The replay manifest must attest the window's actual data chronology. */
    public function sealForDataset(string $dataHash, array $replayManifest): ?array
    {
        if (data_get($replayManifest, 'data_partition.screening_source')
                !== 'authorized_post_paper_research_validation'
            || ! filled(data_get($replayManifest, 'instrument_research_window.authorization_id'))
            || ! filled(data_get($replayManifest, 'instrument_research_window.research_epoch_id'))
            || ! filled(data_get($replayManifest, 'first_candle_at'))
            || ! filled(data_get($replayManifest, 'last_candle_at'))) {
            return null;
        }
        try {
            $first = CarbonImmutable::parse((string) $replayManifest['first_candle_at'], 'UTC')->utc();
            $last = CarbonImmutable::parse((string) $replayManifest['last_candle_at'], 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
        if ($last->lessThan($first)) {
            return null;
        }
        $matches = [];
        foreach ((array) config('services.instrument_policy.authorized_research_windows', []) as $manifest) {
            if (! is_array($manifest)) {
                continue;
            }
            $receipt = $this->seal((string) ($manifest['authorization_id'] ?? ''), $dataHash);
            if ($receipt !== null
                && (string) data_get($replayManifest, 'instrument_research_window.authorization_id') === $receipt['authorization_id']
                && (string) data_get($replayManifest, 'instrument_research_window.research_epoch_id') === $receipt['research_epoch_id']
                && ! $first->lessThan(CarbonImmutable::parse($receipt['start_inclusive']))
                && $last->lessThan(CarbonImmutable::parse($receipt['end_exclusive']))) {
                $matches[$receipt['window_key']] = $receipt;
            }
        }

        // Ambiguous configuration must never guess which chronology was used.
        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    /** Recheck an issued receipt against server authorization at evidence-write time. */
    public function authorized(array $receipt, string $dataHash): bool
    {
        $sealed = $this->seal((string) ($receipt['authorization_id'] ?? ''), $dataHash);

        return $sealed !== null && $this->sameReceipt($sealed, $receipt);
    }

    /**
     * Re-derive window identity and chronological non-overlap from persisted
     * observations. Window names alone never establish independence.
     *
     * @return array{valid:bool,windows:int,positive_windows:int,negative_windows:int,positive_observations:int,negative_observations:int,evidence_keys:list<string>}
     */
    public function analyze(array $observations): array
    {
        $invalid = ['valid' => false, 'windows' => 0, 'positive_windows' => 0,
            'negative_windows' => 0, 'positive_observations' => 0,
            'negative_observations' => 0, 'evidence_keys' => []];
        if ($observations === []) {
            return $invalid;
        }

        $windows = [];
        $evidenceKeys = [];
        $positiveObservations = 0;
        $negativeObservations = 0;
        foreach ($observations as $observation) {
            if (! is_array($observation)) {
                return $invalid;
            }
            $receipt = (array) ($observation['window'] ?? []);
            $key = (string) ($receipt['window_key'] ?? '');
            $evidenceKey = (string) ($observation['evidence_key'] ?? '');
            $outcome = (string) ($observation['outcome'] ?? '');
            if (! $this->validReceipt($receipt) || $evidenceKey === ''
                || isset($evidenceKeys[$evidenceKey])
                || ! in_array($outcome, ['positive', 'negative', 'neutral'], true)) {
                return $invalid;
            }
            $evidenceKeys[$evidenceKey] = true;
            if (isset($windows[$key]) && ! $this->sameReceipt($windows[$key]['receipt'], $receipt)) {
                return $invalid;
            }
            $windows[$key] ??= ['receipt' => $receipt, 'positive' => false, 'negative' => false];
            if ($outcome !== 'neutral') {
                $windows[$key][$outcome] = true;
                if ($outcome === 'positive') {
                    $positiveObservations++;
                } else {
                    $negativeObservations++;
                }
            }
        }

        $ordered = array_values($windows);
        usort($ordered, static fn (array $a, array $b): int => strcmp(
            $a['receipt']['start_inclusive'], $b['receipt']['start_inclusive'],
        ));
        $datasetHashes = [];
        foreach ($ordered as $row) {
            $hash = $row['receipt']['dataset_sha256'];
            if (isset($datasetHashes[$hash])) {
                return $invalid; // One dataset replayed under new labels is not replication.
            }
            $datasetHashes[$hash] = true;
        }
        for ($index = 1; $index < count($ordered); $index++) {
            if ($ordered[$index - 1]['receipt']['end_exclusive'] > $ordered[$index]['receipt']['start_inclusive']) {
                return $invalid;
            }
        }

        return [
            'valid' => true,
            'windows' => count($ordered),
            'positive_windows' => count(array_filter($ordered, static fn (array $row): bool => $row['positive'])),
            'negative_windows' => count(array_filter($ordered, static fn (array $row): bool => $row['negative'])),
            'positive_observations' => $positiveObservations,
            'negative_observations' => $negativeObservations,
            'evidence_keys' => array_keys($evidenceKeys),
        ];
    }

    /** @return array<string,string>|null */
    private function receipt(array $manifest): ?array
    {
        $id = (string) ($manifest['authorization_id'] ?? '');
        $epoch = (string) ($manifest['research_epoch_id'] ?? '');
        $hash = strtolower((string) ($manifest['dataset_sha256'] ?? ''));
        if ($id === '' || $epoch === '' || ! preg_match('/^[a-f0-9]{64}$/', $hash)
            || ($manifest['purpose'] ?? null) !== 'instrument_independent_validation') {
            return null;
        }
        try {
            $start = CarbonImmutable::parse((string) ($manifest['start_inclusive'] ?? ''), 'UTC')->utc();
            $end = CarbonImmutable::parse((string) ($manifest['end_exclusive'] ?? ''), 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
        $paperEnd = CarbonImmutable::parse((string) data_get(
            app(ResearchPaperEpochContractService::class)->contract(), 'paper_epoch.end_exclusive', ''
        ), 'UTC')->utc();
        if ($start->lessThan($paperEnd) || ! $end->greaterThan($start)
            || ! app(ResearchPaperEpochContractService::class)->researchIntervalDisjointFromPaper(
                $start->toIso8601String(), $end->toIso8601String(),
            )) {
            return null;
        }
        $identity = [
            'protocol' => self::PROTOCOL,
            'authorization_id' => $id,
            'research_epoch_id' => $epoch,
            'start_inclusive' => $start->toIso8601String(),
            'end_exclusive' => $end->toIso8601String(),
            'dataset_sha256' => $hash,
        ];

        return [...$identity, 'window_key' => hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES))];
    }

    private function validReceipt(array $receipt): bool
    {
        if (count($receipt) !== 7 || ! isset($receipt['window_key'])) {
            return false;
        }
        $canonical = $this->receipt([
            ...$receipt, 'purpose' => 'instrument_independent_validation',
        ]);

        return $canonical !== null && $this->sameReceipt($canonical, $receipt)
            && CarbonImmutable::parse($receipt['end_exclusive'])->lessThanOrEqualTo(now());
    }

    private function sameReceipt(array $expected, array $actual): bool
    {
        if (count($expected) !== count($actual)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual) || $actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
