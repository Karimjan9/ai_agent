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
        $allObservations = MarketCandleObservation::query()->where(compact('symbol', 'timeframe'))->latest('time')->take(2000)->get()->sortBy('time')->values();
        $observations = $allObservations->where('provider', $provider)->values();
        if ($observations->isEmpty()) {
            $this->backfillCanonicalObservations($provider, $symbol, $timeframe);
            $allObservations = MarketCandleObservation::query()->where(compact('symbol', 'timeframe'))->latest('time')->take(2000)->get()->sortBy('time')->values();
            $observations = $allObservations->where('provider', $provider)->values();
        }
        $gaps = $this->unexpectedGaps($observations, $timeframe);
        // Observations are an audit ledger, whereas candles are the canonical
        // market-data store. A partial earlier audit/import must not turn an
        // otherwise complete canonical candle series into a false P0 warning.
        // Reconcile just the audited tail, then evaluate it again.
        if ($gaps > 0) {
            $this->backfillCanonicalObservations($provider, $symbol, $timeframe);
            $allObservations = MarketCandleObservation::query()->where(compact('symbol', 'timeframe'))->latest('time')->take(2000)->get()->sortBy('time')->values();
            $observations = $allObservations->where('provider', $provider)->values();
            $gaps = $this->unexpectedGaps($observations, $timeframe);
        }
        $providerCounts = $allObservations->groupBy('provider')->map->count();
        $discrepancy = $this->closeDiscrepancyBps($allObservations);
        $status = $observations->isEmpty() || $gaps > 0 ? 'warning' : 'passed';
        $metrics = [
            'audit_status' => $status, 'canonical_provider' => $provider, 'canonical_observations' => $observations->count(),
            'observations' => $allObservations->count(), 'providers' => $providerCounts,
            'unexpected_gaps' => $gaps, 'close_discrepancy_bps' => $discrepancy,
            'timezone' => 'UTC', 'flat_candles_retained' => true,
            'secondary_provider_discrepancy_observed' => $discrepancy !== null,
        ];

        $state = MarketDataSyncState::query()->where(compact('provider', 'symbol', 'timeframe'))->first();
        $state?->update(['metrics' => array_merge($state->metrics ?? [], ['data_quality' => $metrics])]);

        return $metrics;
    }

    private function unexpectedGaps(Collection $observations, string $timeframe): int
    {
        if (! in_array(strtoupper($timeframe), ['H1', 'M15'], true) || $observations->count() < 2) return 0;
        $intervalMinutes = strtoupper($timeframe) === 'M15' ? 15 : 60;
        $gaps = 0; $previous = null;
        foreach ($observations as $observation) {
            $current = CarbonImmutable::instance($observation->time)->utc();
            if ($previous && $this->hasUnexpectedGap($previous, $current, $intervalMinutes)) $gaps++;
            $previous = $current;
        }

        return $gaps;
    }

    private function hasUnexpectedGap(CarbonImmutable $previous, CarbonImmutable $current, int $intervalMinutes): bool
    {
        if ($current->lessThanOrEqualTo($previous->addMinutes($intervalMinutes))) {
            return false;
        }

        for ($cursor = $previous->addMinutes($intervalMinutes); $cursor->lessThan($current); $cursor = $cursor->addMinutes($intervalMinutes)) {
            if ($this->isExpectedMarketOpen($cursor)) {
                return true;
            }
        }

        return false;
    }

    private function isExpectedMarketOpen(CarbonImmutable $time): bool
    {
        return match ($time->dayOfWeek) {
            CarbonImmutable::SATURDAY => false,
            CarbonImmutable::SUNDAY => $time->hour >= 22,
            CarbonImmutable::FRIDAY => $time->hour < 22,
            default => true,
        };
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
        $rows = Candle::query()->where('symbol_id', $symbolId)->where('timeframe', $timeframe)->where('provider', $provider)->latest('time')->take(1000)->get()
            ->map(fn (Candle $candle): array => [
                'provider' => $provider, 'symbol' => $symbol, 'timeframe' => $timeframe,
                'time' => $candle->time, 'open' => $candle->open, 'high' => $candle->high, 'low' => $candle->low,
                'close' => $candle->close, 'volume' => $candle->volume ?? 0, 'created_at' => $now, 'updated_at' => $now,
            ])->all();
        if ($rows) MarketCandleObservation::upsert($rows, ['provider', 'symbol', 'timeframe', 'time'], ['open', 'high', 'low', 'close', 'volume', 'updated_at']);
    }
}
