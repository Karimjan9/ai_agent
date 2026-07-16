<?php

namespace App\Services\MarketData;

use App\Models\Candle;
use App\Models\Symbol;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class HistoricalDataQualityService
{
    /** @return array<string, mixed> */
    public function inspect(string $symbol, string $timeframe = 'H1', bool $fresh = false): array
    {
        $symbol = strtoupper($symbol);
        $cacheKey = "historical-data-quality:{$symbol}:{$timeframe}";

        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($symbol, $timeframe): array {
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
            $allowedMissingHours = max(0, (int) config('services.historical_data.allowed_missing_open_hours', 0));

            $previous = null;
            $gapIntervals = 0;
            $missingOpenHours = 0;
            $largestGapHours = 0;
            $examples = [];

            foreach ((clone $query)->orderBy('time')->orderBy('id')->cursor() as $candle) {
                $current = CarbonImmutable::parse($candle->time, 'UTC')->startOfHour();
                if ($previous && $current->greaterThan($previous->addHour())) {
                    $missing = $this->missingOpenHours($previous, $current, $symbol);
                    if ($missing > 0) {
                        $gapIntervals++;
                        $missingOpenHours += $missing;
                        $largestGapHours = max($largestGapHours, $previous->diffInHours($current));
                        $examples[] = [
                            'after' => $previous->toIso8601String(),
                            'before' => $current->toIso8601String(),
                            'missing_open_hours' => $missing,
                        ];
                    }
                }
                $previous = $current;
            }

            $reasons = [];
            if ($rowCount < $minimumRows) {
                $reasons[] = "Only {$rowCount} rows are available; {$minimumRows} required.";
            }
            if ($missingOpenHours > $allowedMissingHours) {
                $reasons[] = "{$missingOpenHours} market-open H1 candles are missing across {$gapIntervals} gaps.";
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
                'missing_open_hours' => $missingOpenHours,
                'allowed_missing_open_hours' => $allowedMissingHours,
                'largest_gap_hours' => $largestGapHours,
                'gap_examples' => collect($examples)->sortByDesc('missing_open_hours')->take(10)->values()->all(),
                'reasons' => $reasons,
                'checked_at' => now()->utc()->toIso8601String(),
            ];
        });
    }

    public function ready(string $symbol, string $timeframe = 'H1', bool $fresh = false): bool
    {
        return $this->inspect($symbol, $timeframe, $fresh)['status'] === 'ready';
    }

    private function missingOpenHours(CarbonImmutable $previous, CarbonImmutable $current, string $symbol): int
    {
        if ($this->isScheduledClosure($previous, $current, $symbol)) {
            return 0;
        }

        $missing = 0;
        for ($cursor = $previous->addHour(); $cursor->lessThan($current); $cursor = $cursor->addHour()) {
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

        if (str_starts_with($symbol, 'XAU') && $time->hour === 0) {
            return false;
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

        // The Dukascopy spot-metal archive has a recurring one-hour daily
        // maintenance window represented as 23:00 -> 01:00 UTC.
        return str_starts_with($symbol, 'XAU')
            && $duration <= 3
            && $previous->hour === 23
            && $current->hour === 1;
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
            'missing_open_hours' => 0,
            'allowed_missing_open_hours' => 0,
            'largest_gap_hours' => 0,
            'gap_examples' => [],
            'reasons' => [$reason],
            'checked_at' => now()->utc()->toIso8601String(),
        ];
    }
}
