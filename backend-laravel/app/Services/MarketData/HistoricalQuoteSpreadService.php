<?php

namespace App\Services\MarketData;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use RuntimeException;

/** Admits synchronized quote sidecars only into a newly frozen research stream. */
class HistoricalQuoteSpreadService
{
    public const PROTOCOL = 'historical_quote_spread_snapshot_v1';

    /** @return array{rows:array,provenance:array} */
    public function attach(array $rows, string $sourceCsvHash): array
    {
        $root = storage_path('app/lab-datasets/quote-spread');
        $candidates = [];
        foreach (is_dir($root) ? File::directories($root) : [] as $directory) {
            if (! preg_match('/^[a-f0-9]{64}$/', basename($directory)) || ! is_file($directory.'/manifest.json')) {
                continue;
            }
            $manifest = json_decode((string) File::get($directory.'/manifest.json'), true);
            $identity = (array) ($manifest['identity'] ?? []);
            // Independently closing BID/ASK bars remain diagnostic only.
            if (($identity['protocol'] ?? null) !== 'research_quote_spread_ticks_v1'
                || ($identity['m5_sha256'] ?? null) !== $sourceCsvHash) {
                continue;
            }
            $this->assertArtifact($manifest, $directory);
            $candidates[] = ['directory' => $directory, 'manifest' => $manifest];
        }
        // Freeze every matching artifact in stable digest order. Overlap must
        // agree on the exact quote; a newer download cannot silently override.
        usort($candidates, fn (array $a, array $b): int => strcmp($a['manifest']['identity_hash'], $b['manifest']['identity_hash']));
        $quotes = [];
        $sources = [];
        foreach ($candidates as $candidate) {
            $manifest = $candidate['manifest'];
            $sources[] = $manifest;
            $handle = fopen($candidate['directory'].'/m5-spread.csv', 'rb');
            if (! $handle) {
                throw new RuntimeException('HISTORICAL_QUOTE_SIDECAR_UNREADABLE');
            }
            try {
                $header = fgetcsv($handle);
                $required = ['time', 'spread', 'spread_available', 'bid_close', 'ask_close', 'quote_time_utc', 'available_after_utc', 'quote_age_ms', 'observation_status'];
                if ($header !== $required) {
                    throw new RuntimeException('HISTORICAL_QUOTE_HEADER_INVALID');
                }
                while (($values = fgetcsv($handle)) !== false) {
                    if (count($values) !== count($header)) {
                        throw new RuntimeException('HISTORICAL_QUOTE_ROW_INVALID');
                    }
                    $quote = array_combine($header, $values);
                    $time = (string) $quote['time'];
                    if (! in_array($quote['spread_available'], ['0', '1'], true)) {
                        throw new RuntimeException('HISTORICAL_QUOTE_AVAILABILITY_INVALID');
                    }
                    if ($quote['spread_available'] !== '1') {
                        continue;
                    }
                    $this->assertQuote($quote);
                    if (isset($quotes[$time]) && $quotes[$time] !== $quote) {
                        throw new RuntimeException('HISTORICAL_QUOTE_OVERLAP_CONFLICT');
                    }
                    $quotes[$time] = $quote;
                }
            } finally {
                fclose($handle);
            }
        }
        $available = 0;
        if ($sources !== []) {
            foreach ($rows as &$row) {
                $quote = $quotes[(string) $row['time']] ?? null;
                if ($quote && abs((float) $quote['bid_close'] - (float) $row['close']) > 0.000001) {
                    throw new RuntimeException('HISTORICAL_QUOTE_FROZEN_BID_MISMATCH');
                }
                $row['spread'] = $quote ? (float) $quote['spread'] : null;
                $row['spread_available'] = $quote !== null;
                $row['bid_close'] = $quote ? (float) $quote['bid_close'] : null;
                $row['ask_close'] = $quote ? (float) $quote['ask_close'] : null;
                $row['quote_time_utc'] = $quote['quote_time_utc'] ?? null;
                $row['quote_available_after_utc'] = $quote['available_after_utc'] ?? null;
                $row['quote_age_ms'] = $quote ? (int) $quote['quote_age_ms'] : null;
                $available += $quote ? 1 : 0;
            }
            unset($row);
        }

        return ['rows' => $rows, 'provenance' => [
            'protocol' => self::PROTOCOL,
            'status' => $available === count($rows) && $available > 0 ? 'ready' : ($available > 0 ? 'partial' : 'unavailable'),
            'provider' => 'dukascopy_historical_synchronized_tick_v1',
            'source_m5_csv_sha256' => $sourceCsvHash, 'sources' => $sources,
            'rows' => count($rows), 'available_rows' => $available,
            'coverage' => count($rows) ? round($available / count($rows), 8) : 0,
            'maximum_quote_age_ms' => 60000, 'availability_column' => 'spread_available',
            'observation' => 'last_synchronized_bid_ask_tick_strictly_before_m5_close',
            'execution_cost_model_changed' => false,
            'paper_2026_included' => false, 'independent_validation_evidence' => false, 'promotion_evidence' => false,
        ]];
    }

    private function assertArtifact(array $manifest, string $directory): void
    {
        $identity = (array) ($manifest['identity'] ?? []);
        $digest = hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        foreach (['m5_sha256', 'sidecar_sha256', 'freezer_sha256', 'decoder_sha256', 'dependency_lock_sha256'] as $field) {
            if (! preg_match('/^[a-f0-9]{64}$/', (string) ($identity[$field] ?? ''))) {
                throw new RuntimeException('HISTORICAL_QUOTE_SOURCE_INVALID');
            }
        }
        foreach ((array) ($identity['source_hour_content_sha256'] ?? []) as $hash) {
            if (! preg_match('/^[a-f0-9]{64}$/', (string) $hash)) {
                throw new RuntimeException('HISTORICAL_QUOTE_SOURCE_INVALID');
            }
        }
        if (empty($identity['from_utc']) || empty($identity['to_utc_exclusive'])) {
            throw new RuntimeException('HISTORICAL_QUOTE_INTERVAL_INVALID');
        }
        $from = CarbonImmutable::parse($identity['from_utc'], 'UTC');
        $to = CarbonImmutable::parse($identity['to_utc_exclusive'], 'UTC');
        if (! $from->lessThan($to) || $from->diffInDays($to) > 31
            || $to->greaterThan(CarbonImmutable::parse('2026-01-01', 'UTC'))) {
            throw new RuntimeException('HISTORICAL_QUOTE_INTERVAL_INVALID');
        }
        $actual = is_file($directory.'/m5-spread.csv') ? hash_file('sha256', $directory.'/m5-spread.csv') : false;
        if (basename($directory) !== $digest || ($manifest['identity_hash'] ?? '') !== $digest
            || ! is_string($actual) || ! hash_equals((string) ($identity['sidecar_sha256'] ?? ''), $actual)
            || ($identity['symbol'] ?? '') !== 'XAUUSD' || ($identity['timeframe'] ?? '') !== 'M5'
            || ($identity['provider'] ?? '') !== 'dukascopy_historical_synchronized_tick_v1'
            || ($identity['observation'] ?? '') !== 'last_synchronized_bid_ask_tick_strictly_before_m5_close'
            || ($identity['maximum_quote_age_ms'] ?? null) !== 60000
            || ($identity['paper_2026_included'] ?? null) !== false || ($identity['promotion_evidence'] ?? null) !== false
            || ($identity['automatic_replay_authority'] ?? null) !== false
            || empty($identity['source_hour_content_sha256'])
            || (string) ($identity['to_utc_exclusive'] ?? '') > '2026-01-01') {
            throw new RuntimeException('HISTORICAL_QUOTE_ARTIFACT_INVALID');
        }
    }

    private function assertQuote(array $quote): void
    {
        foreach (['spread', 'bid_close', 'ask_close', 'quote_age_ms'] as $key) {
            if (! is_numeric($quote[$key]) || ! is_finite((float) $quote[$key])) {
                throw new RuntimeException('HISTORICAL_QUOTE_VALUE_INVALID');
            }
        }
        $open = CarbonImmutable::parse($quote['time'], 'UTC');
        $close = $open->addMinutes(5);
        $observed = CarbonImmutable::parse($quote['quote_time_utc'], 'UTC');
        $age = (int) round($observed->diffInMilliseconds($close, false));
        if ($open->greaterThanOrEqualTo(CarbonImmutable::parse('2026-01-01', 'UTC'))
            || $observed->lessThan($open) || ! $observed->lessThan($close)
            || ! CarbonImmutable::parse($quote['available_after_utc'], 'UTC')->equalTo($close)
            || $age <= 0 || $age > 60000 || $age !== (int) $quote['quote_age_ms']
            || (float) $quote['bid_close'] <= 0 || (float) $quote['ask_close'] < (float) $quote['bid_close']
            || abs((float) $quote['spread'] - ((float) $quote['ask_close'] - (float) $quote['bid_close'])) > 0.000001
            || $quote['observation_status'] !== 'paired') {
            throw new RuntimeException('HISTORICAL_QUOTE_ALIGNMENT_INVALID');
        }
    }
}
