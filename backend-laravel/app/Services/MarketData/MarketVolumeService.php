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
                'protocol' => 'relative_volume_session_v1',
                'global_lookback' => 168,
                'session_lookback' => 20,
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
        while ($cursor->lessThan($to)) {
            $chunkEnd = $cursor->startOfMonth()->addMonths($chunkMonths);
            if ($chunkEnd->lessThanOrEqualTo($cursor)) $chunkEnd = $cursor->addMonth();
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
        $expected = $priceRows->count();
        $first = $priceRows->first()?->time;
        $last = $priceRows->last()?->time;

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
        foreach ($priceRows as $price) {
            $volume = $byTime->get($this->timeKey($price->time));
            if (! $volume) continue;
            $matched++;
            if ((float) $volume->raw_volume > 0 && $volume->status === 'usable') $usable++;
        }
        $coverage = $expected > 0 ? $matched / $expected : 0.0;
        $usableRatio = $expected > 0 ? $usable / $expected : 0.0;
        $lastVolume = $volumeRows->last()?->time;
        $lagSeconds = $last && $lastVolume
            ? max(0, CarbonImmutable::parse($last, 'UTC')->diffInSeconds(CarbonImmutable::parse($lastVolume, 'UTC'), absolute: true))
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
}
