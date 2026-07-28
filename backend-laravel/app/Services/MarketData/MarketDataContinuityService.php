<?php

namespace App\Services\MarketData;

use App\Models\Candle;
use App\Models\MarketDataSyncState;
use App\Models\Symbol;
use Carbon\CarbonImmutable;

class MarketDataContinuityService
{
    public function recoveryStart(string $provider, string $symbol, string $timeframe, ?CarbonImmutable $latest): ?CarbonImmutable
    {
        $state = $this->state($provider, $symbol, $timeframe);

        if ($state->pending_from_at) {
            return CarbonImmutable::instance($state->pending_from_at)->utc();
        }

        return $latest?->addMinutes($this->intervalMinutes($timeframe));
    }

    public function recordFailure(
        string $provider,
        string $symbol,
        string $timeframe,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $error,
    ): void {
        $state = $this->state($provider, $symbol, $timeframe);
        $state->update([
            'status' => 'offline',
            'pending_from_at' => $state->pending_from_at ?: $from,
            'pending_to_at' => $to,
            'retry_count' => $state->retry_count + 1,
            'last_error' => mb_substr($error, 0, 2000),
            'last_attempt_at' => now(),
        ]);
    }

    public function recordResult(
        string $provider,
        string $symbol,
        string $timeframe,
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $saved,
    ): MarketDataSyncState {
        $state = $this->state($provider, $symbol, $timeframe);
        $state->update(['last_attempt_at' => now()]);

        // Historical backfills may span decades. Their data-quality review is
        // separate; live outage recovery only verifies bounded recent ranges.
        if ($from->diffInHours($to) > 72) {
            return $this->markHealthy($state, $symbol, $timeframe, $saved, $to);
        }

        $missing = $this->firstMissingOpenCandle($symbol, $timeframe, $from, $this->lastClosedBoundary($to, $timeframe));
        if ($missing) {
            $state->update([
                'status' => 'catching_up',
                'pending_from_at' => $state->pending_from_at ?: $missing,
                'pending_to_at' => $to,
                'last_error' => null,
                'last_attempt_at' => now(),
                'metrics' => array_merge($state->metrics ?? [], [
                    'last_saved' => $saved,
                    'first_missing_open_candle' => $missing->toIso8601String(),
                    'first_missing_open_hour' => $missing->toIso8601String(),
                ]),
            ]);

            return $state->fresh();
        }

        return $this->markHealthy($state, $symbol, $timeframe, $saved, $to);
    }

    public function isReady(string $provider, string $symbol, string $timeframe): bool
    {
        $state = MarketDataSyncState::query()
            ->where(compact('provider', 'symbol', 'timeframe'))
            ->first();

        return ! $state || $state->status === 'healthy';
    }

    private function markHealthy(MarketDataSyncState $state, string $symbol, string $timeframe, int $saved, CarbonImmutable $to): MarketDataSyncState
    {
        $symbolId = Symbol::query()->where('code', $symbol)->value('id');
        $latest = $symbolId
            ? Candle::query()->where('symbol_id', $symbolId)->where('timeframe', $timeframe)->max('time')
            : null;
        $recovered = $state->status !== 'healthy';

        $state->update([
            'status' => 'healthy',
            'last_confirmed_candle_at' => $latest,
            'pending_from_at' => null,
            'pending_to_at' => null,
            'retry_count' => 0,
            'last_error' => null,
            'last_attempt_at' => now(),
            'last_success_at' => now(),
            'recovered_at' => $recovered ? now() : $state->recovered_at,
            'metrics' => array_merge($state->metrics ?? [], ['last_saved' => $saved, 'last_target_at' => $to->toIso8601String()]),
        ]);

        return $state->fresh();
    }

    private function state(string $provider, string $symbol, string $timeframe): MarketDataSyncState
    {
        return MarketDataSyncState::firstOrCreate(
            compact('provider', 'symbol', 'timeframe'),
            ['status' => 'healthy'],
        );
    }

    private function firstMissingOpenCandle(string $symbol, string $timeframe, CarbonImmutable $from, CarbonImmutable $to): ?CarbonImmutable
    {
        if ($to->lessThan($from)) {
            return null;
        }

        $symbolId = Symbol::query()->where('code', $symbol)->value('id');
        if (! $symbolId) {
            return $from;
        }

        $existing = Candle::query()
            ->where('symbol_id', $symbolId)
            ->where('timeframe', $timeframe)
            ->whereBetween('time', [$from, $to])
            ->pluck('time')
            ->map(fn ($time) => $this->alignToInterval(CarbonImmutable::parse($time, 'UTC'), $timeframe)->format('Y-m-d H:i:s'))
            ->flip();

        $intervalMinutes = $this->intervalMinutes($timeframe);
        for ($cursor = $this->alignToInterval($from, $timeframe); $cursor->lessThanOrEqualTo($to); $cursor = $cursor->addMinutes($intervalMinutes)) {
            if ($this->isMarketOpenCandle($cursor) && ! $existing->has($cursor->format('Y-m-d H:i:s'))) {
                return $cursor;
            }
        }

        return null;
    }

    private function lastClosedBoundary(CarbonImmutable $time, string $timeframe): CarbonImmutable
    {
        return $this->alignToInterval($time, $timeframe)->subMinutes($this->intervalMinutes($timeframe));
    }

    private function isMarketOpenCandle(CarbonImmutable $time): bool
    {
        return match ($time->dayOfWeek) {
            CarbonImmutable::SATURDAY => false,
            CarbonImmutable::SUNDAY => $time->hour >= 22,
            CarbonImmutable::FRIDAY => $time->hour < 22,
            default => true,
        };
    }

    private function intervalMinutes(string $timeframe): int
    {
        return match (strtoupper($timeframe)) {
            'M15' => 15,
            'H1' => 60,
            default => throw new \InvalidArgumentException("Unsupported continuity timeframe: {$timeframe}"),
        };
    }

    private function alignToInterval(CarbonImmutable $time, string $timeframe): CarbonImmutable
    {
        $time = $time->utc();
        $intervalMinutes = $this->intervalMinutes($timeframe);

        return $time->setTime($time->hour, intdiv($time->minute, $intervalMinutes) * $intervalMinutes, 0);
    }
}
