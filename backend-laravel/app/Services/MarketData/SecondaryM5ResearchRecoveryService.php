<?php

namespace App\Services\MarketData;

use App\Models\MarketTrainingArchive;
use App\Services\ExecutionContractService;
use App\Services\MultiTimeframeSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Explicit, attributed secondary research. It never repairs a native archive. */
class SecondaryM5ResearchRecoveryService
{
    public const PROTOCOL = 'secondary_m5_research_recovery_v2';
    public const PRICE_BASIS = 'twelve_composite_mid_native_bid_ask_equivalence_unverified';

    public function __construct(private MarketTrainingDataService $training) {}

    /** Reopen provider responses, not a previously assembled secondary CSV. */
    public function build(string $nativeDataset, array $evidenceReceipts, string $budgetBundleHash, bool $apply = false): array
    {
        $owner = app(MultiTimeframeSnapshotService::class);
        $native = $owner->verifiedNativeM5Repair($nativeDataset);
        if ($native === null) throw new RuntimeException('SECONDARY_NATIVE_PARENT_UNVERIFIED');
        $anchor = $this->budgetAnchor($budgetBundleHash, $native);
        $parent = $this->priceRows($native['prospective_m5_source_path'], $native['prospective_m5_source_sha256']);
        $targets = (array) data_get($native, 'calendar_scope.remaining_missing_utc', []);
        if ($targets === [] || count($targets) > 300) throw new RuntimeException('SECONDARY_PARENT_TARGET_SCOPE_INVALID');
        $audit = new Process(['python', '-B', base_path('scripts/audit-frozen-m5-gap-source.py'),
            $native['prospective_m5_source_path'], '--inventory', '--recovered='.implode(',', $targets)]);
        $audit->setTimeout(120); $audit->mustRun();
        $calendar = json_decode($audit->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        if (($calendar['source_csv_sha256'] ?? null) !== $native['prospective_m5_source_sha256']
            || ($calendar['canonical_missing_utc'] ?? null) !== $targets
            || ($calendar['full_source_unexpected_after'] ?? null) !== 0) throw new RuntimeException('SECONDARY_PARENT_CALENDAR_UNVERIFIED');
        [$minutes, $bars, $receipts] = $this->observations($evidenceReceipts);
        $proofs = []; $patches = [];
        foreach ($targets as $iso) {
            $at = CarbonImmutable::parse($iso, 'UTC'); $time = $at->format('Y-m-d H:i:s');
            $observed = [];
            for ($offset = 0; $offset < 5; $offset++) {
                $key = $at->addMinutes($offset)->format('Y-m-d H:i:s');
                if (isset($minutes[$key])) $observed[] = $minutes[$key];
            }
            if ($observed === []) throw new RuntimeException('SECONDARY_ACTUAL_OBSERVATIONS_MISSING:'.$time);
            $aggregate = ['time' => $time, 'open' => $observed[0]['open'],
                'high' => max(array_column($observed, 'high')), 'low' => min(array_column($observed, 'low')),
                'close' => $observed[array_key_last($observed)]['close'], 'volume' => 0];
            $actualM5 = $bars[$time] ?? null;
            if (count($observed) !== 5 && $actualM5 === null) throw new RuntimeException('SECONDARY_PARTIAL_M1_REQUIRES_ACTUAL_M5:'.$time);
            if ($actualM5 !== null) foreach (['open', 'high', 'low', 'close'] as $field) {
                if (abs((float) $actualM5[$field] - (float) $aggregate[$field]) > 0.000001) {
                    throw new RuntimeException('SECONDARY_M1_M5_DISAGREE:'.$time.':'.$field);
                }
            }
            $row = $actualM5 === null ? $aggregate : array_intersect_key($actualM5, array_flip(['time','open','high','low','close','volume']));
            $row['volume'] = 0;
            $hashes = array_values(array_unique([...array_column($observed, 'source_response_sha256'),
                ...($actualM5 === null ? [] : [$actualM5['source_response_sha256']])])); sort($hashes);
            $proofs[] = ['target_utc' => $at->toIso8601String(), 'recovered_row' => $row,
                'observed_m1_minutes' => count($observed), 'complete_observed_m1_membership' => count($observed) === 5,
                'actual_provider_m5_present' => $actualM5 !== null, 'missing_m1_filled' => false,
                'source_provider' => 'twelve', 'price_basis' => self::PRICE_BASIS,
                'source_response_sha256' => $hashes, 'native_bid_ask_equivalence_proven' => false];
            $patches[$time] = $row;
        }
        $rows = [];
        foreach ($parent as $row) {
            if (isset($patches[$row['time']])) throw new RuntimeException('SECONDARY_NATIVE_ROW_REPLACEMENT_FORBIDDEN');
            $rows[$row['time']] = $row;
        }
        foreach ($patches as $time => $row) $rows[$time] = $row;
        ksort($rows); $rows = array_values($rows);
        $csv = \FrozenM5GapRecoveryOperation::csvBytes($rows);
        $attribution = $this->attributionCsv($rows, $proofs);
        $identity = ['protocol' => self::PROTOCOL, 'provider' => 'mixed', 'symbol' => 'XAUUSD', 'timeframe' => 'M5',
            'native_parent_dataset_key' => $nativeDataset, 'native_parent_repair_hash' => $native['repair_hash'],
            'native_parent_price_path' => $native['prospective_m5_source_path'],
            'native_parent_price_sha256' => $native['prospective_m5_source_sha256'],
            'native_parent_economic_rows_sha256' => $native['economic_rows_sha256'],
            'original_bad_m5_sha256' => $native['original_bad_m5_sha256'],
            'source_rows' => count($parent), 'new_rows' => count($rows), 'target_proofs' => $proofs,
            'canonical_missing_utc' => $targets, 'evidence_receipts' => $receipts,
            'new_price_csv_sha256' => hash('sha256', $csv), 'row_attribution_sha256' => hash('sha256', $attribution),
            'economic_row_hash_protocol' => 'training_decimal_6_rows_v1',
            'new_economic_rows_sha256' => \FrozenM5GapRecoveryOperation::economicRowsHash($rows),
            'original_budget_scope_anchor' => $anchor,
            'calendar_scope' => ['protocol' => 'secondary_research_calendar_scope_v1',
                'original_open_scope_unexpected_before' => $anchor['original_full_source_unexpected_gaps'],
                'native_repairs_since_original_scope' => $anchor['original_full_source_unexpected_gaps'] - count($targets),
                'full_source_unexpected_before' => count($targets), 'full_source_unexpected_after' => 0,
                'screening_unexpected_after' => 0, 'remaining_missing_utc' => [],
                'whole_mixed_research_archive_continuity_proven' => true, 'whole_native_archive_continuity_proven' => false],
            'native_rows_replaced' => false, 'synthetic_minutes_created' => false, 'native_equivalence_proven' => false,
            'volume_available' => false, 'quote_liquidity_inherited' => false, 'scientific_question_budget_reset' => false,
            'independent_evidence' => false, 'full_validation_eligible' => false, 'paper_eligible' => false,
            'promotion_evidence' => false, 'runtime_trade_authority' => false];
        $hash = app(ExecutionContractService::class)->hashParameters($identity);
        $receipt = [...$identity, 'repair_hash' => $hash, 'dataset_key' => 'research_mixed_gapfix_'.substr($hash, 0, 16)];
        $directory = storage_path('app/lab-datasets/training/secondary-recovery/'.$hash);
        if ($apply) {
            if (! hash_equals($native['prospective_m5_source_sha256'], (string) hash_file('sha256', $native['prospective_m5_source_path']))) throw new RuntimeException('SECONDARY_NATIVE_SOURCE_DRIFT');
            DB::transaction(function () use ($receipt, $rows, $csv, $attribution, $directory): void {
                $existing = MarketTrainingArchive::query()->where('dataset_key', $receipt['dataset_key'])->where('provider', 'mixed')
                    ->where('symbol', 'XAUUSD')->where('timeframe', 'M5')->lockForUpdate()->first();
                if ($existing) {
                    if (data_get($existing->metrics, 'secondary_m5_research_receipt') !== $receipt
                        || ! is_file($directory.'/m5.csv') || hash_file('sha256', $directory.'/m5.csv') !== $receipt['new_price_csv_sha256']) {
                        throw new RuntimeException('SECONDARY_IMMUTABLE_ARCHIVE_CONFLICT');
                    }
                    return;
                }
                File::ensureDirectoryExists($directory);
                foreach (['m5.csv' => $csv, 'row-attribution.csv' => $attribution,
                    'receipt.json' => json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)] as $name => $bytes) {
                    if (is_file($directory.'/'.$name) && File::get($directory.'/'.$name) !== $bytes) throw new RuntimeException('SECONDARY_IMMUTABLE_FILE_CONFLICT');
                    File::put($directory.'/'.$name, $bytes);
                }
                $archive = $this->training->ensureArchive($receipt['dataset_key'], 'mixed', 'XAUUSD', 'M5',
                    CarbonImmutable::parse($rows[0]['time'], 'UTC'), CarbonImmutable::parse($rows[array_key_last($rows)]['time'], 'UTC')->addMinutes(5));
                $this->training->upsertCandles($receipt['dataset_key'], 'mixed', 'XAUUSD', 'M5', $rows);
                $this->training->refreshCoverage($archive);
                $archive->update(['status' => 'complete', 'metrics' => [...(array) $archive->metrics,
                    'secondary_m5_research_receipt' => $receipt, 'secondary_m5_research_price_path' => $directory.'/m5.csv']]);
            });
        }
        return ['receipt' => $receipt, 'price_path' => $directory.'/m5.csv', 'rows' => $rows, 'applied' => $apply];
    }

    /** Strong reconstruction plus actual SQL/file/attribution checks; no self-sealed trust. */
    public function verify(MarketTrainingArchive $archive): ?array
    {
        try {
            if ($archive->provider !== 'mixed' || $archive->symbol !== 'XAUUSD' || $archive->timeframe !== 'M5' || $archive->status !== 'complete') return null;
            $receipt = (array) data_get($archive->metrics, 'secondary_m5_research_receipt', []);
            if (($receipt['protocol'] ?? null) !== self::PROTOCOL || ($receipt['provider'] ?? null) !== 'mixed') return null;
            $built = $this->build((string) ($receipt['native_parent_dataset_key'] ?? ''), (array) ($receipt['evidence_receipts'] ?? []),
                (string) data_get($receipt, 'original_budget_scope_anchor.original_bundle_hash'));
            if (app(ExecutionContractService::class)->hashParameters($receipt) !== app(ExecutionContractService::class)->hashParameters($built['receipt'])
                || $archive->dataset_key !== $receipt['dataset_key'] || (int) $archive->row_count !== $receipt['new_rows']) return null;
            $path = realpath((string) data_get($archive->metrics, 'secondary_m5_research_price_path'));
            if ($path === false || $path !== realpath($built['price_path'])
                || hash_file('sha256', $path) !== $receipt['new_price_csv_sha256']
                || ! is_file(dirname($path).'/row-attribution.csv')
                || hash_file('sha256', dirname($path).'/row-attribution.csv') !== $receipt['row_attribution_sha256']) return null;
            $digest = hash_init('sha256'); $count = 0; $prior = null;
            foreach ($this->training->query($archive->dataset_key, 'mixed', 'XAUUSD', 'M5')->toBase()->orderBy('time')->cursor() as $row) {
                $time = (string) $row->time;
                if ($time >= '2026-01-01' || ($prior !== null && $time <= $prior) || ++$count > 350300) return null;
                hash_update($digest, json_encode([$time, ...array_map(static fn ($field) => number_format((float) $row->$field, 6, '.', ''),
                    ['open','high','low','close','volume'])])."\n"); $prior = $time;
            }
            if ($count !== $receipt['new_rows'] || hash_final($digest) !== $receipt['new_economic_rows_sha256']) return null;
            return ['protocol' => self::PROTOCOL, 'verified' => true, 'provider' => 'mixed', 'repair_hash' => $receipt['repair_hash'],
                'dataset_key' => $archive->dataset_key, 'original_bad_m5_sha256' => $receipt['original_bad_m5_sha256'],
                'prospective_m5_source_path' => $path, 'prospective_m5_source_sha256' => $receipt['new_price_csv_sha256'],
                'economic_rows_sha256' => $receipt['new_economic_rows_sha256'], 'calendar_scope' => $receipt['calendar_scope'],
                'original_budget_scope_anchor' => $receipt['original_budget_scope_anchor'], 'target_proofs' => $receipt['target_proofs'],
                'row_attribution_sha256' => $receipt['row_attribution_sha256'], 'quote_liquidity_inherited' => false,
                'independent_evidence' => false, 'promotion_evidence' => false];
        } catch (\Throwable) { return null; }
    }

    public function attributeRows(array $rows, array $repair): array
    {
        $patches = []; foreach ($repair['target_proofs'] as $proof) $patches[$proof['recovered_row']['time']] = $proof;
        foreach ($rows as &$row) {
            $proof = $patches[$row['time']] ?? null;
            $row['source_provider'] = $proof === null ? 'dukascopy' : 'twelve';
            $row['price_basis'] = $proof === null ? 'native_bid' : self::PRICE_BASIS;
            $row['source_response_sha256'] = $proof === null ? '' : implode('|', $proof['source_response_sha256']);
            $row['volume_available'] = false;
        }
        unset($row); return $rows;
    }

    private function budgetAnchor(string $bundleHash, array $native): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $bundleHash)) throw new RuntimeException('SECONDARY_ORIGINAL_BUDGET_BUNDLE_INVALID');
        $path = storage_path('app/lab-datasets/mtf/'.$bundleHash.'/manifest.json');
        $manifest = is_file($path) ? json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR) : null;
        if (! is_array($manifest) || ($manifest['bundle_hash'] ?? null) !== $bundleHash
            || ($manifest['provider'] ?? null) !== 'dukascopy'
            || data_get($manifest, 'prospective_m5_repair.original_bad_m5_sha256') !== $native['original_bad_m5_sha256']
            || ! app(MultiTimeframeSnapshotService::class)->discoveryBundleReadiness($manifest)['allowed']) {
            throw new RuntimeException('SECONDARY_ORIGINAL_BUDGET_BUNDLE_UNVERIFIED');
        }
        $scope = $manifest['discovery_scope'];
        return ['protocol' => 'native_discovery_budget_anchor_v1', 'original_bundle_hash' => $bundleHash,
            'original_scope_hash' => $scope['scope_hash'], 'parent_fork_price_sha256' => $scope['parent_fork_price_sha256'],
            'parent_economic_rows_sha256' => $scope['parent_economic_rows_sha256'],
            'original_full_source_unexpected_gaps' => $scope['calendar']['full_source_unexpected_gaps'],
            'scientific_question_budget_reset' => false];
    }

    private function observations(array $references): array
    {
        if ($references === [] || count($references) > 30) throw new RuntimeException('SECONDARY_EVIDENCE_SCOPE_INVALID');
        $minutes = []; $bars = []; $receipts = [];
        foreach ($references as $reference) {
            $path = $this->evidencePath((string) ($reference['path'] ?? ''));
            $hash = (string) ($reference['sha256'] ?? '');
            $receipt = $this->jsonEvidence($path, $hash); $receipts[$path] = ['path' => $path, 'sha256' => $hash];
            foreach ((array) ($receipt['results'] ?? []) as $result) {
                if (isset($result['sanitized_response_path'])) {
                    if (($result['provider'] ?? null) !== 'twelve_data' || ($result['endpoint_without_query'] ?? null) !== 'https://api.twelvedata.com/time_series'
                        || ($result['http_status'] ?? null) !== 200) continue;
                    $sources = [['path' => $result['sanitized_response_path'], 'sha256' => $result['sanitized_response_sha256'] ?? '']];
                    $query = (array) ($result['requested_public_parameters'] ?? []);
                } else {
                    if (! isset($result['request_query']) || ! str_starts_with((string) ($result['source_url'] ?? ''), 'https://api.twelvedata.com/time_series?')) continue;
                    $query = (array) $result['request_query']; $sources = [];
                    foreach ((array) ($result['attempts'] ?? []) as $attempt) if (($attempt['status'] ?? null) === 200 && ($attempt['provider_status'] ?? null) === 'ok') {
                        $sources[] = ['path' => $attempt['raw_path'] ?? '', 'sha256' => $attempt['raw_sha256'] ?? ''];
                    }
                }
                if (($query['symbol'] ?? null) !== 'XAU/USD' || ($query['timezone'] ?? null) !== 'UTC'
                    || ! in_array($query['interval'] ?? null, ['1min','5min'], true)) throw new RuntimeException('SECONDARY_PROVIDER_REQUEST_IDENTITY_INVALID');
                foreach (['start_date', 'end_date'] as $boundary) {
                    if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/D', (string) ($query[$boundary] ?? ''))
                        || $query[$boundary] >= '2026-01-01') throw new RuntimeException('SECONDARY_PROVIDER_REQUEST_BOUNDS_INVALID');
                }
                if ($query['start_date'] > $query['end_date']) throw new RuntimeException('SECONDARY_PROVIDER_REQUEST_BOUNDS_INVALID');
                foreach ($sources as $source) {
                    $payload = $this->jsonEvidence($this->evidencePath((string) $source['path']), (string) $source['sha256']);
                    if (($payload['status'] ?? null) !== 'ok' || data_get($payload, 'meta.symbol') !== 'XAU/USD'
                        || data_get($payload, 'meta.interval') !== $query['interval']
                        || (data_get($payload, 'meta.timezone') !== null && data_get($payload, 'meta.timezone') !== 'UTC')
                        || ! is_array($payload['values'] ?? null)) throw new RuntimeException('SECONDARY_PROVIDER_RESPONSE_IDENTITY_INVALID');
                    foreach ($payload['values'] as $value) {
                        $time = (string) ($value['datetime'] ?? '');
                        if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/D', $time) || $time >= '2026-01-01'
                            || $time < (string) ($query['start_date'] ?? '') || $time > (string) ($query['end_date'] ?? '')) throw new RuntimeException('SECONDARY_PROVIDER_ROW_TIME_INVALID');
                        if ($query['interval'] === '5min' && CarbonImmutable::parse($time, 'UTC')->minute % 5 !== 0) throw new RuntimeException('SECONDARY_PROVIDER_M5_ALIGNMENT_INVALID');
                        $row = ['time' => $time, 'volume' => 0, 'source_response_sha256' => $source['sha256']];
                        foreach (['open','high','low','close'] as $field) {
                            if (! is_numeric($value[$field] ?? null) || ! is_finite((float) $value[$field]) || (float) $value[$field] <= 0) throw new RuntimeException('SECONDARY_PROVIDER_PRICE_INVALID');
                            $row[$field] = (string) $value[$field];
                        }
                        if ((float) $row['low'] > (float) $row['high'] || (float) $row['high'] < max((float) $row['open'], (float) $row['close'])
                            || (float) $row['low'] > min((float) $row['open'], (float) $row['close'])) throw new RuntimeException('SECONDARY_PROVIDER_OHLC_INVALID');
                        if ($query['interval'] === '1min') $destination = &$minutes; else $destination = &$bars;
                        if (isset($destination[$time])) foreach (['open','high','low','close'] as $field) {
                            if (abs((float) $destination[$time][$field] - (float) $row[$field]) > 0.000001) throw new RuntimeException('SECONDARY_PROVIDER_OVERLAP_CONFLICT');
                        }
                        $destination[$time] = $row; unset($destination);
                    }
                }
            }
        }
        ksort($receipts); return [$minutes, $bars, array_values($receipts)];
    }

    private function jsonEvidence(string $path, string $hash): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $hash) || filesize($path) > 8388608 || hash_file('sha256', $path) !== $hash) throw new RuntimeException('SECONDARY_EVIDENCE_HASH_INVALID');
        $bytes = File::get($path);
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) $bytes = substr($bytes, 3);
        $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($value)) throw new RuntimeException('SECONDARY_EVIDENCE_JSON_INVALID'); return $value;
    }

    private function evidencePath(string $path): string
    {
        $resolved = realpath($path); $root = realpath(dirname(base_path()));
        if ($resolved === false || $root === false || ! str_starts_with(str_replace('\\','/', $resolved), str_replace('\\','/', $root).'/')
            || ! is_file($resolved) || strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'json') throw new RuntimeException('SECONDARY_EVIDENCE_PATH_INVALID');
        return $resolved;
    }

    private function priceRows(string $path, string $hash): array
    {
        return \FrozenM5GapRecoveryOperation::source($path, $hash, true);
    }

    private function attributionCsv(array $rows, array $proofs): string
    {
        $handle = fopen('php://temp/maxmemory:1048576', 'w+b');
        fputcsv($handle, ['time','source_provider','price_basis','source_response_sha256','volume_available']);
        foreach ($this->attributeRows($rows, ['target_proofs' => $proofs]) as $row) fputcsv($handle,
            [$row['time'], $row['source_provider'], $row['price_basis'], $row['source_response_sha256'], 0]);
        rewind($handle); $bytes = stream_get_contents($handle); fclose($handle); return $bytes;
    }
}
