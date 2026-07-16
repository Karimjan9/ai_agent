<?php

namespace App\Services\MarketData;

use App\Models\MarketCandleObservation;
use App\Models\MarketDataSyncState;
use App\Models\Candle;
use App\Models\Symbol;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MarketDataAuditService
{
    /** @return array<string, mixed> */
    public function audit(string $provider, string $symbol, string $timeframe): array
    {
        $observations = MarketCandleObservation::query()->where(compact('symbol', 'timeframe'))->latest('time')->take(1000)->get()->sortBy('time')->values();
        if ($observations->isEmpty()) {
            $this->backfillCanonicalObservations($provider, $symbol, $timeframe);
            $observations = MarketCandleObservation::query()->where(compact('symbol', 'timeframe'))->latest('time')->take(1000)->get()->sortBy('time')->values();
        }
        $gaps = $this->unexpectedGaps($observations, $timeframe);
        $providerCounts = $observations->groupBy('provider')->map->count();
        $discrepancy = $this->closeDiscrepancyBps($observations);
        $status = $observations->isEmpty() || $gaps > 0 ? 'warning' : 'passed';
        $metrics = [
            'audit_status' => $status, 'observations' => $observations->count(), 'providers' => $providerCounts,
            'unexpected_gaps' => $gaps, 'close_discrepancy_bps' => $discrepancy,
            'timezone' => 'UTC', 'flat_candles_retained' => true,
        ];

        $state = MarketDataSyncState::query()->where(compact('provider', 'symbol', 'timeframe'))->first();
        $state?->update(['metrics' => array_merge($state->metrics ?? [], ['data_quality' => $metrics])]);

        return $metrics;
    }

    private function unexpectedGaps(Collection $observations, string $timeframe): int
    {
        if ($timeframe !== 'H1' || $observations->count() < 2) return 0;
        $gaps = 0; $previous = null;
        foreach ($observations as $observation) {
            $current = CarbonImmutable::instance($observation->time)->utc();
            if ($previous && $current->diffInHours($previous) > 1 && $previous->dayOfWeek !== CarbonImmutable::FRIDAY) $gaps++;
            $previous = $current;
        }

        return $gaps;
    }

    private function closeDiscrepancyBps(Collection $observations): ?float
    {
        $pairs = $observations->groupBy(fn (MarketCandleObservation $row) => $row->time->format('Y-m-d H:i:s'))->filter(fn (Collection $rows) => $rows->pluck('provider')->unique()->count() > 1);
        if ($pairs->isEmpty()) return null;

        return round((float) $pairs->map(function (Collection $rows): float {
            $min = (float) $rows->min('close'); $max = (float) $rows->max('close');
            return $min > 0 ? (($max - $min) / $min) * 10000 : 0;
        })->avg(), 3);
    }

    private function backfillCanonicalObservations(string $provider, string $symbol, string $timeframe): void
    {
        $symbolId = Symbol::query()->where('code', $symbol)->value('id');
        if (! $symbolId) return;
        $now = now();
        $rows = Candle::query()->where('symbol_id', $symbolId)->where('timeframe', $timeframe)->latest('time')->take(1000)->get()
            ->map(fn (Candle $candle): array => [
                'provider' => $candle->provider ?: $provider, 'symbol' => $symbol, 'timeframe' => $timeframe,
                'time' => $candle->time, 'open' => $candle->open, 'high' => $candle->high, 'low' => $candle->low,
                'close' => $candle->close, 'volume' => $candle->volume ?? 0, 'created_at' => $now, 'updated_at' => $now,
            ])->all();
        if ($rows) MarketCandleObservation::upsert($rows, ['provider', 'symbol', 'timeframe', 'time'], ['open', 'high', 'low', 'close', 'volume', 'updated_at']);
    }
}
