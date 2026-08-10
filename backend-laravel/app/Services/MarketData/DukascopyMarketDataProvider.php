<?php

namespace App\Services\MarketData;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class DukascopyMarketDataProvider implements MarketDataProviderInterface
{
    public function fetchCandles(
        string $symbol,
        string $providerSymbol,
        string $timeframe,
        int $limit = 1000,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array {
        if (! in_array(strtoupper($timeframe), ['H1', 'M15'], true)) {
            throw new RuntimeException("Dukascopy timeframe qo'llab-quvvatlanmaydi: {$timeframe}");
        }

        $from ??= CarbonImmutable::now('UTC')->subHours($limit);
        $to ??= CarbonImmutable::now('UTC');

        if ($from >= $to) {
            return [];
        }

        $instrument = $this->instrument($symbol, $providerSymbol);
        $chunkDays = max(1, (int) config('services.dukascopy.chunk_days', 7));
        $liveChunkHours = max(0, (int) config('services.dukascopy.live_chunk_hours', 0));
        $transport = strtolower((string) config('services.dukascopy.transport', 'jetta'));
        $cursor = CarbonImmutable::instance($from)->utc();
        $end = CarbonImmutable::instance($to)->utc();
        $isLiveRecovery = $cursor->diffInHours($end) <= 72;
        $candles = [];

        while ($cursor < $end) {
            // Jetta stores H1 history in monthly resources. Aligning requests
            // to month boundaries prevents the same month from being fetched
            // repeatedly during a large historical repair.
            if (strtoupper($timeframe) === 'H1' && $transport !== 'legacy') {
                $chunkEnd = $cursor->startOfMonth()->addMonth();
            } else {
                $chunkEnd = $isLiveRecovery && $liveChunkHours > 0
                    ? $cursor->addHours($liveChunkHours)
                    : $cursor->addDays($chunkDays);
            }
            if ($chunkEnd > $end) {
                $chunkEnd = $end;
            }

            $candles = array_merge(
                $candles,
                $this->fetchChunk($instrument, $timeframe, $cursor, $chunkEnd),
            );

            $cursor = $chunkEnd;
        }

        return $candles;
    }

    /**
     * @return array<int, array{time: string, open: float, high: float, low: float, close: float, volume: float}>
     */
    private function fetchChunk(
        string $instrument,
        string $timeframe,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $transport = strtolower((string) config('services.dukascopy.transport', 'jetta'));

        if (strtoupper($timeframe) === 'M15' && $transport !== 'legacy') {
            try {
                return (bool) config('services.dukascopy.m15_node_enabled', true)
                    ? $this->fetchJettaM15NodeChunk($instrument, $from, $to)
                    : $this->fetchJettaM15Chunk($instrument, $from, $to);
            } catch (Throwable $exception) {
                if ($transport !== 'auto') {
                    throw $exception;
                }

                Log::warning('Dukascopy Jetta M15 failed; trying legacy datafeed.', [
                    'from' => $from->toDateTimeString(),
                    'to' => $to->toDateTimeString(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (strtoupper($timeframe) === 'H1' && $transport !== 'legacy') {
            try {
                return $this->fetchJettaChunk($instrument, $from, $to);
            } catch (Throwable $exception) {
                if ($transport !== 'auto') {
                    throw $exception;
                }

                Log::warning('Dukascopy Jetta failed; trying legacy datafeed.', [
                    'from' => $from->toDateTimeString(),
                    'to' => $to->toDateTimeString(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $this->fetchLegacyChunk($instrument, $timeframe, $from, $to);
    }

    /**
     * Jetta exposes minute candles with the same canonical tick-volume
     * semantic as its hourly archive. Aggregate those closed minute rows into
     * M15 locally so the research contract does not depend on a legacy node
     * downloader or a provider-specific M15 endpoint.
     *
     * @return array<int, array{time: string, open: float, high: float, low: float, close: float, volume: float}>
     */
    private function fetchJettaM15Chunk(
        string $instrument,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $normalized = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $instrument));
        if (strlen($normalized) !== 6) {
            throw new RuntimeException("Dukascopy Jetta instrument qo'llab-quvvatlanmaydi: {$instrument}");
        }

        $code = substr($normalized, 0, 3).'-'.substr($normalized, 3);
        $baseUrl = rtrim((string) config('services.dukascopy.jetta_base_url', 'https://jetta.dukascopy.com'), '/');
        $cursor = $from->startOfDay();
        $candles = [];

        while ($cursor->lessThan($to)) {
            $dayEnd = $cursor->addDay();
            $url = $dayEnd->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
                ? sprintf(
                    '%s/v1/candles/minute/%s/BID/%d/%d/%d',
                    $baseUrl,
                    $code,
                    $cursor->year,
                    $cursor->month,
                    $cursor->day,
                )
                : sprintf(
                    '%s/v1/candles/minute/%s/BID?from=%d',
                    $baseUrl,
                    $code,
                    $cursor->getTimestampMs(),
                );
            $payload = $this->requestJettaJson($url);
            $minuteRows = $this->decodeJettaMinutePayload($payload, $cursor->startOfDay(), $dayEnd);

            foreach ($minuteRows as $row) {
                $minute = CarbonImmutable::parse($row['time'], 'UTC');
                $bucket = $minute->startOfHour()->addMinutes(intdiv($minute->minute, 15) * 15);
                if ($bucket->lessThan($from) || ! $bucket->lessThan($to)) {
                    continue;
                }

                $key = $bucket->format('Y-m-d H:i:s');
                if (! isset($candles[$key])) {
                    $candles[$key] = [
                        'time' => $key,
                        'open' => (float) $row['open'],
                        'high' => (float) $row['high'],
                        'low' => (float) $row['low'],
                        'close' => (float) $row['close'],
                        'volume' => (float) $row['volume'],
                    ];
                    continue;
                }

                $candles[$key]['high'] = max($candles[$key]['high'], (float) $row['high']);
                $candles[$key]['low'] = min($candles[$key]['low'], (float) $row['low']);
                $candles[$key]['close'] = (float) $row['close'];
                $candles[$key]['volume'] += (float) $row['volume'];
            }

            $cursor = $cursor->addDay();
        }

        ksort($candles);

        return array_values($candles);
    }

    /**
     * Fast production path for the Jetta M15 adapter. The Node 22 runtime is
     * materially faster than PHP/cURL on this host; it still uses one Jetta
     * request at a time and performs the same local M15 aggregation.
     *
     * @return array<int, array{time: string, open: float, high: float, low: float, close: float, volume: float}>
     */
    private function fetchJettaM15NodeChunk(
        string $instrument,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $timeout = max(10, (int) config('services.dukascopy.timeout_seconds', 45));
        $result = Process::timeout($timeout)
            ->idleTimeout($timeout)
            ->path(base_path())
            ->run([
                (string) config('services.dukascopy.node_binary', 'node'),
                base_path('scripts/fetch-dukascopy.cjs'),
                '--instrument', $instrument,
                '--timeframe', 'M15',
                '--from', $from->toIso8601String(),
                '--to', $to->toIso8601String(),
                '--transport', 'jetta',
                '--baseUrl', (string) config('services.dukascopy.jetta_base_url', 'https://jetta.dukascopy.com'),
                '--httpTimeoutMs', (string) ((int) config('services.dukascopy.http_timeout_seconds', 20) * 1000),
                '--httpRetries', (string) config('services.dukascopy.http_retry_attempts', 3),
                '--pauseMs', (string) config('services.dukascopy.pause_ms', 1000),
            ]);

        if (! $result->successful()) {
            throw new RuntimeException('Dukascopy Jetta M15 fetch failed: '.trim((string) $result->errorOutput()));
        }

        $output = preg_replace('/^\xEF\xBB\xBF/', '', $result->output()) ?? $result->output();
        $rows = json_decode($output, true);
        if (! is_array($rows)) {
            throw new RuntimeException('Dukascopy Jetta M15 noto\'g\'ri JSON qaytardi.');
        }

        return collect($rows)
            ->map(fn (array $row): array => [
                'time' => CarbonImmutable::createFromTimestampMs((int) $row['timestamp'], 'UTC')->format('Y-m-d H:i:s'),
                'open' => (float) $row['open'],
                'high' => (float) $row['high'],
                'low' => (float) $row['low'],
                'close' => (float) $row['close'],
                'volume' => (float) ($row['volume'] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{time: string, open: float, high: float, low: float, close: float, volume: float}>
     */
    private function decodeJettaMinutePayload(
        array $payload,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $fields = ['times', 'opens', 'highs', 'lows', 'closes', 'volumes'];
        $arrays = [];
        foreach ($fields as $field) {
            $arrays[$field] = is_array($payload[$field] ?? null) ? $payload[$field] : [];
        }

        $length = count($arrays['times']);
        foreach ($arrays as $values) {
            if (count($values) !== $length) {
                throw new RuntimeException('Dukascopy Jetta inconsistent minute OHLCV history qaytardi.');
            }
        }

        $shift = (int) ($payload['shift'] ?? 60_000);
        $multiplier = (float) ($payload['multiplier'] ?? 1);
        $exponent = $multiplier > 0 ? (int) floor(log10($multiplier)) : 0;
        $precision = $exponent > 0 ? $multiplier : 10 ** abs($exponent);
        $timestamp = (int) ($payload['timestamp'] ?? $from->getTimestampMs());
        $open = (float) ($payload['open'] ?? 0);
        $high = (float) ($payload['high'] ?? 0);
        $low = (float) ($payload['low'] ?? 0);
        $close = (float) ($payload['close'] ?? 0);
        $fromMs = $from->getTimestampMs();
        $toMs = $to->getTimestampMs();
        $rows = [];

        for ($index = 0; $index < $length; $index++) {
            $timestamp += $shift * (int) $arrays['times'][$index];
            $open = $this->applyJettaDelta($open, (float) $arrays['opens'][$index], $multiplier, $precision);
            $high = $this->applyJettaDelta($high, (float) $arrays['highs'][$index], $multiplier, $precision);
            $low = $this->applyJettaDelta($low, (float) $arrays['lows'][$index], $multiplier, $precision);
            $close = $this->applyJettaDelta($close, (float) $arrays['closes'][$index], $multiplier, $precision);
            $high = max($high, $open, $close);
            $low = min($low, $open, $close);

            if ($timestamp >= $fromMs && $timestamp < $toMs) {
                $rows[] = [
                    'time' => CarbonImmutable::createFromTimestampMs($timestamp, 'UTC')->format('Y-m-d H:i:s'),
                    'open' => $open,
                    'high' => $high,
                    'low' => $low,
                    'close' => $close,
                    'volume' => (float) $arrays['volumes'][$index],
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array{time: string, open: float, high: float, low: float, close: float, volume: float}>
     */
    private function fetchJettaChunk(
        string $instrument,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $normalized = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $instrument));
        if (strlen($normalized) !== 6) {
            throw new RuntimeException("Dukascopy Jetta instrument qo'llab-quvvatlanmaydi: {$instrument}");
        }

        $code = substr($normalized, 0, 3).'-'.substr($normalized, 3);
        $baseUrl = rtrim((string) config('services.dukascopy.jetta_base_url', 'https://jetta.dukascopy.com'), '/');
        $liveUrl = sprintf(
            '%s/v1/candles/trade/hour/%s/BID?from=%d',
            $baseUrl,
            $code,
            $from->getTimestampMs(),
        );
        $currentMonth = CarbonImmutable::now('UTC')->startOfMonth();

        // The monthly resource is an archive, not a reliable live endpoint:
        // it may return HTTP 200 with no current closed candles. Use Jetta's
        // timestamp endpoint directly for the active month, then retain the
        // monthly archive for historical imports.
        if ($from->greaterThanOrEqualTo($currentMonth)) {
            $payload = $this->requestJettaJson($liveUrl);
        } else {
            $url = sprintf(
                '%s/v1/candles/trade/hour/%s/BID/%d/%d',
                $baseUrl,
                $code,
                $from->year,
                $from->month,
            );
            try {
                $payload = $this->requestJettaJson($url);
            } catch (RuntimeException) {
                $payload = $this->requestJettaJson($liveUrl);
            }
        }

        $fields = ['times', 'opens', 'highs', 'lows', 'closes', 'volumes'];
        $arrays = [];
        foreach ($fields as $field) {
            $arrays[$field] = is_array($payload[$field] ?? null) ? $payload[$field] : [];
        }

        $length = count($arrays['times']);
        foreach ($arrays as $values) {
            if (count($values) !== $length) {
                throw new RuntimeException('Dukascopy Jetta inconsistent OHLCV history qaytardi.');
            }
        }

        $shift = (int) ($payload['shift'] ?? 1);
        $multiplier = (float) ($payload['multiplier'] ?? 1);
        $exponent = $multiplier > 0 ? (int) floor(log10($multiplier)) : 0;
        $precision = $exponent > 0 ? $multiplier : 10 ** abs($exponent);
        $timestamp = (int) ($payload['timestamp'] ?? 0);
        $open = (float) ($payload['open'] ?? 0);
        $high = (float) ($payload['high'] ?? 0);
        $low = (float) ($payload['low'] ?? 0);
        $close = (float) ($payload['close'] ?? 0);
        $fromMs = $from->getTimestampMs();
        $toMs = $to->getTimestampMs();
        $candles = [];

        for ($index = 0; $index < $length; $index++) {
            $timestamp += $shift * (int) $arrays['times'][$index];
            $open = $this->applyJettaDelta($open, (float) $arrays['opens'][$index], $multiplier, $precision);
            $high = $this->applyJettaDelta($high, (float) $arrays['highs'][$index], $multiplier, $precision);
            $low = $this->applyJettaDelta($low, (float) $arrays['lows'][$index], $multiplier, $precision);
            $close = $this->applyJettaDelta($close, (float) $arrays['closes'][$index], $multiplier, $precision);

            // Jetta stores OHLC as independently rounded cumulative deltas.
            // At a price-tick boundary that can leave the close one tick
            // outside the reconstructed high/low even though the source
            // candle is geometrically valid. Keep the provider archive
            // physically coherent without changing values beyond the
            // already-declared Jetta precision.
            $high = max($high, $open, $close);
            $low = min($low, $open, $close);

            if ($timestamp >= $fromMs && $timestamp < $toMs) {
                $candles[$this->candleKey($timestamp)] = [
                    'time' => CarbonImmutable::createFromTimestampMs($timestamp, 'UTC')->format('Y-m-d H:i:s'),
                    'open' => $open,
                    'high' => $high,
                    'low' => $low,
                    'close' => $close,
                    // Keep dukascopy-node's default `millions` unit so old and
                    // newly backfilled rows remain comparable during training.
                    'volume' => (float) $arrays['volumes'][$index],
                ];
            }
        }

        if ((bool) config('services.dukascopy.tick_fallback_enabled', true)) {
            // A known archive failure can leave a monthly H1 resource with
            // only a handful of one-minute-like rows while tick history is
            // still intact. In that case rebuild every requested market hour;
            // otherwise use ticks only for individual missing H1 candles.
            $monthlyArchiveIsSparse = $length < 100;
            if ($monthlyArchiveIsSparse) {
                $candles = [];
            }

            for ($hour = $from->startOfHour(); $hour->lessThan($to); $hour = $hour->addHour()) {
                $key = $hour->format('Y-m-d H:00:00');
                if ((! $monthlyArchiveIsSparse && isset($candles[$key])) || ! $this->isExpectedMarketHour($hour, $normalized)) {
                    continue;
                }

                $tickCandle = $this->fetchJettaTickCandle($baseUrl, $code, $hour);
                if ($tickCandle !== null) {
                    $candles[$key] = $tickCandle;
                }
            }
        }

        ksort($candles);

        return array_values($candles);
    }

    /** @return array<string, mixed> */
    private function requestJettaJson(string $url): array
    {
        $response = Http::acceptJson()
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
            ->timeout(max(1, (int) config('services.dukascopy.http_timeout_seconds', 20)))
            ->retry(
                max(1, (int) config('services.dukascopy.http_retry_attempts', 3)),
                max(100, (int) config('services.dukascopy.http_retry_pause_ms', 2000)),
                throw: false,
            )
            ->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Dukascopy Jetta candle fetch failed: HTTP {$response->status()}");
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Dukascopy Jetta noto\'g\'ri JSON qaytardi.');
        }

        return $payload;
    }

    /**
     * @return array{time: string, open: float, high: float, low: float, close: float, volume: float}|null
     */
    private function fetchJettaTickCandle(
        string $baseUrl,
        string $code,
        CarbonImmutable $hour,
    ): ?array {
        $url = sprintf(
            '%s/v1/ticks/%s/%d/%d/%d/%d',
            $baseUrl,
            $code,
            $hour->year,
            $hour->month,
            $hour->day,
            $hour->hour,
        );
        $payload = $this->requestJettaJson($url);
        $times = is_array($payload['times'] ?? null) ? $payload['times'] : [];
        $bids = is_array($payload['bids'] ?? null) ? $payload['bids'] : [];
        $bidVolumes = is_array($payload['bidVolumes'] ?? null) ? $payload['bidVolumes'] : [];
        $length = count($times);
        if ($length === 0) {
            return null;
        }
        if (count($bids) !== $length || count($bidVolumes) !== $length) {
            throw new RuntimeException('Dukascopy Jetta inconsistent tick history qaytardi.');
        }

        $multiplier = (float) ($payload['multiplier'] ?? 1);
        $exponent = $multiplier > 0 ? (int) floor(log10($multiplier)) : 0;
        $precision = $exponent > 0 ? $multiplier : 10 ** abs($exponent);
        $timestamp = (int) ($payload['timestamp'] ?? $hour->getTimestampMs());
        $bid = (float) ($payload['bid'] ?? 0);
        $open = null;
        $high = -INF;
        $low = INF;
        $close = null;
        $volume = 0.0;
        $hourEndMs = $hour->addHour()->getTimestampMs();

        for ($index = 0; $index < $length; $index++) {
            $timestamp += (int) $times[$index];
            $bid = $this->applyJettaDelta($bid, (float) $bids[$index], $multiplier, $precision);
            if ($timestamp < $hour->getTimestampMs() || $timestamp >= $hourEndMs) {
                continue;
            }

            $open ??= $bid;
            $high = max($high, $bid);
            $low = min($low, $bid);
            $close = $bid;
            // Tick volume is returned in units; H1/archive volume and the
            // existing database use millions.
            $volume += (float) $bidVolumes[$index] / 1e6;
        }

        if ($open === null || $close === null) {
            return null;
        }

        return [
            'time' => $hour->format('Y-m-d H:i:s'),
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => $volume,
        ];
    }

    private function isExpectedMarketHour(CarbonImmutable $hour, string $instrument): bool
    {
        if (($hour->month === 1 && $hour->day === 1) || ($hour->month === 12 && $hour->day === 25)) {
            return false;
        }
        if (str_starts_with($instrument, 'XAU') && $hour->hour === 0) {
            return false;
        }

        return match ($hour->dayOfWeek) {
            CarbonImmutable::SATURDAY, CarbonImmutable::SUNDAY => false,
            CarbonImmutable::FRIDAY => $hour->hour < 21,
            default => true,
        };
    }

    private function candleKey(int $timestampMs): string
    {
        return CarbonImmutable::createFromTimestampMs($timestampMs, 'UTC')->format('Y-m-d H:i:s');
    }

    private function applyJettaDelta(float $value, float $delta, float $multiplier, float $precision): float
    {
        return round(($value + ($delta * $multiplier)) * $precision) / $precision;
    }

    /**
     * @return array<int, array{time: string, open: float, high: float, low: float, close: float, volume: float}>
     */
    private function fetchLegacyChunk(
        string $instrument,
        string $timeframe,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $attempts = max(1, (int) config('services.dukascopy.retry_attempts', 3));
        $result = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $timeout = max(10, (int) config('services.dukascopy.timeout_seconds', 45));
            try {
                $result = Process::timeout($timeout)
                    ->idleTimeout($timeout)
                    ->path(base_path())
                    ->run([
                        (string) config('services.dukascopy.node_binary', 'node'),
                        base_path('scripts/fetch-dukascopy.cjs'),
                        '--instrument', $instrument,
                        '--timeframe', strtoupper($timeframe),
                        '--from', $from->toIso8601String(),
                        '--to', $to->toIso8601String(),
                        '--transport', 'legacy',
                        '--batchSize', (string) config('services.dukascopy.batch_size', 1),
                        '--pauseMs', (string) config('services.dukascopy.pause_ms', 1000),
                        '--retryCount', (string) config('services.dukascopy.retry_attempts', 3),
                        '--retryPauseMs', (string) config('services.dukascopy.retry_pause_ms', 5000),
                    ]);
            } catch (Throwable $exception) {
                Log::warning('Dukascopy chunk process exception.', [
                    'attempt' => $attempt, 'from' => $from->toDateTimeString(),
                    'to' => $to->toDateTimeString(), 'error' => $exception->getMessage(),
                ]);
                if ($attempt === $attempts) {
                    throw new RuntimeException('Dukascopy candle fetch process failed: '.$exception->getMessage(), previous: $exception);
                }
                sleep($attempt);
                continue;
            }

            if ($result->successful()) {
                break;
            }

            sleep($attempt);
        }

        if (! $result?->successful()) {
            Log::warning('Dukascopy chunk failed after retries.', [
                'from' => $from->toDateTimeString(),
                'to' => $to->toDateTimeString(),
                'error' => $result?->errorOutput(),
            ]);

            throw new RuntimeException('Dukascopy candle fetch failed: '.trim((string) $result?->errorOutput()));
        }

        $output = preg_replace('/^\xEF\xBB\xBF/', '', $result->output()) ?? $result->output();
        $rows = json_decode($output, true);

        if (! is_array($rows)) {
            throw new RuntimeException('Dukascopy noto\'g\'ri JSON qaytardi.');
        }

        return collect($rows)
            ->map(fn (array $row): array => [
                'time' => CarbonImmutable::createFromTimestampMs((int) $row['timestamp'], 'UTC')->format('Y-m-d H:i:s'),
                'open' => (float) $row['open'],
                'high' => (float) $row['high'],
                'low' => (float) $row['low'],
                'close' => (float) $row['close'],
                'volume' => (float) ($row['volume'] ?? 0),
            ])
            ->values()
            ->all();
    }

    private function instrument(string $symbol, string $providerSymbol): string
    {
        $configured = config('services.dukascopy.instruments.'.strtoupper($symbol));

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return strtolower(str_replace(['/', '_'], '', $providerSymbol ?: $symbol));
    }
}
