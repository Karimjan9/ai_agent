<?php

namespace App\Services;

use App\Models\MarketTrainingArchive;
use App\Services\MarketData\HistoricalQuoteSpreadService;
use App\Services\MarketData\MarketTrainingDataService;
use App\Services\MarketData\MarketVolumeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Freezes the complete H4/H1/M15/M5 research input as one immutable bundle.
 *
 * M5 is the execution stream. H4 is deterministically aggregated from the
 * same frozen H1 source, because the current provider does not publish an H4
 * stream. Every resulting row remains an OHLCV observation, not order-book
 * evidence.
 */
class MultiTimeframeSnapshotService
{
    public const PROTOCOL = 'closed_h4_h1_m15_m5_snapshot_v1';

    public const RESEARCH_MAX_M5_ROWS = 10000;

    /** Scout first; spend more history only on a promising underpowered prior. */
    public const RESEARCH_EVIDENCE_BUDGETS = [10000, 20000, 40000];

    /** Two-year sealed holdout plus nine sampled folds, still below full history. */
    public const AGENT_VALIDATION_MAX_M5_ROWS = 200000;

    /** Expand temporal breadth once; never relax the strategy to manufacture activity. */
    public const AGENT_VALIDATION_EVIDENCE_BUDGETS = [200000, 350000];

    public const AGENT_VALIDATION_MIN_M5_ROWS = 10000;

    private const DURATIONS = ['M5' => 5, 'M15' => 15, 'H1' => 60, 'H4' => 240, 'D1' => 1440];

    public function __construct(
        private LabDatasetExportService $datasets,
        private MarketTrainingDataService $training,
    ) {}

    /**
     * Cheap admission check for the model-owned MTF lane.
     *
     * The live Candle table starts M5 in 2026 and therefore has no training
     * rows.  This lane is intentionally tied to the isolated pre-2026
     * training store.  Checking it before queue dispatch prevents an empty
     * live export from entering a fail/retry CPU loop.
     *
     * @return array<string,mixed>
     */
    public function agentValidationReadiness(
        string $symbol,
        int $maxM5Rows = self::AGENT_VALIDATION_MAX_M5_ROWS,
    ): array {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
        if ($symbol !== 'XAUUSD') {
            return ['ready' => false, 'reason' => 'SYMBOL_NOT_SUPPORTED'];
        }
        if (! in_array($maxM5Rows, self::AGENT_VALIDATION_EVIDENCE_BUDGETS, true)) {
            return ['ready' => false, 'reason' => 'UNSEALED_EVIDENCE_BUDGET'];
        }

        $requirements = [
            ['dataset' => (string) config('services.xauusd_organism.research_m5_dataset', 'foundation_intraday_10y'), 'timeframe' => 'M5', 'minimum' => self::AGENT_VALIDATION_MIN_M5_ROWS],
            ['dataset' => MarketTrainingDataService::DEFAULT_DATASET, 'timeframe' => 'M15', 'minimum' => 1000],
            ['dataset' => MarketTrainingDataService::DEFAULT_DATASET, 'timeframe' => 'H1', 'minimum' => 500],
        ];
        $streams = [];
        $repairProvenance = null;
        foreach ($requirements as $requirement) {
            $archive = MarketTrainingArchive::query()
                ->where('dataset_key', $requirement['dataset'])
                ->where('provider', MarketTrainingDataService::DEFAULT_PROVIDER)
                ->where('symbol', $symbol)
                ->where('timeframe', $requirement['timeframe'])
                ->first();
            if ($requirement['timeframe'] === 'M5' && $requirement['dataset'] !== 'foundation_intraday_10y') {
                $repairProvenance = $this->verifiedProspectiveM5Repair($archive);
                if ($repairProvenance === null) return ['ready' => false, 'reason' => 'PROSPECTIVE_M5_REPAIR_PROVENANCE_INVALID', 'streams' => $streams];
                $fullGaps = data_get($repairProvenance, 'calendar_scope.full_source_unexpected_after');
                if (! is_int($fullGaps) || $fullGaps !== 0) return ['ready' => false,
                    'reason' => 'HISTORICAL_M5_CONTINUITY_SCOPE_UNRESOLVED', 'streams' => $streams,
                    'prospective_m5_repair' => $repairProvenance,
                    'selected_screening_unexpected_gaps' => data_get($repairProvenance, 'calendar_scope.screening_unexpected_after'),
                    'full_source_unexpected_gaps' => $fullGaps, 'promotion_evidence' => false];
            }
            $rows = (int) ($archive?->row_count ?? 0);
            $streams[$requirement['timeframe']] = [
                'dataset_key' => $requirement['dataset'],
                'row_count' => $rows,
                'status' => $archive?->status,
                'first_candle_at' => $archive?->first_candle_at?->utc()->toIso8601String(),
                'last_candle_at' => $archive?->last_candle_at?->utc()->toIso8601String(),
                'minimum_rows' => $requirement['minimum'],
            ];
            if ($rows < $requirement['minimum'] || ! $archive?->last_candle_at) {
                return [
                    'ready' => false,
                    'reason' => 'FOUNDATION_STREAM_UNDERPOWERED',
                    'blocked_timeframe' => $requirement['timeframe'],
                    'streams' => $streams,
                ];
            }
        }

        $m5Last = CarbonImmutable::parse((string) data_get($streams, 'M5.last_candle_at'), 'UTC');
        foreach (['M15', 'H1'] as $timeframe) {
            if (CarbonImmutable::parse((string) data_get($streams, "{$timeframe}.last_candle_at"), 'UTC')
                ->lessThan($m5Last)) {
                return [
                    'ready' => false,
                    'reason' => 'CONTEXT_DOES_NOT_COVER_ENTRY_CUTOFF',
                    'blocked_timeframe' => $timeframe,
                    'streams' => $streams,
                ];
            }
        }

        return [
            'ready' => true,
            'reason' => 'SEALED_FOUNDATION_READY',
            'streams' => $streams,
            'entry_cutoff' => $m5Last->toIso8601String(),
            'requested_m5_rows' => $maxM5Rows,
            'bounded_m5_rows' => min((int) data_get($streams, 'M5.row_count'), $maxM5Rows),
            'promotion_evidence' => false,
            'prospective_m5_repair' => $repairProvenance,
        ];
    }

    /** A config label alone cannot waive a refused immutable data source. */
    private function verifiedProspectiveM5Repair(?MarketTrainingArchive $archive): ?array
    {
        if (! $archive || $archive->status !== 'complete') return null;
        $receipt = (array) data_get($archive->metrics, 'frozen_m5_gap_recovery_receipt', []);
        $hash = (string) ($receipt['repair_hash'] ?? '');
        $identity = array_diff_key($receipt, array_flip(['repair_hash', 'dataset_key']));
        $batch = ($receipt['protocol'] ?? null) === 'frozen_m5_gap_recovery_v2';
        $proofs = (array) ($receipt['target_proofs'] ?? []);
        $addedRows = $batch ? count($proofs) : 1;
        if (! in_array($receipt['protocol'] ?? null, ['frozen_m5_gap_recovery_v1','frozen_m5_gap_recovery_v2'], true) || ! preg_match('/^[a-f0-9]{64}$/', $hash)
            || ! hash_equals($hash, app(ExecutionContractService::class)->hashParameters($identity))
            || $archive->dataset_key !== 'foundation_intraday_gapfix_'.substr($hash, 0, 16)
            || ($receipt['dataset_key'] ?? null) !== $archive->dataset_key || ($receipt['symbol'] ?? null) !== 'XAUUSD'
            || ($receipt['timeframe'] ?? null) !== 'M5' || ($receipt['provider'] ?? null) !== 'dukascopy'
            || ($receipt['economic_row_hash_protocol'] ?? null) !== 'training_decimal_6_rows_v1'
            || $addedRows < 1 || $addedRows > 300 || (int) ($receipt['source_rows'] ?? 0) + $addedRows !== (int) ($receipt['new_rows'] ?? 0)
            || (int) ($receipt['new_rows'] ?? 0) !== (int) $archive->row_count || (int) $archive->row_count > 350300
            || ($receipt['independent_evidence'] ?? null) !== false || ($receipt['promotion_evidence'] ?? null) !== false
            || ($receipt['runtime_trade_authority'] ?? null) !== false || ($receipt['quote_liquidity_inherited'] ?? null) !== false
            || (! $batch && (data_get($receipt, 'proof.observed_m1_minutes') !== 4 || data_get($receipt, 'proof.expected_clock_minutes') !== 5
            || data_get($receipt, 'proof.complete_minute_coverage') !== false || data_get($receipt, 'proof.unobserved_minute_filled') !== false
            || data_get($receipt, 'proof.actual_tick_count') !== 137
            || data_get($receipt, 'proof.source_tick_hour_sha256') !== 'ecccee8e84d4bcd2ebb56ff0cf3f4db5cd397bbe6dc41547bc91503b7cef327e'
            || data_get($receipt, 'proof.recovered_row.time') !== '2025-12-17 23:00:00'))
            || data_get($receipt, 'calendar_scope.protocol') !== ($batch ? 'frozen_source_calendar_scope_audit_v2' : 'frozen_source_calendar_scope_audit_v1')
            || data_get($receipt, 'calendar_scope.source_csv_sha256') !== ($receipt['source_csv_sha256'] ?? null)
            || data_get($receipt, 'calendar_scope.screening_unexpected_after') !== 0) return null;
        $source = realpath((string) ($receipt['source_csv_path'] ?? ''));
        $sourceRoot = realpath(storage_path('app/lab-datasets/mtf'));
        $price = realpath((string) data_get($archive->metrics, 'frozen_m5_gap_recovery_price_path'));
        $expectedPrice = realpath(storage_path('app/lab-datasets/training/recovery/'.$hash.'/m5.csv'));
        if ($source === false || $sourceRoot === false || ! str_starts_with(str_replace('\\', '/', $source), str_replace('\\', '/', $sourceRoot).'/')
            || $price === false || $expectedPrice === false || $price !== $expectedPrice || $price === $source
            || filesize($source) > 134217728 || filesize($price) > 134217728
            || ! hash_equals((string) ($receipt['source_csv_sha256'] ?? ''), (string) hash_file('sha256', $source))
            || ! hash_equals((string) ($receipt['new_price_csv_sha256'] ?? ''), (string) hash_file('sha256', $price))) return null;
        if ($batch) {
            try {
                if (! class_exists(\FrozenM5GapRecoveryOperation::class, false)) require_once base_path('scripts/recover-frozen-m5-gap.php');
                $original = \FrozenM5GapRecoveryOperation::source($source, $receipt['source_csv_sha256'], true);
                $fork = \FrozenM5GapRecoveryOperation::forkMany($original, $proofs, (array) ($receipt['canonical_missing_utc'] ?? []));
                $targets = array_map(static fn ($proof) => str_replace(' ', 'T', $proof['recovered_row']['time']).'+00:00', $proofs);
                $missing = (array) ($receipt['canonical_missing_utc'] ?? []);
                $remaining = array_values(array_diff($missing, $targets));
                $calendar = (array) $receipt['calendar_scope'];
                $unresolved = (array) ($receipt['unresolved_targets'] ?? []);
                if (count($original) !== (int) $receipt['source_rows'] || $original[0]['time'] !== ($receipt['source_first_at'] ?? null)
                    || $original[array_key_last($original)]['time'] !== ($receipt['source_last_at'] ?? null)
                    || $calendar['source_rows'] !== count($original) || $calendar['new_rows'] !== count($fork)
                    || ($calendar['canonical_missing_utc'] ?? null) !== $missing || ($calendar['recovered_targets_utc'] ?? null) !== $targets
                    || ($calendar['remaining_missing_utc'] ?? null) !== $remaining
                    || ($calendar['full_source_unexpected_before'] ?? null) !== count($missing)
                    || ($calendar['full_source_unexpected_after'] ?? null) !== count($remaining)
                    || ($calendar['whole_archive_continuity_proven'] ?? null) !== (count($remaining) === 0)
                    || array_column($unresolved, 'target_utc') !== $remaining
                    || ($receipt['collection_ceiling_seconds'] ?? null) !== 1800
                    || ! hash_equals($receipt['new_price_csv_sha256'], hash('sha256', \FrozenM5GapRecoveryOperation::csvBytes($fork)))
                    || ! hash_equals($receipt['new_economic_rows_sha256'], \FrozenM5GapRecoveryOperation::economicRowsHash($fork))) return null;
                foreach ($unresolved as $dependency) if (! is_string($dependency['reason'] ?? null) || ! str_starts_with($dependency['reason'], 'RECOVERY_')) return null;
            } catch (\Throwable) { return null; }
        }
        $digest = hash_init('sha256'); $rows = 0; $prior = null;
        // Plain bounded cursor avoids hydrating 200k Eloquent/Carbon objects in readiness.
        foreach ($this->training->query($archive->dataset_key, 'dukascopy', 'XAUUSD', 'M5')->toBase()->orderBy('time')->cursor() as $row) {
            $time = (string) $row->time;
            if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', $time)
                || $time >= '2026-01-01 00:00:00' || ($prior !== null && $time <= $prior) || ++$rows > 350001) return null;
            hash_update($digest, json_encode([$time, number_format((float) $row->open, 6, '.', ''), number_format((float) $row->high, 6, '.', ''),
                number_format((float) $row->low, 6, '.', ''), number_format((float) $row->close, 6, '.', ''), number_format((float) $row->volume, 6, '.', '')])."\n");
            $prior = $time;
        }
        if ($rows !== (int) $archive->row_count || ! hash_equals((string) ($receipt['new_economic_rows_sha256'] ?? ''), hash_final($digest))) return null;
        return ['protocol' => $receipt['protocol'], 'verified' => true, 'repair_hash' => $hash,
            'dataset_key' => $archive->dataset_key, 'original_bad_m5_sha256' => $receipt['source_csv_sha256'],
            'prospective_m5_source_sha256' => $receipt['new_price_csv_sha256'], 'prospective_m5_source_path' => $price,
            'economic_rows_sha256' => $receipt['new_economic_rows_sha256'], 'quote_liquidity_inherited' => false,
            'calendar_scope' => $receipt['calendar_scope'],
            'independent_evidence' => false, 'promotion_evidence' => false];
    }

    /**
     * Freeze a bounded, content-addressed pre-2026 bundle for nine-fold
     * model-owned confirmation.  No rolling/paper CSV is exported here.
     *
     * @return array<string,mixed>
     */
    public function forAgentOwnedConfirmationValidation(
        string $symbol,
        int $maxM5Rows = self::AGENT_VALIDATION_MAX_M5_ROWS,
    ): array {
        $readiness = $this->agentValidationReadiness($symbol, $maxM5Rows);
        if (! (bool) ($readiness['ready'] ?? false)) {
            throw new RuntimeException('MTF agent validation foundation is not ready: '.(string) ($readiness['reason'] ?? 'UNKNOWN'));
        }
        $symbol = 'XAUUSD';
        $provider = MarketTrainingDataService::DEFAULT_PROVIDER;
        $m5Dataset = (string) data_get($readiness, 'streams.M5.dataset_key');
        $contextDataset = MarketTrainingDataService::DEFAULT_DATASET;
        $entryCutoff = CarbonImmutable::parse((string) $readiness['entry_cutoff'], 'UTC');
        $exclusiveCutoff = $entryCutoff->addMinutes(self::DURATIONS['M5']);
        $m5Rows = $this->training->candlesForAgent(
            $m5Dataset,
            $provider,
            $symbol,
            'M5',
            null,
            $exclusiveCutoff,
            $maxM5Rows,
        );
        if (count($m5Rows) < self::AGENT_VALIDATION_MIN_M5_ROWS) {
            throw new RuntimeException('MTF agent validation M5 foundation became underpowered during freeze.');
        }

        // Give every first entry fold ample closed higher-timeframe warm-up;
        // no context row after the frozen M5 cutoff can enter the bundle.
        $contextFrom = CarbonImmutable::parse((string) $m5Rows[0]['time'], 'UTC')->subDays(90);
        $streams = ['M5' => $m5Rows];
        foreach (['M15', 'H1'] as $timeframe) {
            $rows = $this->training->candlesForAgent(
                $contextDataset,
                $provider,
                $symbol,
                $timeframe,
                $contextFrom,
                $exclusiveCutoff,
            );
            $streams[$timeframe] = $this->closedRows($rows, $timeframe, $exclusiveCutoff);
        }
        $streams['H4'] = $this->aggregateH4($streams['H1']);
        foreach (['M5' => self::AGENT_VALIDATION_MIN_M5_ROWS, 'M15' => 1000, 'H1' => 500, 'H4' => 100] as $timeframe => $minimum) {
            if (count($streams[$timeframe]) < $minimum) {
                throw new RuntimeException("MTF agent validation {$timeframe} snapshot candle yetarli emas: ".count($streams[$timeframe]));
            }
        }

        // The rolling/live volume audit is intentionally not consulted here.
        // This receipt is scoped to the exact pre-2026 rows that will be
        // frozen below, and the marker is written into those CSVs themselves.
        $volumeAttestation = $this->attestHistoricalVolumeStreams($streams, $provider);
        $streams = $volumeAttestation['streams'];

        // Quotes are admitted before the new bundle identity is frozen, never
        // injected into an existing generation or its already sealed CSV.
        $quoteAttestation = app(HistoricalQuoteSpreadService::class)->attach(
            $streams['M5'], $this->csvHash($streams['M5']),
        );
        $streams['M5'] = $quoteAttestation['rows'];

        $sourceHashes = [];
        foreach ($streams as $timeframe => $rows) {
            $sourceHashes[$timeframe] = $this->rowContentHash($rows);
        }
        $identity = [
            'protocol' => self::PROTOCOL,
            'validation_bundle_protocol' => 'agent_owned_mtf_foundation_bundle_v1',
            'data_role' => 'pre_2026_foundation_training_only',
            'symbol' => $symbol,
            'provider' => $provider,
            'datasets' => ['M5' => $m5Dataset, 'M15' => $contextDataset, 'H1' => $contextDataset, 'H4' => 'derived_from_H1'],
            ...(($readiness['prospective_m5_repair'] ?? null) === null ? [] : ['prospective_m5_repair' => $readiness['prospective_m5_repair']]),
            'entry_rows' => count($streams['M5']),
            'entry_first_candle_at' => $streams['M5'][0]['time'],
            'entry_last_candle_at' => $streams['M5'][array_key_last($streams['M5'])]['time'],
            'closed_cutoff' => $exclusiveCutoff->toIso8601String(),
            'context_warmup_days' => 90,
            'stream_content_sha256' => $sourceHashes,
            'volume_provenance' => [
                ...$volumeAttestation['provenance'],
                'stream_content_sha256' => $sourceHashes,
            ],
            'quote_spread_provenance' => $quoteAttestation['provenance'],
            'aggregation' => ['H4' => 'four_complete_UTC_H1_candles'],
            'bounded_cost_contract' => [
                'maximum_m5_rows' => $maxM5Rows,
                'requested_m5_rows' => $maxM5Rows,
                'available_m5_rows_at_freeze' => (int) data_get($readiness, 'streams.M5.row_count'),
                'full_live_export_forbidden' => true,
                'nine_fold_max_rows_per_fold' => 4096,
            ],
            'post_selection_historical_evidence' => true,
            'runtime_trade_authority' => false,
            'parent_authority' => false,
            'promotion_evidence' => false,
        ];
        $bundleHash = hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES));
        $directory = storage_path('app/lab-datasets/mtf/'.$bundleHash);
        $manifestPath = $directory.'/manifest.json';
        if (is_file($manifestPath)) {
            $manifest = (array) json_decode((string) File::get($manifestPath), true);
            if ((string) ($manifest['bundle_hash'] ?? '') === $bundleHash && $this->validBundle($manifest)) {
                return $this->result($manifest, $directory);
            }
            throw new RuntimeException("MTF agent validation bundle integrity mismatch: {$directory}");
        }

        File::ensureDirectoryExists($directory);
        $streamManifest = [];
        foreach ($streams as $timeframe => $rows) {
            $path = $directory.'/'.strtolower($timeframe).'.csv';
            $this->writeCsv($path, $rows);
            $streamManifest[$timeframe] = $this->streamManifest($path, $rows, $timeframe);
        }
        $manifest = [
            ...$identity,
            'bundle_hash' => $bundleHash,
            'streams' => $streamManifest,
            'generated_at' => now()->utc()->toIso8601String(),
            'rule' => 'M5 entries may read only M15/H1/H4 candles closed by the M5 decision; the bundle is historical research, never promotion evidence.',
        ];
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $this->result($manifest, $directory);
    }

    /** @return array<string,mixed> */
    public function forLiquidityTrapReplay(string $symbol): array
    {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
        if ($symbol === '') {
            throw new RuntimeException('MTF snapshot uchun symbol topilmadi.');
        }

        $sources = [];
        foreach (['M5', 'M15', 'H1'] as $timeframe) {
            $path = $this->datasets->export($symbol, $timeframe);
            $sources[$timeframe] = [
                'path' => $path,
                'sha256' => hash_file('sha256', $path),
                'rows' => $this->datasets->rowsFromSnapshot($path),
            ];
        }
        $cutoff = $this->commonClosedCutoff($sources);
        $streams = [];
        foreach ($sources as $timeframe => $source) {
            $streams[$timeframe] = $this->closedRows((array) $source['rows'], $timeframe, $cutoff);
        }
        $streams['H4'] = $this->aggregateH4($streams['H1']);
        foreach (['M5' => 100, 'M15' => 40, 'H1' => 40, 'H4' => 10] as $timeframe => $minimum) {
            if (count($streams[$timeframe]) < $minimum) {
                throw new RuntimeException("MTF {$timeframe} snapshot candle yetarli emas: ".count($streams[$timeframe]));
            }
        }

        $identity = [
            'protocol' => self::PROTOCOL,
            'symbol' => $symbol,
            'closed_cutoff' => $cutoff->toIso8601String(),
            'source_sha256' => array_map(fn (array $item): ?string => $item['sha256'], $sources),
            'aggregation' => ['H4' => 'four_complete_UTC_H1_candles'],
        ];
        $bundleHash = hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES));
        $directory = storage_path('app/lab-datasets/mtf/'.$bundleHash);
        $manifestPath = $directory.'/manifest.json';
        if (is_file($manifestPath)) {
            $manifest = (array) json_decode((string) File::get($manifestPath), true);
            if ((string) ($manifest['bundle_hash'] ?? '') === $bundleHash && $this->validBundle($manifest)) {
                return $this->result($manifest, $directory);
            }
            throw new RuntimeException("MTF immutable bundle integrity mismatch: {$directory}");
        }

        File::ensureDirectoryExists($directory);
        $streamManifest = [];
        foreach ($streams as $timeframe => $rows) {
            $path = $directory.'/'.strtolower($timeframe).'.csv';
            $this->writeCsv($path, $rows);
            $streamManifest[$timeframe] = [
                'path' => $path,
                'sha256' => hash_file('sha256', $path),
                'row_count' => count($rows),
                'first_candle_at' => $rows[0]['time'],
                'last_candle_at' => $rows[array_key_last($rows)]['time'],
                'available_after_seconds' => self::DURATIONS[$timeframe] * 60,
            ];
        }
        $manifest = [
            ...$identity,
            'bundle_hash' => $bundleHash,
            'streams' => $streamManifest,
            'generated_at' => now()->utc()->toIso8601String(),
            'rule' => 'M5 execution may merge only H4/H1/M15 rows whose open time plus timeframe duration is at or before the M5 decision time.',
            'promotion_evidence' => false,
        ];
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $this->result($manifest, $directory);
    }

    /**
     * Freeze the complete input bundle for one catalogue playbook.
     *
     * Calendar/session requirements are deterministic functions of the UTC
     * candle timestamps and therefore add no mutable external stream. D1 and
     * H4 are derived only from the same sealed H1 source. A cross-market SMT
     * run requires an explicit related symbol; it is never synthesized from
     * the primary market.
     *
     * @param  array<int,string>  $requirements
     * @return array<string,mixed>
     */
    public function forResearchPlaybookReplay(
        string $symbol,
        array $requirements = [],
        ?string $relatedSymbol = null,
        int $maxM5Rows = self::RESEARCH_MAX_M5_ROWS,
    ): array {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
        if ($symbol === '') {
            throw new RuntimeException('MTF research snapshot uchun symbol topilmadi.');
        }
        $requirements = array_values(array_unique(array_map('strtoupper', $requirements)));
        $needsD1 = in_array('D1', $requirements, true);
        $needsRelated = in_array('RELATED_MARKET', $requirements, true);
        if ($needsRelated && trim((string) $relatedSymbol) === '') {
            throw new RuntimeException('SMT research uchun related symbol majburiy.');
        }
        if (! in_array($maxM5Rows, self::RESEARCH_EVIDENCE_BUDGETS, true)) {
            throw new RuntimeException('MTF research evidence budget is not a sealed tier.');
        }

        $sources = [];
        foreach (['M5', 'M15', 'H1'] as $timeframe) {
            // Canonical M5 currently begins in 2026, which is explicitly a
            // paper-only period. The catalogue may observe this data only as
            // a frozen scheduling prior; it cannot create agent-owned or
            // promotion evidence. A training export would be an empty M5
            // stream and make every newly built toolbox model unusable.
            $path = $this->datasets->exportPaper($symbol, $timeframe);
            $sources[$timeframe] = [
                'path' => $path,
                'sha256' => hash_file('sha256', $path),
                'rows' => $this->datasets->rowsFromSnapshot($path),
            ];
        }
        $cutoff = $this->commonClosedCutoff($sources);
        $streams = [];
        foreach ($sources as $timeframe => $source) {
            $streams[$timeframe] = $this->closedRows((array) $source['rows'], $timeframe, $cutoff);
        }
        // Bound discovery cost as the paper stream grows. Any promising
        // prior must still earn independent chronological evidence through a
        // separate agent-owned trial, so this catalogue pass does not need
        // an ever-growing full-history replay.
        if (count($streams['M5']) > $maxM5Rows) {
            $streams['M5'] = array_slice($streams['M5'], -$maxM5Rows);
        }
        $streams['H4'] = $this->aggregateH4($streams['H1']);
        if ($needsD1) {
            $streams['D1'] = $this->aggregateD1($streams['H1']);
        }

        foreach (['M5' => 100, 'M15' => 40, 'H1' => 40, 'H4' => 10, 'D1' => 10] as $timeframe => $minimum) {
            if (! array_key_exists($timeframe, $streams)) {
                continue;
            }
            if (count($streams[$timeframe]) < $minimum) {
                throw new RuntimeException("MTF {$timeframe} snapshot candle yetarli emas: ".count($streams[$timeframe]));
            }
        }

        $related = null;
        if ($needsRelated) {
            $relatedCode = strtoupper(str_replace(['/', '_', '-'], '', trim((string) $relatedSymbol)));
            $relatedPath = $this->datasets->exportPaper($relatedCode, 'M15');
            $relatedRows = $this->closedRows($this->datasets->rowsFromSnapshot($relatedPath), 'M15', $cutoff);
            if (count($relatedRows) < 40) {
                throw new RuntimeException('Related M15 snapshot candle yetarli emas: '.count($relatedRows));
            }
            $related = [
                'symbol' => $relatedCode,
                'source_sha256' => hash_file('sha256', $relatedPath),
                'rows' => $relatedRows,
            ];
        }

        $identity = [
            'protocol' => self::PROTOCOL,
            'research_protocol' => 'mtf_playbook_frozen_control_v2_multifidelity',
            'data_role' => 'paper_shadow_prior_only',
            'agent_owned_evidence' => false,
            'promotion_evidence' => false,
            'symbol' => $symbol,
            'requirements' => $requirements,
            'evidence_budget_protocol' => 'mtf_evidence_budget_ladder_v1',
            'bounded_entry_rows' => $maxM5Rows,
            'entry_first_candle_at' => data_get($streams, 'M5.0.time'),
            'closed_cutoff' => $cutoff->toIso8601String(),
            'source_sha256' => array_map(fn (array $item): ?string => $item['sha256'], $sources),
            'related_source' => $related ? ['symbol' => $related['symbol'], 'sha256' => $related['source_sha256']] : null,
            'aggregation' => [
                'H4' => 'four_complete_UTC_H1_candles',
                'D1' => $needsD1 ? 'twenty_four_complete_UTC_H1_candles' : null,
            ],
        ];
        $bundleHash = hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES));
        $directory = storage_path('app/lab-datasets/mtf/'.$bundleHash);
        $manifestPath = $directory.'/manifest.json';
        if (is_file($manifestPath)) {
            $manifest = (array) json_decode((string) File::get($manifestPath), true);
            if ((string) ($manifest['bundle_hash'] ?? '') === $bundleHash && $this->validBundle($manifest)) {
                return $this->researchResult($manifest, $directory);
            }
            throw new RuntimeException("MTF immutable bundle integrity mismatch: {$directory}");
        }

        File::ensureDirectoryExists($directory);
        $streamManifest = [];
        foreach ($streams as $timeframe => $rows) {
            $path = $directory.'/'.strtolower($timeframe).'.csv';
            $this->writeCsv($path, $rows);
            $streamManifest[$timeframe] = $this->streamManifest($path, $rows, $timeframe);
        }
        if ($related) {
            $path = $directory.'/related_m15.csv';
            $this->writeCsv($path, $related['rows']);
            $streamManifest['RELATED_M15'] = [
                ...$this->streamManifest($path, $related['rows'], 'M15'),
                'symbol' => $related['symbol'],
                'source_sha256' => $related['source_sha256'],
            ];
        }
        $manifest = [
            ...$identity,
            'bundle_hash' => $bundleHash,
            'streams' => $streamManifest,
            'generated_at' => now()->utc()->toIso8601String(),
            'rule' => 'M5 execution may merge only H4/H1/M15/D1 rows whose open time plus timeframe duration is at or before the M5 candle-close decision time.',
            'promotion_evidence' => false,
        ];
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $this->researchResult($manifest, $directory);
    }

    /**
     * Reopen a content-addressed research bundle without exporting fresh
     * candles. Causal repair must use the source trial's exact bytes.
     *
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    public function restoreResearchPlaybookBundle(array $manifest): array
    {
        $bundleHash = strtolower(trim((string) ($manifest['bundle_hash'] ?? '')));
        if (! preg_match('/^[a-f0-9]{64}$/', $bundleHash)) {
            throw new RuntimeException('MTF source bundle hash is missing or invalid.');
        }
        $directory = storage_path('app/lab-datasets/mtf/'.$bundleHash);
        $expectedDirectory = realpath($directory);
        if ($expectedDirectory === false || ! is_file($directory.'/manifest.json')) {
            throw new RuntimeException("MTF source bundle is unavailable: {$bundleHash}");
        }
        $diskManifest = (array) json_decode((string) File::get($directory.'/manifest.json'), true);
        if ((string) ($diskManifest['bundle_hash'] ?? '') !== $bundleHash) {
            throw new RuntimeException("MTF source manifest identity mismatch: {$bundleHash}");
        }
        foreach ((array) ($diskManifest['streams'] ?? []) as $stream) {
            $path = realpath((string) data_get($stream, 'path', ''));
            if ($path === false || ! str_starts_with($path, $expectedDirectory.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("MTF source bundle path escaped its immutable directory: {$bundleHash}");
            }
        }
        if (! $this->validBundle($diskManifest)) {
            throw new RuntimeException("MTF source bundle integrity mismatch: {$bundleHash}");
        }

        return $this->researchResult($diskManifest, $directory);
    }

    /**
     * Reopen the exact sealed model-validation bytes on a retry.
     *
     * The foundation archive can continue growing while a replay is in
     * progress. A retry must not silently move its cutoff or reread hundreds
     * of thousands of rows; it resumes the original content-addressed bundle.
     *
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    public function restoreAgentOwnedConfirmationValidationBundle(array $manifest): array
    {
        if ((string) data_get($manifest, 'validation_bundle_protocol') !== 'agent_owned_mtf_foundation_bundle_v1'
            || (string) data_get($manifest, 'data_role') !== 'pre_2026_foundation_training_only'
            || (bool) data_get($manifest, 'promotion_evidence', true)) {
            throw new RuntimeException('MTF agent validation resume manifest has an invalid evidence boundary.');
        }
        $budget = (int) data_get($manifest, 'bounded_cost_contract.requested_m5_rows', 0);
        if (! in_array($budget, self::AGENT_VALIDATION_EVIDENCE_BUDGETS, true)) {
            throw new RuntimeException('MTF agent validation resume manifest has an unsealed evidence budget.');
        }
        $bundleHash = strtolower(trim((string) ($manifest['bundle_hash'] ?? '')));
        if (! preg_match('/^[a-f0-9]{64}$/', $bundleHash)) {
            throw new RuntimeException('MTF agent validation resume bundle hash is missing or invalid.');
        }
        $directory = storage_path('app/lab-datasets/mtf/'.$bundleHash);
        $expectedDirectory = realpath($directory);
        if ($expectedDirectory === false || ! is_file($directory.'/manifest.json')) {
            throw new RuntimeException("MTF agent validation resume bundle is unavailable: {$bundleHash}");
        }
        $diskManifest = (array) json_decode((string) File::get($directory.'/manifest.json'), true);
        if ((string) ($diskManifest['bundle_hash'] ?? '') !== $bundleHash
            || (string) data_get($diskManifest, 'validation_bundle_protocol') !== 'agent_owned_mtf_foundation_bundle_v1') {
            throw new RuntimeException("MTF agent validation resume identity mismatch: {$bundleHash}");
        }
        foreach ((array) ($diskManifest['streams'] ?? []) as $stream) {
            $path = realpath((string) data_get($stream, 'path', ''));
            if ($path === false || ! str_starts_with($path, $expectedDirectory.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("MTF agent validation resume path escaped its immutable directory: {$bundleHash}");
            }
        }
        if (! $this->validBundle($diskManifest)) {
            throw new RuntimeException("MTF agent validation resume integrity mismatch: {$bundleHash}");
        }

        return [...$this->result($diskManifest, $directory), 'restored_from_sealed_retry' => true];
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private function result(array $manifest, string $directory): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'bundle_hash' => $manifest['bundle_hash'],
            'manifest' => $manifest,
            'manifest_path' => $directory.'/manifest.json',
            'entry_dataset_path' => data_get($manifest, 'streams.M5.path'),
            'context_dataset_paths' => [
                'H4' => data_get($manifest, 'streams.H4.path'),
                'H1' => data_get($manifest, 'streams.H1.path'),
                'M15' => data_get($manifest, 'streams.M15.path'),
            ],
        ];
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private function researchResult(array $manifest, string $directory): array
    {
        $contexts = [];
        foreach (['H4', 'H1', 'M15', 'D1'] as $timeframe) {
            $path = (string) data_get($manifest, "streams.{$timeframe}.path", '');
            if ($path !== '') {
                $contexts[$timeframe] = $path;
            }
        }
        $related = (string) data_get($manifest, 'streams.RELATED_M15.path', '');

        return [
            'protocol' => self::PROTOCOL,
            'bundle_hash' => $manifest['bundle_hash'],
            'manifest' => $manifest,
            'manifest_path' => $directory.'/manifest.json',
            'entry_dataset_path' => data_get($manifest, 'streams.M5.path'),
            'context_dataset_paths' => $contexts,
            'related_context_dataset_paths' => $related !== '' ? ['M15' => $related] : [],
        ];
    }

    /** @param array<string,array<string,mixed>> $sources */
    private function commonClosedCutoff(array $sources): CarbonImmutable
    {
        $closed = [];
        foreach ($sources as $timeframe => $source) {
            $rows = (array) ($source['rows'] ?? []);
            $last = $rows[array_key_last($rows)] ?? null;
            if (! is_array($last) || ! filled($last['time'] ?? null)) {
                throw new RuntimeException("MTF {$timeframe} source bo'sh.");
            }
            $closed[] = CarbonImmutable::parse((string) $last['time'], 'UTC')->addMinutes(self::DURATIONS[$timeframe]);
        }

        return collect($closed)->min();
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function closedRows(array $rows, string $timeframe, CarbonImmutable $cutoff): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($timeframe, $cutoff): bool {
            try {
                return CarbonImmutable::parse((string) ($row['time'] ?? ''), 'UTC')
                    ->addMinutes(self::DURATIONS[$timeframe])
                    ->lessThanOrEqualTo($cutoff);
            } catch (\Throwable) {
                return false;
            }
        }));
    }

    /** @param array<int,array<string,mixed>> $h1Rows @return array<int,array<string,mixed>> */
    private function aggregateH4(array $h1Rows): array
    {
        return $this->aggregateH1($h1Rows, 4, 'H4');
    }

    /** @param array<int,array<string,mixed>> $h1Rows @return array<int,array<string,mixed>> */
    private function aggregateD1(array $h1Rows): array
    {
        return $this->aggregateH1($h1Rows, 24, 'D1');
    }

    /** @param array<int,array<string,mixed>> $h1Rows @return array<int,array<string,mixed>> */
    private function aggregateH1(array $h1Rows, int $hours, string $timeframe): array
    {
        $groups = [];
        foreach ($h1Rows as $row) {
            $time = CarbonImmutable::parse((string) $row['time'], 'UTC')->startOfHour();
            $bucket = $hours === 24
                ? $time->startOfDay()
                : $time->subHours($time->hour % $hours);
            $groups[$bucket->toIso8601String()][] = [...$row, '_time' => $time];
        }
        $result = [];
        foreach ($groups as $bucket => $rows) {
            usort($rows, fn (array $a, array $b): int => $a['_time'] <=> $b['_time']);
            if (count($rows) !== $hours) {
                continue;
            }
            $first = $rows[0]['_time'];
            $complete = collect($rows)->every(fn (array $row, int $index): bool => $row['_time']->equalTo($first->addHours($index)));
            if (! $complete) {
                continue;
            }
            $result[] = [
                'time' => CarbonImmutable::parse($bucket, 'UTC')->format('Y-m-d H:i:s'),
                'open' => (float) $rows[0]['open'],
                'high' => max(array_map(fn (array $row): float => (float) $row['high'], $rows)),
                'low' => min(array_map(fn (array $row): float => (float) $row['low'], $rows)),
                'close' => (float) $rows[$hours - 1]['close'],
                'volume' => array_sum(array_map(fn (array $row): float => (float) ($row['volume'] ?? 0), $rows)),
            ];
        }

        return $result;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,mixed> */
    private function streamManifest(string $path, array $rows, string $timeframe): array
    {
        $manifest = [
            'path' => $path,
            'sha256' => hash_file('sha256', $path),
            'row_count' => count($rows),
            'first_candle_at' => $rows[0]['time'],
            'last_candle_at' => $rows[array_key_last($rows)]['time'],
            'available_after_seconds' => self::DURATIONS[$timeframe] * 60,
        ];
        if (array_key_exists('volume_available', $rows[0] ?? [])) {
            $available = count(array_filter(
                $rows,
                static fn (array $row): bool => (bool) ($row['volume_available'] ?? false),
            ));
            $coverage = count($rows) > 0 ? $available / count($rows) : 0.0;
            $minimumCoverage = (float) config('services.market_volume.minimum_coverage', 0.95);
            $manifest['volume_quality'] = [
                'status' => $coverage >= $minimumCoverage ? 'passed' : 'volume_unavailable',
                'rows' => count($rows),
                'available_rows' => $available,
                'coverage' => round($coverage, 6),
                'minimum_coverage' => $minimumCoverage,
                'source_contract' => MarketVolumeService::HISTORICAL_SOURCE_CONTRACT,
                'promotion_evidence' => false,
            ];
        }

        return $manifest;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function rowContentHash(array $rows): string
    {
        $context = hash_init('sha256');
        foreach ($rows as $row) {
            $parts = [
                (string) ($row['time'] ?? ''),
                sprintf('%.10F', (float) ($row['open'] ?? 0)),
                sprintf('%.10F', (float) ($row['high'] ?? 0)),
                sprintf('%.10F', (float) ($row['low'] ?? 0)),
                sprintf('%.10F', (float) ($row['close'] ?? 0)),
                sprintf('%.10F', (float) ($row['volume'] ?? 0)),
                (bool) ($row['volume_available'] ?? false) ? '1' : '0',
            ];
            if (array_key_exists('spread_available', $row)) {
                $parts = [...$parts,
                    (bool) $row['spread_available'] ? '1' : '0',
                    sprintf('%.10F', (float) ($row['spread'] ?? 0)),
                    sprintf('%.10F', (float) ($row['bid_close'] ?? 0)),
                    sprintf('%.10F', (float) ($row['ask_close'] ?? 0)),
                    (string) ($row['quote_time_utc'] ?? ''), (string) ($row['quote_available_after_utc'] ?? ''),
                    (string) ($row['quote_age_ms'] ?? ''),
                ];
            }
            hash_update($context, implode('|', $parts)."\n");
        }

        return hash_final($context);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function writeCsv(string $path, array $rows): void
    {
        $temporary = tempnam(dirname($path), '.mtf_');
        if ($temporary === false) {
            throw new RuntimeException("MTF temporary snapshot yaratilmadi: {$path}");
        }
        try {
            $handle = fopen($temporary, 'wb');
            if ($handle === false) {
                throw new RuntimeException("MTF temporary snapshot ochilmadi: {$path}");
            }
            $headers = $this->csvColumns($rows);
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $this->csvValues($row, $headers));
            }
            fclose($handle);
            if (! copy($temporary, $path)) {
                throw new RuntimeException("MTF snapshot publish qilinmadi: {$path}");
            }
        } finally {
            File::delete($temporary);
        }
    }

    private function csvColumns(array $rows): array
    {
        $columns = ['time', 'open', 'high', 'low', 'close', 'volume'];
        foreach (['volume_available', 'spread_available'] as $marker) {
            if (collect($rows)->contains(fn (array $row): bool => array_key_exists($marker, $row))) {
                $columns[] = $marker;
                if ($marker === 'spread_available') {
                    $columns = [...$columns, 'spread', 'bid_close', 'ask_close', 'quote_time_utc', 'quote_available_after_utc', 'quote_age_ms'];
                }
            }
        }

        return $columns;
    }

    private function csvValues(array $row, array $columns): array
    {
        return array_map(static fn (string $column): mixed => match ($column) {
            'volume_available', 'spread_available' => (bool) ($row[$column] ?? false) ? 1 : 0,
            'volume' => $row[$column] ?? 0,
            default => $row[$column] ?? '',
        }, $columns);
    }

    /** Same canonical CSV bytes as writeCsv; binds quotes to the price-only input. */
    private function csvHash(array $rows): string
    {
        $handle = fopen('php://temp/maxmemory:1048576', 'w+b');
        if (! $handle) {
            throw new RuntimeException('MTF_CSV_HASH_STREAM_UNAVAILABLE');
        }
        try {
            $columns = $this->csvColumns($rows);
            fputcsv($handle, $columns);
            foreach ($rows as $row) {
                fputcsv($handle, $this->csvValues($row, $columns));
            }
            rewind($handle);
            $digest = hash_init('sha256');
            hash_update_stream($digest, $handle);

            return hash_final($digest);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Audit and mark the exact historical streams being frozen. Provider
     * identity is part of the training-store key; the resulting receipt is
     * additionally bound to each stream's content hash by the caller.
     *
     * @param  array<string,array<int,array<string,mixed>>>  $streams
     * @return array{streams: array<string,array<int,array<string,mixed>>>, provenance: array<string,mixed>}
     */
    private function attestHistoricalVolumeStreams(array $streams, string $provider): array
    {
        $minimumCoverage = (float) config('services.market_volume.minimum_coverage', 0.95);
        $minimumUsableRatio = (float) config('services.market_volume.minimum_usable_ratio', 0.95);
        $providerIsCanonical = strtolower($provider) === 'dukascopy';
        $quality = [];
        $allPassed = $providerIsCanonical;

        foreach ($streams as $timeframe => &$rows) {
            $available = 0;
            $usable = 0;
            foreach ($rows as &$row) {
                $volume = is_numeric($row['volume'] ?? null) ? (float) $row['volume'] : NAN;
                $rowAvailable = $providerIsCanonical && is_finite($volume) && $volume > 0;
                $row['volume_available'] = $rowAvailable;
                if ($rowAvailable) {
                    $available++;
                    $usable++;
                }
            }
            unset($row);
            $count = count($rows);
            $coverage = $count > 0 ? $available / $count : 0.0;
            $usableRatio = $count > 0 ? $usable / $count : 0.0;
            $passed = $providerIsCanonical
                && $coverage >= $minimumCoverage
                && $usableRatio >= $minimumUsableRatio;
            $allPassed = $allPassed && $passed;
            $quality[$timeframe] = [
                'status' => $passed ? 'passed' : 'volume_unavailable',
                'rows' => $count,
                'available_rows' => $available,
                'usable_rows' => $usable,
                'coverage' => round($coverage, 6),
                'usable_ratio' => round($usableRatio, 6),
            ];
        }
        unset($rows);

        return [
            'streams' => $streams,
            'provenance' => [
                'protocol' => 'historical_volume_snapshot_provenance_v1',
                'status' => $allPassed ? 'passed' : 'volume_unavailable',
                'reason' => $allPassed
                    ? 'frozen_historical_stream_quality_gate_passed'
                    : ($providerIsCanonical ? 'historical_stream_quality_gate_failed' : 'non_canonical_provider'),
                'provider' => strtolower($provider),
                'transport' => 'frozen_training_archive',
                'price_side' => 'BID',
                'semantic' => MarketVolumeService::SEMANTIC,
                'unit' => MarketVolumeService::UNIT,
                'session' => 'UTC',
                'source_contract' => MarketVolumeService::HISTORICAL_SOURCE_CONTRACT,
                'availability_column' => 'volume_available',
                'minimum_coverage' => $minimumCoverage,
                'minimum_usable_ratio' => $minimumUsableRatio,
                'streams' => $quality,
                'attestation_basis' => 'training_archive_provider_identity_and_frozen_row_audit',
                'attestation_scope' => 'this_frozen_historical_bundle_only',
                'live_coverage_inherited' => false,
                'promotion_evidence' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $manifest */
    private function validBundle(array $manifest): bool
    {
        foreach ((array) ($manifest['streams'] ?? []) as $stream) {
            $path = (string) data_get($stream, 'path', '');
            $expected = (string) data_get($stream, 'sha256', '');
            $actual = $path !== '' && is_file($path) ? hash_file('sha256', $path) : false;
            if (! is_string($actual) || $expected === '' || ! hash_equals($expected, $actual)) {
                return false;
            }
        }

        return true;
    }
}
