<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** A single bounded pass over request references. This is exclusion evidence, never unused-data authority. */
class ResearchRequestReferenceInventoryService
{
    public function inventory(int $highWater, ?array $candidate): array
    {
        $phases = []; $groups = []; $inputs = []; $summary = []; $missing = [];
        DB::table('lab_evaluation_runs')->where('id', '<=', $highWater)
            ->select(['id', 'phase', 'request_hash', 'data_hash', 'request_meta'])
            ->chunkById(32, function ($rows) use (&$phases, &$groups, &$inputs, &$summary, &$missing): void {
                foreach ($rows as $row) {
                    $meta = json_decode((string) $row->request_meta, true);
                    if (! is_array($meta)) {
                        $meta = []; $missing[] = 'RESEARCH_INPUT_REFERENCE_METADATA_INVALID';
                    }
                    $get = fn ($key) => data_get($meta, $key);
                    $first = $get('dataset_manifest.mtf_bundle_manifest.streams.M5.first_candle_at')
                        ?? $get('dataset_manifest.mtf_bundle_manifest.entry_first_candle_at') ?? $get('dataset_manifest.first_candle_at');
                    $last = $get('dataset_manifest.mtf_bundle_manifest.streams.M5.last_candle_at')
                        ?? $get('dataset_manifest.mtf_bundle_manifest.entry_last_candle_at') ?? $get('dataset_manifest.last_candle_at');
                    $symbol = $get('payload.symbol') ?? $get('dataset_manifest.symbol');
                    $phase = (string) $row->phase;
                    $phases[$phase] ??= ['phase' => $row->phase, 'total' => 0, 'first_run_id' => (int) $row->id,
                        'last_run_id' => (int) $row->id, 'incomplete_chronology_or_request' => 0, 'unknown_market_identity' => 0];
                    $phases[$phase]['total']++;
                    $phases[$phase]['last_run_id'] = (int) $row->id;
                    $phases[$phase]['incomplete_chronology_or_request'] += (int) ($first === null || $last === null || $row->request_hash === null);
                    $phases[$phase]['unknown_market_identity'] += (int) ($symbol === null);
                    if ($row->request_hash !== null && $first !== null && $last !== null) {
                        $key = json_encode([$phase, $symbol, $first, $last]);
                        $this->aggregate($groups, $key, ['phase' => $row->phase, 'source_symbol' => $symbol,
                            'first_candle_at' => $first, 'last_candle_at' => $last, 'example_run_id' => (int) $row->id,
                            'example_request_hash' => $row->request_hash, 'example_data_hash' => $row->data_hash,
                            'example_source_dataset_sha256' => $get('dataset_manifest.mtf_bundle_manifest.streams.M5.sha256')
                                ?? $get('dataset_manifest.sha256') ?? $get('dataset_manifest.snapshot_sha256')],
                            $missing, 'EXPOSURE_RANGE_OUTPUT_BOUND_REACHED');
                    }
                    foreach ($this->references($meta, $symbol) as $reference) {
                        $summaryKey = $reference['reference_kind'].'|'.$reference['stream'];
                        $summary[$summaryKey] ??= ['reference_kind' => $reference['reference_kind'], 'stream' => $reference['stream'],
                            'referenced_runs' => 0, 'incomplete_reference' => 0];
                        $summary[$summaryKey]['referenced_runs']++;
                        $summary[$summaryKey]['incomplete_reference'] += (int) ($reference['first_candle_at'] === null
                            || $reference['last_candle_at'] === null || $reference['source_sha256'] === null || $reference['source_symbol'] === null);
                        $key = json_encode([$phase, $reference]);
                        $this->aggregate($inputs, $key, ['phase' => $row->phase, ...$reference, 'example_run_id' => (int) $row->id],
                            $missing, 'INPUT_REFERENCE_RANGE_OUTPUT_BOUND_REACHED');
                    }
                }
            });
        ksort($phases); ksort($summary);
        if (array_sum(array_column($phases, 'incomplete_chronology_or_request')) > 0) $missing[] = 'LEGACY_RESEARCH_EXPOSURE_CHRONOLOGY_INCOMPLETE';
        if (array_sum(array_column($phases, 'unknown_market_identity')) > 0) $missing[] = 'RESEARCH_MARKET_IDENTITY_INCOMPLETE';
        if (array_sum(array_column($summary, 'incomplete_reference')) > 0) $missing[] = 'RESEARCH_INPUT_REFERENCE_CHRONOLOGY_OR_IDENTITY_INCOMPLETE';
        $ranges = []; $inputRanges = [];
        foreach (array_slice(array_values($groups), 0, 100) as $group) {
            $from = $this->utc($group['first_candle_at']); $through = $this->utc($group['last_candle_at']);
            if ($from === null || $through === null || $through->lessThan($from)) {
                $missing[] = 'EXPOSURE_CHRONOLOGY_UNASSESSABLE'; continue;
            }
            $ranges[] = [...$group, 'first_candle_at' => $from->toIso8601String(), 'last_candle_at' => $through->toIso8601String(),
                'original_receipt_bytes_revalidated' => false, 'candidate_physical_time_overlap' => $candidate !== null
                    && $group['source_symbol'] === 'XAUUSD' && $from->lessThan($candidate['end']) && $through->greaterThanOrEqualTo($candidate['start'])];
        }
        usort($inputs, fn ($a, $b) => [$a['example_run_id'], $a['reference_kind'], $a['stream']] <=> [$b['example_run_id'], $b['reference_kind'], $b['stream']]);
        foreach (array_slice($inputs, 0, 100) as $group) {
            $from = $this->utc($group['first_candle_at']); $through = $this->utc($group['last_candle_at']);
            $seconds = $group['stream_seconds'] ?? $this->seconds((string) $group['declared_timeframe']);
            if ($from === null || $through === null || $through->lessThan($from) || $seconds === null) {
                $missing[] = 'RESEARCH_INPUT_REFERENCE_SCOPE_UNASSESSABLE'; continue;
            }
            $until = $through->addSeconds($seconds);
            unset($group['stream_seconds'], $group['declared_timeframe']);
            $inputRanges[] = [...$group, 'first_candle_at' => $from->toIso8601String(), 'last_candle_at' => $through->toIso8601String(),
                'referenced_end_exclusive' => $until->toIso8601String(), 'whole_source_including_warmup_referenced' => true,
                'holding_exposure_attested' => false, 'original_receipt_bytes_revalidated' => false,
                'candidate_physical_time_overlap' => $candidate !== null && strtoupper((string) $group['source_symbol']) === 'XAUUSD'
                    && $from->lessThan($candidate['end']) && $until->greaterThan($candidate['start'])];
        }
        return ['phases' => array_values($phases), 'ranges' => $ranges, 'input_ranges' => $inputRanges,
            'input_summary' => array_values($summary), 'missing' => array_values(array_unique($missing))];
    }

    private function aggregate(array &$groups, string $key, array $row, array &$missing, string $bound): void
    {
        if (isset($groups[$key])) { $groups[$key]['referenced_runs']++; return; }
        if (count($groups) >= 100) { $missing[$bound] = $bound; return; }
        $groups[$key] = [...$row, 'referenced_runs' => 1];
    }

    private function references(array $meta, mixed $primarySymbol): array
    {
        $references = [];
        foreach (['dataset_manifest.mtf_bundle_manifest', 'payload.mtf_snapshot_manifest'] as $root) {
            foreach (['M1', 'M5', 'M15', 'M30', 'H1', 'H4', 'D1'] as $stream) {
                foreach ([$stream, 'RELATED_'.$stream] as $key) {
                    $declared = data_get($meta, $root.'.streams.'.$key);
                    if ($declared === null) continue;
                    $references[] = $this->reference(str_replace('.', '->', $root), $key, (array) $declared,
                        str_starts_with($key, 'RELATED_') ? null : $primarySymbol, $this->seconds($stream));
                }
            }
        }
        foreach (['foundation' => 'FOUNDATION', 'regime' => 'REGIME_H1'] as $key => $stream) {
            $declared = data_get($meta, 'dataset_manifest.'.$key);
            if ($declared === null && data_get($meta, 'payload.'.$key.'_dataset_path') === null) continue;
            $references[] = $this->reference('dataset_manifest->'.$key, $stream, (array) $declared, $primarySymbol,
                $stream === 'REGIME_H1' ? 3600 : null);
        }
        foreach (['candles' => 'INLINE_PRIMARY', 'regime_candles' => 'INLINE_REGIME_H1'] as $key => $stream) {
            $declared = data_get($meta, 'payload.'.$key);
            if ((int) data_get($declared, 'row_count', 0) <= 0) continue;
            $references[] = $this->reference('payload->'.$key, $stream, [
                'first_candle_at' => data_get($declared, 'first_row.time') ?? data_get($declared, 'first_row.timestamp'),
                'last_candle_at' => data_get($declared, 'last_row.time') ?? data_get($declared, 'last_row.timestamp'),
                'sha256' => data_get($declared, 'sha256'), 'timeframe' => data_get($meta, 'payload.timeframe')],
                $primarySymbol, $stream === 'INLINE_REGIME_H1' ? 3600 : null);
        }
        return $references;
    }

    private function reference(string $kind, string $stream, array $source, mixed $symbol, ?int $seconds): array
    {
        return ['reference_kind' => $kind, 'stream' => $stream, 'stream_seconds' => $seconds,
            'declared_timeframe' => $source['timeframe'] ?? null, 'source_symbol' => $source['symbol'] ?? $symbol,
            'first_candle_at' => $source['first_candle_at'] ?? null,
            'last_candle_at' => $source['last_candle_at'] ?? $source['last_closed_candle_at'] ?? null,
            'source_sha256' => $source['sha256'] ?? $source['snapshot_sha256'] ?? null];
    }

    private function seconds(string $stream): ?int
    {
        return ['M1' => 60, 'M5' => 300, 'M15' => 900, 'M30' => 1800, 'H1' => 3600, 'H4' => 14400, 'D1' => 86400][strtoupper($stream)] ?? null;
    }

    private function utc(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/D', $value)) return null;
        if (! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
            || (int) substr($value, 11, 2) > 23 || (int) substr($value, 14, 2) > 59 || (int) substr($value, 17, 2) > 59) return null;
        try { return CarbonImmutable::parse($value, 'UTC')->utc(); } catch (\Throwable) { return null; }
    }
}
