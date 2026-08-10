<?php

namespace App\Services\MarketData;

use App\Models\Candle;
use App\Models\MarketVolumeObservation;
use App\Models\Symbol;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Owns the single canonical volume source used by volume research.
 *
 * Price candles may remain on the existing canonical price provider. Volume
 * is deliberately stored separately so a zero-volume TwelveData candle can
 * never masquerade as a low-liquidity observation, and a provider fallback
 * cannot silently change the feature semantics of a frozen replay.
 */
class MarketVolumeService
{
    public const PROTOCOL = 'canonical_volume_source_v1';
    public const SOURCE_CONTRACT = 'dukascopy_jetta_bid_tick_volume_millions_v1';
    public const SEMANTIC = 'tick_volume';
    public const UNIT = 'millions';

    public function __construct(private DukascopyMarketDataProvider $dukascopy) {}

    /** @return array<string, mixed> */
    public function contract(): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'source_contract' => self::SOURCE_CONTRACT,
            'provider' => 'dukascopy',
            'transport' => 'jetta',
            'price_side' => 'BID',
            'semantic' => self::SEMANTIC,
            'unit' => self::UNIT,
            'timezone' => 'UTC',
            'fallback' => false,
            'normalization' => [
                'protocol' => 'relative_volume_session_v2',
                'global_lookback' => 168,
                'session_lookback' => 20,
                'm15_global_lookback' => 672,
                'seasonality_bucket' => 'utc_weekday_timeframe_slot_v2',
                'effort_result' => 'shadow_diagnostic_only_v1',
                'market_calendar' => 'dukascopy_instrument_session_calendar_v2',
                'uses_only_prior_closed_candles' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function sync(
        string $symbol,
        string $timeframe = 'H1',
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array {
        $this->assertCanonicalTransport();
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $from = $from ? CarbonImmutable::instance($from)->utc() : $this->priceStart($symbol, $timeframe);
        $to = $to ? CarbonImmutable::instance($to)->utc() : $this->priceEnd($symbol, $timeframe);
        if (! $from || ! $to || $from->greaterThanOrEqualTo($to)) {
            throw new RuntimeException("{$symbol} {$timeframe}: volume sync uchun price range topilmadi.");
        }

        // This is intentionally a direct provider call. No market-data
        // fallback is allowed for the canonical volume contract.
        $fetchedRows = 0;
        $storedRows = 0;
        $cursor = $from;
        $chunkMonths = max(1, (int) config('services.market_volume.sync_chunk_months', 1));
        $chunkDays = max(1, (int) config('services.market_volume.sync_chunk_days', 1));
        $chunkPauseSeconds = max(0, (int) config('services.market_volume.sync_chunk_pause_seconds', 2));
        while ($cursor->lessThan($to)) {
            // M15 legacy files are one file per UTC day.  A daily checkpoint
            // keeps a long backfill resumable and prevents one rate-limited
            // file from discarding the whole month's fetched result. H1 keeps
            // its efficient monthly Jetta archive path.
            $chunkEnd = $timeframe === 'M15'
                ? $cursor->addDays($chunkDays)
                : $cursor->startOfMonth()->addMonths($chunkMonths);
            if ($chunkEnd->lessThanOrEqualTo($cursor)) {
                $chunkEnd = $timeframe === 'M15' ? $cursor->addDay() : $cursor->addMonth();
            }
            if ($chunkEnd->greaterThan($to)) $chunkEnd = $to;
            $rows = $this->dukascopy->fetchCandles(
                symbol: $symbol,
                providerSymbol: strtolower($symbol),
                timeframe: $timeframe,
                limit: 1000000,
                from: $cursor,
                to: $chunkEnd,
            );
            $fetchedRows += count($rows);
            $now = now();
            $payload = collect($rows)->map(function (array $row) use ($symbol, $timeframe, $now): array {
                $volume = is_numeric($row['volume'] ?? null) ? (float) $row['volume'] : 0.0;

                return [
                    'source_contract' => self::SOURCE_CONTRACT,
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'time' => $row['time'],
                    'raw_volume' => max(0.0, $volume),
                    'semantic' => self::SEMANTIC,
                    'unit' => self::UNIT,
                    'status' => $volume > 0 ? 'usable' : 'unavailable',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->values();
            $payload->chunk(1000)->each(fn (Collection $chunk) => MarketVolumeObservation::upsert(
                $chunk->all(),
                ['source_contract', 'symbol', 'timeframe', 'time'],
                ['raw_volume', 'semantic', 'unit', 'status', 'updated_at'],
            ));
            $storedRows += $payload->count();
            $cursor = $chunkEnd;
            if ($cursor->lessThan($to) && $chunkPauseSeconds > 0) {
                sleep($chunkPauseSeconds);
            }
        }

        return [
            'status' => 'synced',
            'contract' => $this->contract(),
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'fetched_rows' => $fetchedRows,
            'stored_rows' => $storedRows,
            'chunk_months' => $chunkMonths,
            'chunk_days' => $chunkDays,
            'chunk_pause_seconds' => $chunkPauseSeconds,
            'resumable' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function inspect(string $symbol, string $timeframe = 'H1', ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $priceQuery = Candle::query()->whereHas('symbol', fn ($query) => $query->where('code', $symbol))
            ->where('timeframe', $timeframe);
        if ($from) $priceQuery->where('time', '>=', CarbonImmutable::instance($from)->utc());
        if ($to) $priceQuery->where('time', '<', CarbonImmutable::instance($to)->utc());
        $priceRows = $priceQuery->orderBy('time')->get(['time']);
        $priceRowCount = $priceRows->count();
        $first = $priceRows->first()?->time;
        $last = $priceRows->last()?->time;
        $eligiblePriceRows = $priceRows
            ->filter(fn (Candle $price): bool => $this->isExpectedVolumeCandle($price->time, $symbol, $timeframe))
            ->values();
        $expected = $eligiblePriceRows->count();
        $lastExpected = $eligiblePriceRows->last()?->time;

        $volumeQuery = MarketVolumeObservation::query()
            ->where('source_contract', self::SOURCE_CONTRACT)
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe);
        if ($from) $volumeQuery->where('time', '>=', CarbonImmutable::instance($from)->utc());
        if ($to) $volumeQuery->where('time', '<', CarbonImmutable::instance($to)->utc());
        $volumeRows = $volumeQuery->orderBy('time')->get(['time', 'raw_volume', 'status']);
        $byTime = $volumeRows->keyBy(fn (MarketVolumeObservation $row): string => $this->timeKey($row->time));
        $matched = 0;
        $usable = 0;
        foreach ($eligiblePriceRows as $price) {
            $volume = $byTime->get($this->timeKey($price->time));
            if (! $volume) continue;
            $matched++;
            if ((float) $volume->raw_volume > 0 && $volume->status === 'usable') $usable++;
        }
        $coverage = $expected > 0 ? $matched / $expected : 0.0;
        $usableRatio = $expected > 0 ? $usable / $expected : 0.0;
        $lastVolume = $volumeRows->last()?->time;
        $lagSeconds = $lastExpected && $lastVolume
            ? max(0, CarbonImmutable::parse($lastExpected, 'UTC')->diffInSeconds(CarbonImmutable::parse($lastVolume, 'UTC'), absolute: true))
            : null;
        $maxLagHours = max(0.0, (float) config('services.market_volume.max_lag_hours', 24));
        $minCoverage = (float) config('services.market_volume.minimum_coverage', .95);
        $minUsable = (float) config('services.market_volume.minimum_usable_ratio', .95);
        $reasons = [];
        if ($expected === 0) $reasons[] = 'NO_PRICE_ROWS';
        if ($coverage < $minCoverage) $reasons[] = 'VOLUME_COVERAGE_BELOW_THRESHOLD';
        if ($usableRatio < $minUsable) $reasons[] = 'VOLUME_ZERO_OR_UNAVAILABLE_RATIO_ABOVE_THRESHOLD';
        if ($expected > 0 && ! $lastVolume) $reasons[] = 'VOLUME_LATEST_OBSERVATION_MISSING';
        if ($lagSeconds !== null && $lagSeconds > ($maxLagHours * 3600)) $reasons[] = 'VOLUME_STALE_LAG';
        if (strtolower((string) config('services.market_volume.provider', 'dukascopy')) !== 'dukascopy') $reasons[] = 'VOLUME_SOURCE_CONTRACT_MISMATCH';
        if (strtolower((string) config('services.market_volume.transport', 'jetta')) !== 'jetta') $reasons[] = 'VOLUME_TRANSPORT_CONTRACT_MISMATCH';

        $raw = $volumeRows->pluck('raw_volume')->map(fn ($value): float => (float) $value)->filter(fn (float $value): bool => $value > 0);

        return [
            'status' => $reasons === [] ? 'passed' : 'volume_unavailable',
            'protocol' => self::PROTOCOL,
            'contract' => $this->contract(),
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'first_price_at' => $first?->toIso8601String(),
            'last_price_at' => $last?->toIso8601String(),
            'last_volume_at' => $lastVolume?->toIso8601String(),
            'lag_seconds' => $lagSeconds,
            'max_lag_hours' => $maxLagHours,
            'price_rows_total' => $priceRowCount,
            'excluded_closed_market_rows' => max(0, $priceRowCount - $expected),
            'expected_price_rows' => $expected,
            'observed_volume_rows' => $volumeRows->count(),
            'matched_rows' => $matched,
            'usable_rows' => $usable,
            'coverage_ratio' => round($coverage, 6),
            'usable_ratio' => round($usableRatio, 6),
            'zero_or_unavailable_ratio' => round(max(0.0, 1.0 - $usableRatio), 6),
            'minimum_coverage' => $minCoverage,
            'minimum_usable_ratio' => $minUsable,
            'raw_stats' => [
                'min' => $raw->isEmpty() ? null : round((float) $raw->min(), 8),
                'max' => $raw->isEmpty() ? null : round((float) $raw->max(), 8),
                'avg' => $raw->isEmpty() ? null : round((float) $raw->avg(), 8),
            ],
            'reasons' => $reasons,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, array{volume: float, available: bool}> */
    public function forDataset(string $symbol, string $timeframe = 'H1'): array
    {
        return MarketVolumeObservation::query()
            ->where('source_contract', self::SOURCE_CONTRACT)
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->orderBy('time')
            ->get(['time', 'raw_volume', 'status'])
            ->mapWithKeys(fn (MarketVolumeObservation $row): array => [
                $this->timeKey($row->time) => [
                    'volume' => max(0.0, (float) $row->raw_volume),
                    'available' => $row->status === 'usable' && (float) $row->raw_volume > 0,
                ],
            ])->all();
    }

    private function assertCanonicalTransport(): void
    {
        if (strtolower((string) config('services.market_volume.provider', 'dukascopy')) !== 'dukascopy'
            || strtolower((string) config('services.market_volume.transport', 'jetta')) !== 'jetta') {
            throw new RuntimeException('Canonical volume faqat Dukascopy Jetta tick-volume contract bilan ishlaydi; fallback taqiqlangan.');
        }

        // The provider supports legacy fallback for price maintenance. Volume
        // research must pin the transport to Jetta inside this process.
        config([
            'services.dukascopy.transport' => 'jetta',
            'services.dukascopy.tick_fallback_enabled' => (bool) config('services.market_volume.tick_fallback_enabled', false),
        ]);
    }

    private function priceStart(string $symbol, string $timeframe): ?CarbonImmutable
    {
        $symbolId = Symbol::query()->where('code', $symbol)->value('id');
        $value = $symbolId ? Candle::query()->where('symbol_id', $symbolId)->where('timeframe', $timeframe)->min('time') : null;
        return $value ? CarbonImmutable::parse($value, 'UTC') : null;
    }

    private function priceEnd(string $symbol, string $timeframe): ?CarbonImmutable
    {
        $symbolId = Symbol::query()->where('code', $symbol)->value('id');
        $value = $symbolId ? Candle::query()->where('symbol_id', $symbolId)->where('timeframe', $timeframe)->max('time') : null;
        if (! $value) return null;
        $end = CarbonImmutable::parse($value, 'UTC');

        return strtoupper($timeframe) === 'M15' ? $end->addMinutes(15) : $end->addHour();
    }

    private function timeKey(mixed $time): string
    {
        return CarbonImmutable::parse($time, 'UTC')->format('Y-m-d H:i:s');
    }

    private function isExpectedVolumeCandle(mixed $time, string $symbol, string $timeframe): bool
    {
        $candle = CarbonImmutable::parse($time, 'UTC');
        if (($candle->month === 1 && $candle->day === 1) || ($candle->month === 12 && $candle->day === 25)) {
            return false;
        }

        // Jetta's UTC open-market calendar is instrument-aware. FX opens
        // Sunday at 21:00 and closes Friday at 21:00. XAU opens Sunday at
        // 23:00, closes Friday at 22:00, and has a daily 22:00 maintenance
        // hour. M15 keeps the minute boundary exact.
        if ($candle->dayOfWeek === CarbonImmutable::SATURDAY) return false;
        if ($symbol === 'XAUUSD') {
            if ($candle->dayOfWeek === CarbonImmutable::SUNDAY) return $candle->hour >= 23;
            if ($candle->dayOfWeek === CarbonImmutable::FRIDAY && $candle->hour >= 22) return false;

            return $candle->hour !== 22;
        }

        if ($candle->dayOfWeek === CarbonImmutable::SUNDAY) return $candle->hour >= 21;
        if ($candle->dayOfWeek === CarbonImmutable::FRIDAY && $candle->hour >= 21) return false;

        return in_array(strtoupper($timeframe), ['H1', 'M15'], true);
    }
}
