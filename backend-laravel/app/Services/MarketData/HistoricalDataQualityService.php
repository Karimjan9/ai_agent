<?php

namespace App\Services\MarketData;

use App\Models\Candle;
use App\Models\Symbol;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HistoricalDataQualityService
{
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

    private function isExpectedMarketOpen(CarbonImmutable $time, string $symbol): bool
    {
        if (($time->month === 1 && $time->day === 1) || ($time->month === 12 && $time->day === 25)) {
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

    private function isScheduledClosure(CarbonImmutable $previous, CarbonImmutable $current, string $symbol): bool
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
