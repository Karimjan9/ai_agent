<?php

namespace App\Services\MarketData;

use App\Models\Candle;
use App\Models\MarketSymbol;
use App\Models\MarketCandleObservation;
use App\Models\Symbol;
use App\Services\MarketRealityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class MarketDataService
{
    public function __construct(
        private CsvMarketDataProvider $csvProvider,
        private DukascopyMarketDataProvider $dukascopyProvider,
        private TwelveDataMarketDataProvider $twelveDataProvider,
        private MarketRealityService $marketRealityService,
        private MarketDataContinuityService $continuity,
    ) {}

    public function updateCandles(
        MarketSymbol $marketSymbol,
        string $timeframe = 'H1',
        int $limit = 1000,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): int
    {
        $providerKey = (string) config('services.market_data.provider', 'csv');
        $symbol = Symbol::updateOrCreate(
            ['code' => $marketSymbol->symbol],
            [
                'display_name' => $marketSymbol->name ?? $marketSymbol->symbol,
                'asset_class' => $marketSymbol->market_type,
                'is_active' => $marketSymbol->is_active,
            ],
        );

        if ($this->usesContinuity($providerKey)) {
            $latest = Candle::query()
                ->where('symbol_id', $symbol->id)
                ->where('timeframe', $timeframe)
                ->max('time');
            $latestAt = $latest ? CarbonImmutable::parse($latest, 'UTC') : null;
            $from = $from
                ? CarbonImmutable::instance($from)->utc()
                : $this->continuity->recoveryStart($providerKey, $marketSymbol->symbol, $timeframe, $latestAt);
            // Providers publish only completed candles. Request an exact
            // timeframe boundary instead of a moving current timestamp.
            $to = $to
                ? CarbonImmutable::instance($to)->utc()
                : $this->currentIntervalBoundary($timeframe);

            // There is nothing to fetch until the next candle closes. A
            // `latest + interval == current boundary` interval is a
            // healthy no-op, not a provider outage.
            if ($from && $from->greaterThanOrEqualTo($to)) {
                $this->continuity->recordResult(
                    $providerKey,
                    $marketSymbol->symbol,
                    $timeframe,
                    $from,
                    $to,
                    0,
                );

                return 0;
            }
        }

        try {
            [$candles, $usedProvider] = $this->fetchWithFallback(
                $providerKey, $marketSymbol, $timeframe, $limit, $from, $to,
            );
        } catch (Throwable $exception) {
            if ($this->usesContinuity($providerKey)) {
                $attemptFrom = $from ?? CarbonImmutable::now('UTC')->subHours($limit);
                $attemptTo = $to ? CarbonImmutable::instance($to)->utc() : CarbonImmutable::now('UTC');
                $this->continuity->recordFailure($providerKey, $marketSymbol->symbol, $timeframe, $attemptFrom, $attemptTo, $exception->getMessage());
            }

            throw $exception;
        }

        // A successful process with an empty payload is still a failed live
        // update when the requested interval contains closed market hours. Do
        // not silently mark it as a partial recovery or spend minutes running
        // market-reality analysis over unchanged data.
        if ($this->usesContinuity($providerKey) && empty($candles)) {
            $attemptFrom = $from ?? CarbonImmutable::now('UTC')->subHours($limit);
            $attemptTo = $to ? CarbonImmutable::instance($to)->utc() : CarbonImmutable::now('UTC');
            $graceMinutes = max(5, (int) config('services.'.$providerKey.'.empty_response_grace_minutes', config('services.dukascopy.empty_response_grace_minutes', 15)));

            // A provider can legitimately lag just after the candle close.
            // Persist a retryable gap without flagging the entire feed offline.
            if ($attemptTo->greaterThan(CarbonImmutable::now('UTC')->subMinutes($graceMinutes))) {
                $this->continuity->recordResult($providerKey, $marketSymbol->symbol, $timeframe, $attemptFrom, $attemptTo, 0);

                return 0;
            }

            $exception = new RuntimeException('Dukascopy returned no candles for a closed interval.');
            $this->continuity->recordFailure($providerKey, $marketSymbol->symbol, $timeframe, $attemptFrom, $attemptTo, $exception->getMessage());

            throw $exception;
        }

        $now = now();
        $rows = collect($candles)
            ->map(fn (array $candle): array => [
                'symbol_id' => $symbol->id,
                'timeframe' => $timeframe,
                'time' => $candle['time'],
                'open' => $candle['open'],
                'high' => $candle['high'],
                'low' => $candle['low'],
                'close' => $candle['close'],
                'volume' => $candle['volume'] ?? 0,
                'provider' => $usedProvider,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values();

        if ($rows->isNotEmpty()) {
            $rows->map(fn (array $row): array => [
                'provider' => $usedProvider, 'symbol' => $marketSymbol->symbol, 'timeframe' => $timeframe,
                'time' => $row['time'], 'open' => $row['open'], 'high' => $row['high'], 'low' => $row['low'],
                'close' => $row['close'], 'volume' => $row['volume'], 'created_at' => $now, 'updated_at' => $now,
            ])->chunk(1000)->each(fn ($chunk) => MarketCandleObservation::upsert(
                $chunk->all(),
                ['provider', 'symbol', 'timeframe', 'time'], ['open', 'high', 'low', 'close', 'volume', 'updated_at'],
            ));
            $rows->chunk(1000)->each(fn ($chunk) => Candle::upsert(
                $chunk->all(),
                ['symbol_id', 'timeframe', 'time'],
                ['open', 'high', 'low', 'close', 'volume', 'provider', 'updated_at'],
            ));
        }

        if ($this->usesContinuity($providerKey)) {
            $attemptFrom = $from ?? CarbonImmutable::now('UTC')->subHours($limit);
            $attemptTo = $to ? CarbonImmutable::instance($to)->utc() : CarbonImmutable::now('UTC');
            $state = $this->continuity->recordResult($providerKey, $marketSymbol->symbol, $timeframe, $attemptFrom, $attemptTo, $rows->count());
            $state->update(['metrics' => array_merge($state->metrics ?? [], ['last_provider' => $usedProvider])]);
        }

        // Rebuilding the same snapshot window after an empty/no-op poll makes
        // a feed worker spend most of its time in database writes. Analyse only
        // when fresh candles arrived; scheduled reality verification remains
        // responsible for broader periodic audits.
        if ($rows->isNotEmpty() && (config('services.secondary_intelligence.enabled', false) || app()->environment('testing'))) {
            try {
                $this->marketRealityService->analyzeSymbol(
                    $symbol,
                    $timeframe,
                    min($limit, (int) config('services.market_reality.analysis_limit', 60)),
                );
            } catch (Throwable $e) {
                Log::warning('Market reality analysis failed after candle update.', [
                    'symbol' => $marketSymbol->symbol,
                    'timeframe' => $timeframe,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $rows->count();
    }

    /** @return array{0: array, 1: string} */
    private function fetchWithFallback(string $providerKey, MarketSymbol $marketSymbol, string $timeframe, int $limit, ?\DateTimeInterface $from, ?\DateTimeInterface $to): array
    {
        $fetch = fn (string $key): array => $this->resolveProvider($key)->fetchCandles(
            symbol: $marketSymbol->symbol,
            providerSymbol: $marketSymbol->provider_symbol ?? $marketSymbol->symbol,
            timeframe: $timeframe,
            limit: $limit,
            from: $from,
            to: $to,
        );

        try {
            $candles = $fetch($providerKey);
            if ($candles !== [] || ! $this->usesContinuity($providerKey)) {
                return [$candles, $providerKey];
            }
            $primaryReturnedEmpty = true;
        } catch (Throwable $primaryError) {
            $primaryFailure = $primaryError;
        }

        $fallback = strtolower(trim((string) config('services.market_data.fallback_provider', '')));
        if ($fallback === '' || $fallback === $providerKey) {
            if (isset($primaryReturnedEmpty)) {
                return [[], $providerKey];
            }

            throw $primaryFailure;
        }

        try {
            $candles = $fetch($fallback);
            if ($candles === []) {
                throw new RuntimeException("Fallback {$fallback} returned no candles.");
            }

            Log::warning('Market data fallback used.', ['primary' => $providerKey, 'fallback' => $fallback, 'symbol' => $marketSymbol->symbol, 'timeframe' => $timeframe]);

            return [$candles, $fallback];
        } catch (Throwable $fallbackError) {
            throw new RuntimeException('Primary/fallback market data failed: '.($primaryFailure?->getMessage() ?? 'empty primary response').' | '.$fallbackError->getMessage(), previous: $fallbackError);
        }
    }

    private function resolveProvider(?string $provider = null): MarketDataProviderInterface
    {
        $provider ??= config('services.market_data.provider', 'csv');

        return match ($provider) {
            'csv' => $this->csvProvider,
            'dukascopy' => $this->dukascopyProvider,
            'twelve' => $this->twelveDataProvider,
            default => throw new RuntimeException("Market data provider topilmadi: {$provider}"),
        };
    }

    private function usesContinuity(string $provider): bool
    {
        return in_array($provider, ['dukascopy', 'twelve'], true);
    }

    private function currentIntervalBoundary(string $timeframe): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');

        return match (strtoupper($timeframe)) {
            'H1' => $now->startOfHour(),
            'M15' => $now->setTime($now->hour, intdiv($now->minute, 15) * 15, 0),
            default => throw new RuntimeException("Unsupported market-data timeframe: {$timeframe}"),
        };
    }
}
