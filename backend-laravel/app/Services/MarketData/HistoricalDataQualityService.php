<?php

namespace App\Services\MarketData;

use App\Models\Candle;
use App\Models\Symbol;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HistoricalDataQualityService
{
    public const FOUNDATION_CONTINUITY_PROTOCOL = 'foundation_continuity_passport_v1';

    /** @return array<string, mixed> */
    public function inspect(string $symbol, string $timeframe = 'H1', bool $fresh = false): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $intervalMinutes = $this->intervalMinutes($timeframe);
        $cacheKey = "historical-data-quality:{$symbol}:{$timeframe}";

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($symbol, $timeframe, $intervalMinutes): array {
            $symbolId = Symbol::query()->where('code', $symbol)->value('id');
            if (! $symbolId) {
                return $this->emptyResult($symbol, $timeframe, 'Symbol is not registered.');
            }

            $query = Candle::query()
                ->where('symbol_id', $symbolId)
                ->where('timeframe', $timeframe);
            $rowCount = (clone $query)->count();
            $first = (clone $query)->min('time');
            $last = (clone $query)->max('time');
            $minimumRows = max(500, (int) config('services.historical_data.minimum_rows', 5000));
            // The existing configuration is expressed in H1-equivalent hours.
            // Convert it to bars so a zero-tolerance gate remains equally strict
            // for M15 without changing the established H1 contract.
            $allowedMissingCandles = max(0, (int) config('services.historical_data.allowed_missing_open_hours', 0)) * (60 / $intervalMinutes);
            $gapExampleLimit = max(10, min(1000, (int) config('services.historical_data.gap_repair_limit', 100)));

            $gapIntervals = 0;
            $missingOpenCandles = 0;
            $largestGapHours = 0;
            $examples = [];

            // Do not hydrate every historical candle merely to find the few
            // discontinuities.  On the 20-year laboratory archives that made
            // an audit take minutes and let scheduler jobs overlap.  The
            // existing unique (symbol_id, timeframe, time) index gives this
            // window query an ordered stream; PHP then applies the canonical
            // market calendar only to candidate intervals.
            foreach ($this->gapCandidates($symbolId, $timeframe, $intervalMinutes * 60) as $candidate) {
                $previous = $this->alignToInterval(CarbonImmutable::parse($candidate->previous_time, 'UTC'), $intervalMinutes);
                $current = $this->alignToInterval(CarbonImmutable::parse($candidate->time, 'UTC'), $intervalMinutes);
                $missing = $this->missingOpenCandles($previous, $current, $symbol, $intervalMinutes);
                if ($missing > 0) {
                    $gapIntervals++;
                    $missingOpenCandles += $missing;
                    $largestGapHours = max($largestGapHours, $previous->diffInHours($current));
                    $examples[] = [
                        'after' => $previous->toIso8601String(),
                        'before' => $current->toIso8601String(),
                        'missing_open_candles' => $missing,
                        // Kept for existing dashboard consumers; for M15 this
                        // is the count of 15-minute candles, not clock-hours.
                        'missing_open_hours' => $missing,
                    ];
                }
            }

            $reasons = [];
            if ($rowCount < $minimumRows) {
                $reasons[] = "Only {$rowCount} rows are available; {$minimumRows} required.";
            }
            if ($missingOpenCandles > $allowedMissingCandles) {
                $reasons[] = "{$missingOpenCandles} market-open {$timeframe} candles are missing across {$gapIntervals} gaps.";
            }

            return [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'status' => $reasons === [] ? 'ready' : 'blocked',
                'row_count' => $rowCount,
                'minimum_rows' => $minimumRows,
                'first_candle_at' => $first,
                'last_candle_at' => $last,
                'gap_intervals' => $gapIntervals,
                'missing_open_candles' => $missingOpenCandles,
                'allowed_missing_open_candles' => $allowedMissingCandles,
                'missing_open_hours' => $missingOpenCandles,
                'allowed_missing_open_hours' => $allowedMissingCandles,
                'largest_gap_hours' => $largestGapHours,
                'gap_examples' => collect($examples)->sortByDesc('missing_open_candles')->take($gapExampleLimit)->values()->all(),
                'reasons' => $reasons,
                'checked_at' => now()->utc()->toIso8601String(),
            ];
        });
    }

    public function ready(string $symbol, string $timeframe = 'H1', bool $fresh = false): bool
    {
        return $this->inspect($symbol, $timeframe, $fresh)['status'] === 'ready';
    }

    /**
     * Full walk-forward replay has a stricter calendar contract than recent
     * screening.  Keep this separate from inspect()/ready(): screening may
     * legitimately use a recent rolling tail, while full validation must have
     * both the historical foundation and the post-2026 rolling segment.
     *
     * The canonical rolling snapshot and the pre-2026 foundation archive are
     * intentionally separate sources.  The optional fourth argument makes
     * that boundary explicit.  When it is omitted, the third argument keeps
     * the legacy combined-dataset behavior for older callers and tests.
     *
     * @param array<string, mixed>|null $rollingManifest Frozen rolling generation manifest, when one already exists.
     * @param array<string, mixed>|null $foundationManifest Frozen foundation manifest, when one already exists.
     * @return array<string, mixed>
     */
    public function fullReplayCoverage(
        string $symbol,
        string $timeframe = 'H1',
        ?array $rollingManifest = null,
        ?array $foundationManifest = null,
    ): array {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        // Dukascopy's first XAU Sunday session can start at 23:00 UTC on
        // 2005-01-02. The export protocol already allows that one-day
        // market-open tolerance; the admission gate must use the same rule.
        $requiredFoundationStart = CarbonImmutable::parse('2005-01-03 00:00:00', 'UTC');
        $requiredFoundationEnd = CarbonImmutable::parse('2025-12-01 00:00:00', 'UTC');
        $requiredRollingStart = CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC');

        $symbolId = Symbol::query()->where('code', $symbol)->value('id');
        $query = $symbolId
            ? Candle::query()->where('symbol_id', $symbolId)->where('timeframe', $timeframe)
            : null;
        $database = [
            'row_count' => $query ? (clone $query)->count() : 0,
            'first_candle_at' => $query ? (clone $query)->min('time') : null,
            'last_candle_at' => $query ? (clone $query)->max('time') : null,
        ];
        $manifestSource = static function (?array $manifest, array $fallback): array {
            if ($manifest === null) {
                return [...$fallback, 'source' => 'database'];
            }

            return [
                'row_count' => (int) ($manifest['row_count'] ?? 0),
                'first_candle_at' => $manifest['first_candle_at'] ?? null,
                'last_candle_at' => $manifest['last_candle_at'] ?? null,
                'source' => 'generation_snapshot',
            ];
        };

        // With no separate foundation manifest, preserve the old contract:
        // the supplied snapshot (or database) is treated as one combined
        // archive and must cover both boundaries.  With a foundation
        // manifest, rolling and foundation are checked independently.
        $foundation = $manifestSource($foundationManifest ?? $rollingManifest, $database);
        $rolling = $manifestSource($rollingManifest, $database);
        $reasons = [];

        $parseTimestamp = static function (mixed $value, array &$reasons): ?CarbonImmutable {
            if ($value === null || $value === '') {
                return null;
            }
            try {
                return CarbonImmutable::parse((string) $value, 'UTC');
            } catch (\Throwable) {
                $reasons[] = 'FULL_REPLAY_DATASET_TIMESTAMP_INVALID';

                return null;
            }
        };
        $foundationFirst = $parseTimestamp($foundation['first_candle_at'], $reasons);
        $foundationLast = $parseTimestamp($foundation['last_candle_at'], $reasons);
        $rollingFirst = $parseTimestamp($rolling['first_candle_at'], $reasons);
        $rollingLast = $parseTimestamp($rolling['last_candle_at'], $reasons);

        if ($foundationManifest !== null) {
            $continuity = data_get($foundationManifest, 'continuity');
            if (! is_array($continuity)
                || data_get($continuity, 'protocol') !== self::FOUNDATION_CONTINUITY_PROTOCOL) {
                $reasons[] = 'FOUNDATION_DATASET_CONTINUITY_PASSPORT_MISSING';
            } elseif (data_get($continuity, 'status') !== 'ready'
                || (int) data_get($continuity, 'unexpected_gap_count', 0) !== 0
                || (int) data_get($continuity, 'missing_open_candles', 0) !== 0) {
                $reasons[] = 'FOUNDATION_DATASET_CONTINUITY_BLOCKED';
            }
        }

        if ($foundationFirst === null || $foundationFirst->greaterThan($requiredFoundationStart)) {
            $reasons[] = 'FOUNDATION_HISTORY_BEFORE_2005_01_02_MARKET_OPEN_REQUIRED';
        }
        if ($foundationLast === null || $foundationLast->lessThan($requiredFoundationEnd)) {
            $reasons[] = 'FOUNDATION_HISTORY_THROUGH_2025_REQUIRED';
        }
        if ((int) $foundation['row_count'] < 202) {
            $reasons[] = 'FOUNDATION_HISTORY_NEEDS_AT_LEAST_202_ROWS';
        }
        if ($rollingLast === null || $rollingLast->lessThan($requiredRollingStart)) {
            $reasons[] = 'ROLLING_HISTORY_FROM_2026_01_01_REQUIRED';
        }
        // Two hundred and two rolling rows cover the replay minimum; two
        // additional rows keep the sealed holdout non-empty under the
        // smallest valid archive.  Larger real manifests are still checked
        // by the Python splitter and the immutable dataset hash.
        if ((int) $rolling['row_count'] < 204) {
            $reasons[] = 'ROLLING_HISTORY_NEEDS_REPLAY_AND_HOLDOUT_ROWS';
        }

        $separateSources = $foundationManifest !== null;
        $source = $separateSources
            ? (($rollingManifest !== null && $foundationManifest !== null) ? 'generation_snapshots' : 'mixed_database_and_generation_snapshot')
            : ($rollingManifest !== null ? 'generation_snapshot' : 'database');
        $firstAt = $foundationFirst;
        $lastAt = $rollingLast;

        return [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'status' => $reasons === [] ? 'ready' : 'blocked',
            'source' => $source,
            'foundation_source' => $foundation['source'],
            'rolling_source' => $rolling['source'],
            'separate_sources' => $separateSources,
            'row_count' => $separateSources
                ? (int) $foundation['row_count'] + (int) $rolling['row_count']
                : (int) $rolling['row_count'],
            'foundation_row_count' => (int) $foundation['row_count'],
            'rolling_row_count' => (int) $rolling['row_count'],
            'first_candle_at' => $firstAt?->toIso8601String(),
            'last_candle_at' => $lastAt?->toIso8601String(),
            'foundation_first_candle_at' => $foundationFirst?->toIso8601String(),
            'foundation_last_candle_at' => $foundationLast?->toIso8601String(),
            'rolling_first_candle_at' => $rollingFirst?->toIso8601String(),
            'rolling_last_candle_at' => $rollingLast?->toIso8601String(),
            'required_foundation_start' => $requiredFoundationStart->toIso8601String(),
            'required_foundation_end' => $requiredFoundationEnd->toIso8601String(),
            'required_rolling_start' => $requiredRollingStart->toIso8601String(),
            'reasons' => array_values(array_unique($reasons)),
            'promotion_evidence' => false,
        ];
    }

    /**
     * Inspect an immutable CSV archive with the same calendar-aware gap
     * contract used by the canonical database quality gate.  Row count and
     * file hash alone are not enough for a foundation archive: one sparse
     * provider outage can otherwise spend hours in replay before Python
     * rejects it.  The resulting passport is persisted inside the archive
     * manifest and is non-promotion evidence.
     *
     * @return array<string, mixed>
     */
    public function inspectCsvContinuity(
        string $path,
        string $symbol,
        string $timeframe = 'H1',
        int $gapExampleLimit = 100,
    ): array {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $intervalMinutes = $this->intervalMinutes($timeframe);
        $gapExampleLimit = max(10, min(1000, $gapExampleLimit));
        $handle = fopen($path, 'rb');

        $base = [
            'protocol' => self::FOUNDATION_CONTINUITY_PROTOCOL,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'status' => 'blocked',
            'row_count' => 0,
            'first_candle_at' => null,
            'last_candle_at' => null,
            'gap_intervals' => 0,
            'unexpected_gap_count' => 0,
            'missing_open_candles' => 0,
            'missing_open_hours' => 0,
            'gap_examples' => [],
            'invalid_rows' => 0,
            'reasons' => [],
            'promotion_evidence' => false,
        ];

        if ($handle === false) {
            $base['reasons'] = ['FOUNDATION_DATASET_CONTINUITY_FILE_UNREADABLE'];

            return $base;
        }

        $rowCount = 0;
        $invalidRows = 0;
        $gapIntervals = 0;
        $missingOpenCandles = 0;
        $gapExamples = [];
        $first = null;
        $last = null;
        $previous = null;
        $headerError = false;

        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers)) {
                $headerError = true;
            } else {
                $headers = array_map(static fn ($header): string => strtolower(trim((string) $header)), $headers);
                $timeIndex = array_search('time', $headers, true);
                if ($timeIndex === false) {
                    $headerError = true;
                }
            }

            if (! $headerError) {
                while (($values = fgetcsv($handle)) !== false) {
                    if ($values === [] || (count($values) === 1 && trim((string) ($values[0] ?? '')) === '')) {
                        continue;
                    }

                    $rowCount++;
                    $rawTime = trim((string) ($values[$timeIndex] ?? ''));
                    try {
                        $time = CarbonImmutable::parse($rawTime, 'UTC')->utc();
                    } catch (\Throwable) {
                        $invalidRows++;
                        continue;
                    }

                    if ($first === null) {
                        $first = $time;
                    }
                    if ($time->minute % $intervalMinutes !== 0 || $time->second !== 0) {
                        $invalidRows++;
                    }

                    if ($previous !== null) {
                        if (! $time->greaterThan($previous)) {
                            $invalidRows++;
                        } elseif ($previous->diffInMinutes($time) > $intervalMinutes) {
                            $missing = $this->missingOpenCandles($previous, $time, $symbol, $intervalMinutes);
                            if ($missing > 0) {
                                $gapIntervals++;
                                $missingOpenCandles += $missing;
                                if (count($gapExamples) < $gapExampleLimit) {
                                    $gapExamples[] = [
                                        'after' => $previous->toIso8601String(),
                                        'before' => $time->toIso8601String(),
                                        'missing_open_candles' => $missing,
                                        'missing_open_hours' => $missing,
                                    ];
                                }
                            }
                        }
                    }

                    $previous = $time;
                    $last = $time;
                }
            }
        } finally {
            fclose($handle);
        }

        $reasons = [];
        if ($headerError) {
            $reasons[] = 'FOUNDATION_DATASET_CONTINUITY_HEADER_INVALID';
        }
        if ($invalidRows > 0) {
            $reasons[] = 'FOUNDATION_DATASET_CONTINUITY_INVALID_ROWS';
        }
        if ($missingOpenCandles > 0) {
            $reasons[] = 'FOUNDATION_DATASET_CONTINUITY_GAPS';
        }

        $passport = [
            ...$base,
            'status' => $reasons === [] ? 'ready' : 'blocked',
            'row_count' => $rowCount,
            'first_candle_at' => $first?->toIso8601String(),
            'last_candle_at' => $last?->toIso8601String(),
            'gap_intervals' => $gapIntervals,
            'unexpected_gap_count' => $gapIntervals,
            'missing_open_candles' => $missingOpenCandles,
            'missing_open_hours' => $missingOpenCandles,
            'gap_examples' => $gapExamples,
            'invalid_rows' => $invalidRows,
            'reasons' => $reasons,
            'checked_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ];
        $passport['continuity_digest'] = hash('sha256', json_encode([
            'protocol' => self::FOUNDATION_CONTINUITY_PROTOCOL,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'row_count' => $rowCount,
            'first_candle_at' => $passport['first_candle_at'],
            'last_candle_at' => $passport['last_candle_at'],
            'gap_intervals' => $gapIntervals,
            'missing_open_candles' => $missingOpenCandles,
            'invalid_rows' => $invalidRows,
            'gap_examples' => $gapExamples,
        ], JSON_UNESCAPED_SLASHES));

        return $passport;
    }

    /** @return iterable<object{time: string, previous_time: string}> */
    private function gapCandidates(int $symbolId, string $timeframe, int $intervalSeconds): iterable
    {
        $ordered = Candle::query()
            ->select('time')
            ->selectRaw('LAG(time) OVER (ORDER BY time) AS previous_time')
            ->where('symbol_id', $symbolId)
            ->where('timeframe', $timeframe);
        $driver = DB::connection()->getDriverName();

        $candidates = DB::query()
            ->fromSub($ordered, 'ordered_candles')
            ->select(['time', 'previous_time'])
            ->whereNotNull('previous_time');

        if ($driver === 'sqlite') {
            return $candidates
                ->whereRaw("(strftime('%s', time) - strftime('%s', previous_time)) > ?", [$intervalSeconds])
                ->orderBy('time')
                ->cursor();
        }

        return $candidates
            ->whereRaw('TIMESTAMPDIFF(SECOND, previous_time, time) > ?', [$intervalSeconds])
            ->orderBy('time')
            ->cursor();
    }

    private function missingOpenCandles(CarbonImmutable $previous, CarbonImmutable $current, string $symbol, int $intervalMinutes): int
    {
        if ($this->isScheduledClosure($previous, $current, $symbol)) {
            return 0;
        }

        $missing = 0;
        for ($cursor = $previous->addMinutes($intervalMinutes); $cursor->lessThan($current); $cursor = $cursor->addMinutes($intervalMinutes)) {
            if ($this->isExpectedMarketOpen($cursor, $symbol)) {
                $missing++;
            }
        }

        return $missing;
    }

    public function isExpectedMarketOpen(CarbonImmutable $time, string $symbol): bool
    {
        if (($time->month === 1 && $time->day === 1) || ($time->month === 12 && $time->day === 25)) {
            return false;
        }

        if (! str_starts_with($symbol, 'XAU')
            && $time->month === 12
            && $time->day === 24
            && $time->hour >= 13) {
            return false;
        }

        if (str_starts_with($symbol, 'XAU')) {
            // Preserve the archive's historical 00:00 UTC maintenance hole
            // and account for the current 17:00 New York daily maintenance
            // hour (21:00/22:00 UTC depending on DST).
            if ($time->hour === 0 || $time->setTimezone('America/New_York')->hour === 17) {
                return false;
            }
        }

        return match ($time->dayOfWeek) {
            CarbonImmutable::SATURDAY => false,
            CarbonImmutable::SUNDAY => false,
            CarbonImmutable::FRIDAY => $time->hour < 21,
            default => true,
        };
    }

    public function isContinuityMarketOpen(CarbonImmutable $time, string $symbol): bool
    {
        $time = $time->utc();

        if (($time->month === 1 && $time->day === 1) || ($time->month === 12 && $time->day === 25)) {
            return false;
        }

        // Continuity observes the Sunday 22:00 UTC reopen and the FX Friday
        // 21:00 UTC candle; historical archive quality intentionally keeps
        // those session-boundary hours conservative.
        if ($time->dayOfWeek === CarbonImmutable::SUNDAY && $time->hour >= 22) {
            return true;
        }

        if (! str_starts_with(strtoupper($symbol), 'XAU')
            && $time->dayOfWeek === CarbonImmutable::FRIDAY
            && $time->hour === 21
            && ! ($time->month === 12 && $time->day === 24)) {
            return true;
        }

        return $this->isExpectedMarketOpen($time, $symbol);
    }

    public function isScheduledClosure(CarbonImmutable $previous, CarbonImmutable $current, string $symbol): bool
    {
        $duration = $previous->diffInHours($current);
        if ($duration <= 96
            && $previous->dayOfWeek === CarbonImmutable::FRIDAY
            && in_array($current->dayOfWeek, [CarbonImmutable::SUNDAY, CarbonImmutable::MONDAY], true)) {
            return true;
        }

        // Dukascopy's FX archive consistently closes early on New Year's Eve
        // and resumes after New Year's Day (or after the adjacent weekend).
        // Those absent hours are a scheduled session closure, not data loss.
        if ($duration <= 100
            && $previous->month === 12
            && $previous->day === 31
            && $current->year === $previous->year + 1
            && $current->month === 1
            && $current->day <= 3) {
            return true;
        }

        // The canonical FX archive closes from the Christmas-Eve afternoon
        // session through the Christmas holiday. Treat that provider/session
        // boundary as a scheduled closure; otherwise a valid Twelve archive
        // is incorrectly rejected as if its 11 absent H1 bars were a feed
        // outage.
        if ($duration <= 48 && $this->crossesFxChristmasClosure($previous, $current, $symbol)) {
            return true;
        }

        if (str_starts_with($symbol, 'XAU')
            && $duration <= 120
            && $this->crossesXauMarketHoliday($previous, $current)) {
            return true;
        }

        if (str_starts_with($symbol, 'XAU')
            && $duration <= 8
            && $previous->month === 12
            && $previous->day === 31
            && $current->isSameDay($previous)) {
            return true;
        }

        // The Dukascopy spot-metal archive has a recurring one-hour daily
        // maintenance window represented as 23:00 -> 01:00 UTC.
        return str_starts_with($symbol, 'XAU')
            && $duration <= 3
            && $previous->hour === 23
            && $current->hour === 1;
    }

    private function crossesFxChristmasClosure(CarbonImmutable $previous, CarbonImmutable $current, string $symbol): bool
    {
        if (str_starts_with($symbol, 'XAU')) return false;

        $previousIsChristmasEve = $previous->month === 12
            && $previous->day === 24
            && $previous->hour >= 12;
        $currentIsChristmasDay = $current->month === 12 && $current->day === 25;
        $previousIsChristmasDay = $previous->month === 12 && $previous->day === 25;
        $currentIsDayAfterChristmas = $current->month === 12 && $current->day === 26;

        return ($previousIsChristmasEve && ($currentIsChristmasDay || $current->day === 24))
            || ($previousIsChristmasDay && ($currentIsChristmasDay || $currentIsDayAfterChristmas));
    }

    private function crossesXauMarketHoliday(CarbonImmutable $previous, CarbonImmutable $current): bool
    {
        for ($date = $previous->startOfDay(); $date->lessThanOrEqualTo($current->startOfDay()); $date = $date->addDay()) {
            if ($this->isXauMarketHoliday($date)) {
                return true;
            }
        }

        return false;
    }

    private function isXauMarketHoliday(CarbonImmutable $date): bool
    {
        $year = $date->year;
        $holidays = [
            $this->observedFixedHoliday($year, 1, 1),
            $this->nthWeekdayOfMonth($year, 1, CarbonImmutable::MONDAY, 3),
            $this->nthWeekdayOfMonth($year, 2, CarbonImmutable::MONDAY, 3),
            CarbonImmutable::createFromTimestampUTC(easter_date($year))->subDays(3)->startOfDay(),
            CarbonImmutable::createFromTimestampUTC(easter_date($year))->subDays(2)->startOfDay(),
            $this->lastWeekdayOfMonth($year, 5, CarbonImmutable::MONDAY),
            $this->observedFixedHoliday($year, 7, 4),
            $this->nthWeekdayOfMonth($year, 9, CarbonImmutable::MONDAY, 1),
            $this->nthWeekdayOfMonth($year, 11, CarbonImmutable::THURSDAY, 4),
            $this->observedFixedHoliday($year, 12, 25),
        ];

        if ($year >= 2022) {
            $holidays[] = $this->observedFixedHoliday($year, 6, 19);
        }

        return collect($holidays)->contains(fn (CarbonImmutable $holiday): bool => $date->isSameDay($holiday));
    }

    private function intervalMinutes(string $timeframe): int
    {
        return match ($timeframe) {
            'M15' => 15,
            'H1' => 60,
            default => throw new \InvalidArgumentException("Unsupported historical-data timeframe: {$timeframe}"),
        };
    }

    private function alignToInterval(CarbonImmutable $time, int $intervalMinutes): CarbonImmutable
    {
        return $time->utc()->setTime($time->hour, intdiv($time->minute, $intervalMinutes) * $intervalMinutes, 0);
    }

    private function observedFixedHoliday(int $year, int $month, int $day): CarbonImmutable
    {
        $holiday = CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');

        return match ($holiday->dayOfWeek) {
            CarbonImmutable::SATURDAY => $holiday->subDay(),
            CarbonImmutable::SUNDAY => $holiday->addDay(),
            default => $holiday,
        };
    }

    private function nthWeekdayOfMonth(int $year, int $month, int $weekday, int $nth): CarbonImmutable
    {
        $first = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC');
        $offset = ($weekday - $first->dayOfWeek + 7) % 7;

        return $first->addDays($offset + (($nth - 1) * 7));
    }

    private function lastWeekdayOfMonth(int $year, int $month, int $weekday): CarbonImmutable
    {
        $last = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')->endOfMonth()->startOfDay();
        $offset = ($last->dayOfWeek - $weekday + 7) % 7;

        return $last->subDays($offset);
    }

    /** @return array<string, mixed> */
    private function emptyResult(string $symbol, string $timeframe, string $reason): array
    {
        return [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'status' => 'blocked',
            'row_count' => 0,
            'minimum_rows' => (int) config('services.historical_data.minimum_rows', 5000),
            'first_candle_at' => null,
            'last_candle_at' => null,
            'gap_intervals' => 0,
            'missing_open_candles' => 0,
            'allowed_missing_open_candles' => 0,
            'missing_open_hours' => 0,
            'allowed_missing_open_hours' => 0,
            'largest_gap_hours' => 0,
            'gap_examples' => [],
            'reasons' => [$reason],
            'checked_at' => now()->utc()->toIso8601String(),
        ];
    }
}
