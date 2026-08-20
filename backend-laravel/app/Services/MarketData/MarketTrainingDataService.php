<?php

namespace App\Services\MarketData;

use App\Models\MarketTrainingArchive;
use App\Models\MarketTrainingCandle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class MarketTrainingDataService
{
    public const DEFAULT_DATASET = 'foundation_10y';

    public const DEFAULT_PROVIDER = 'dukascopy';

    public function trainingCutoff(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            (string) config('services.lab_selection.training_end_exclusive', '2026-01-01 00:00:00'),
            'UTC',
        )->utc();
    }

    /**
     * The archive manifest is the resumable control plane. The candle table
     * itself remains append/upsert-only so a retry can never create a second
     * copy of the same source candle.
     */
    public function ensureArchive(
        string $dataset,
        string $provider,
        string $symbol,
        string $timeframe,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): MarketTrainingArchive {
        $cutoff = $this->trainingCutoff();
        if ($to->greaterThan($cutoff)) {
            $to = $cutoff;
        }
        $identity = [
            'dataset_key' => $dataset,
            'provider' => $provider,
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
        ];

        $archive = MarketTrainingArchive::query()->where($identity)->first();
        if ($archive) {
            if ($archive->target_to === null || CarbonImmutable::instance($archive->target_to)->utc()->greaterThan($to)) {
                $archive->update([
                    'target_to' => $to,
                    'status' => 'partial',
                    'last_error' => null,
                ]);
            }
            return $archive;
        }

        return MarketTrainingArchive::query()->create([
            ...$identity,
            'target_from' => $from,
            'target_to' => $to,
            'backfill_cursor_at' => $from,
            'status' => 'pending',
        ]);
    }

    /**
     * Persist one provider response into the training-only table.
     *
     * @param array<int, array<string, mixed>> $candles
     */
    public function upsertCandles(
        string $dataset,
        string $provider,
        string $symbol,
        string $timeframe,
        array $candles,
    ): int {
        if ($candles === []) {
            return 0;
        }

        $now = now();
        $cutoff = $this->trainingCutoff();
        $rows = [];
        foreach ($candles as $candle) {
            $time = $this->normaliseTime($candle['time'] ?? null);
            $open = (float) ($candle['open'] ?? 0);
            $high = (float) ($candle['high'] ?? 0);
            $low = (float) ($candle['low'] ?? 0);
            $close = (float) ($candle['close'] ?? 0);
            $volume = (float) ($candle['volume'] ?? 0);

            if ($time === null || ! $this->validOhlc($open, $high, $low, $close) || ! is_finite($volume)) {
                continue;
            }
            if (CarbonImmutable::parse($time, 'UTC')->greaterThanOrEqualTo($cutoff)) {
                throw new RuntimeException(
                    'Training archive 2026-01-01 dan keyingi candle qabul qilmaydi; 2026 faqat paper lane uchun.'
                );
            }

            $rows[] = [
                'dataset_key' => $dataset,
                'provider' => $provider,
                'symbol' => strtoupper($symbol),
                'timeframe' => strtoupper($timeframe),
                'time' => $time,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => $volume,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        collect($rows)->chunk(1000)->each(function ($chunk): void {
            MarketTrainingCandle::query()->upsert(
                $chunk->all(),
                ['dataset_key', 'provider', 'symbol', 'timeframe', 'time'],
                ['open', 'high', 'low', 'close', 'volume', 'updated_at'],
            );
        });

        return count($rows);
    }

    /**
     * Import an existing archive CSV without loading the whole file into RAM.
     *
     * @return array{imported: int, skipped: int, coverage: array<string, mixed>}
     */
    public function importCsv(
        MarketTrainingArchive $archive,
        string $path,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): array {
        if (! is_file($path)) {
            throw new RuntimeException("Training CSV topilmadi: {$path}");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Training CSV ochilmadi: {$path}");
        }

        $imported = 0;
        $skipped = 0;
        $batch = [];
        try {
            $firstLine = fgets($handle);
            if (! is_string($firstLine)) {
                throw new RuntimeException("Training CSV header topilmadi: {$path}");
            }
            $delimiter = str_contains($firstLine, ';') ? ';' : ',';
            $headers = array_map(
                static fn ($value): string => strtolower(trim((string) $value)),
                str_getcsv(trim($firstLine), $delimiter),
            );
            foreach (['time', 'open', 'high', 'low', 'close'] as $required) {
                if (! in_array($required, $headers, true)) {
                    throw new RuntimeException("Training CSV {$required} ustunini o'z ichiga olishi kerak.");
                }
            }

            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                if (count($values) !== count($headers)) {
                    $skipped++;
                    continue;
                }

                $row = array_combine($headers, $values);
                $time = $this->normaliseTime($row['time'] ?? $row['date'] ?? null);
                if ($time === null) {
                    $skipped++;
                    continue;
                }
                $candleTime = CarbonImmutable::parse($time, 'UTC');
                if (($from && $candleTime->lessThan($from)) || ($to && ! $candleTime->lessThan($to))) {
                    continue;
                }

                $candle = [
                    'time' => $time,
                    'open' => (float) ($row['open'] ?? 0),
                    'high' => (float) ($row['high'] ?? 0),
                    'low' => (float) ($row['low'] ?? 0),
                    'close' => (float) ($row['close'] ?? 0),
                    'volume' => (float) ($row['volume'] ?? 0),
                ];
                if (! $this->validOhlc($candle['open'], $candle['high'], $candle['low'], $candle['close'])) {
                    $skipped++;
                    continue;
                }
                $batch[] = $candle;

                if (count($batch) >= 1000) {
                    $imported += $this->upsertCandles(
                        $archive->dataset_key,
                        $archive->provider,
                        $archive->symbol,
                        $archive->timeframe,
                        $batch,
                    );
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $imported += $this->upsertCandles(
                    $archive->dataset_key,
                    $archive->provider,
                    $archive->symbol,
                    $archive->timeframe,
                    $batch,
                );
            }
        } finally {
            fclose($handle);
        }

        $coverage = $this->refreshCoverage($archive);
        $archive->update([
            'status' => $archive->status === 'complete' ? 'complete' : 'partial',
            'last_success_at' => now(),
            'metrics' => array_merge($archive->metrics ?? [], [
                'last_import' => [
                    'path' => $path,
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'completed_at' => now()->utc()->toIso8601String(),
                ],
            ]),
        ]);

        return compact('imported', 'skipped', 'coverage');
    }

    /**
     * Return the same neutral OHLCV shape used by the existing agent payloads.
     * The source is explicit and can never accidentally fall back to the live
     * Twelve stream.
     *
     * @return array<int, array<string, mixed>>
     */
    public function candlesForAgent(
        string $dataset,
        string $provider,
        string $symbol,
        string $timeframe,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?int $limit = null,
    ): array {
        $query = $this->query($dataset, $provider, $symbol, $timeframe);
        if ($from) {
            $query->where('time', '>=', $from);
        }
        if ($to) {
            $query->where('time', '<', $to);
        }
        if ($limit !== null && $limit > 0) {
            $candles = $query->orderByDesc('time')->limit($limit)->get()->sortBy('time')->values();
        } else {
            $candles = $query->orderBy('time')->get();
        }

        return $candles->map(static function (MarketTrainingCandle $candle): array {
            return [
                'time' => $candle->time->copy()->utc()->format('Y-m-d H:i:s'),
                'open' => (float) $candle->open,
                'high' => (float) $candle->high,
                'low' => (float) $candle->low,
                'close' => (float) $candle->close,
                'volume' => (float) $candle->volume,
            ];
        })->all();
    }

    /** @return Builder<MarketTrainingCandle> */
    public function query(string $dataset, string $provider, string $symbol, string $timeframe): Builder
    {
        return MarketTrainingCandle::query()
            ->where('dataset_key', $dataset)
            ->where('provider', $provider)
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('time', '<', $this->trainingCutoff());
    }

    /** @return array<string, mixed> */
    public function refreshCoverage(MarketTrainingArchive $archive): array
    {
        $stats = $this->query(
            $archive->dataset_key,
            $archive->provider,
            $archive->symbol,
            $archive->timeframe,
        )->selectRaw('COUNT(*) as row_count, MIN(time) as first_candle_at, MAX(time) as last_candle_at')->first();

        $coverage = [
            'row_count' => (int) ($stats?->row_count ?? 0),
            'first_candle_at' => $stats?->first_candle_at,
            'last_candle_at' => $stats?->last_candle_at,
        ];
        $archive->update($coverage);

        return $coverage;
    }

    private function normaliseTime(mixed $raw): ?string
    {
        if (! is_string($raw) && ! is_numeric($raw)) {
            return null;
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw, 'UTC')->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function validOhlc(float $open, float $high, float $low, float $close): bool
    {
        return is_finite($open)
            && is_finite($high)
            && is_finite($low)
            && is_finite($close)
            && $open > 0
            && $high > 0
            && $low > 0
            && $close > 0
            && $high >= max($open, $close)
            && $low <= min($open, $close)
            && $high >= $low;
    }
}
