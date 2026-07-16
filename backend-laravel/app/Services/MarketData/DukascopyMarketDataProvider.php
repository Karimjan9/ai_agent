<?php

namespace App\Services\MarketData;

use Carbon\CarbonImmutable;
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
        $cursor = CarbonImmutable::instance($from)->utc();
        $end = CarbonImmutable::instance($to)->utc();
        $isLiveRecovery = $cursor->diffInHours($end) <= 72;
        $candles = [];

        while ($cursor < $end) {
            $chunkEnd = $isLiveRecovery && $liveChunkHours > 0
                ? $cursor->addHours($liveChunkHours)
                : $cursor->addDays($chunkDays);
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
                        '--batchSize', (string) config('services.dukascopy.batch_size', 1),
                        '--pauseMs', (string) config('services.dukascopy.pause_ms', 1000),
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
