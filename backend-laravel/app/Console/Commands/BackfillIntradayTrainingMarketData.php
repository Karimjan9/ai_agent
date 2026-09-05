<?php

namespace App\Console\Commands;

use App\Models\MarketSymbol;
use App\Services\MarketData\DukascopyMarketDataProvider;
use App\Services\MarketData\MarketTrainingDataService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;

class BackfillIntradayTrainingMarketData extends Command
{
    protected $signature = 'market-data:backfill-intraday-training
                            {--symbol=XAUUSD}
                            {--from=2016-08-15 00:00:00 : UTC inclusive foundation start}
                            {--to=2026-01-01 00:00:00 : UTC exclusive foundation end}
                            {--cursor= : Explicit UTC resume boundary}
                            {--chunk-days=7 : Bounded provider days per invocation}
                            {--max-chunks=1 : Number of resumable chunks; 0 means all}
                            {--dataset=foundation_intraday_10y}
                            {--provider=dukascopy}
                            {--transport=jetta}';

    protected $description = 'Resume XAUUSD pre-2026 M1 foundation and deterministically derive M5/M30 training archives';

    public function handle(MarketTrainingDataService $training, DukascopyMarketDataProvider $provider): int
    {
        $symbol = strtoupper((string) $this->option('symbol'));
        if ($symbol !== 'XAUUSD') {
            $this->error('Intraday training foundation faqat XAUUSD uchun ruxsat etilgan.');

            return self::INVALID;
        }
        $marketSymbol = MarketSymbol::query()->where('symbol', $symbol)->where('is_active', true)->first();
        if (! $marketSymbol) {
            $this->error('Active XAUUSD market symbol topilmadi.');

            return self::FAILURE;
        }

        try {
            $from = CarbonImmutable::parse((string) $this->option('from'), 'UTC')->utc();
            $to = CarbonImmutable::parse((string) $this->option('to'), 'UTC')->utc();
            $cutoff = $training->trainingCutoff();
            if ($to->greaterThan($cutoff)) $to = $cutoff;
            if ($from->greaterThanOrEqualTo($to)) throw new RuntimeException('Foundation range bo\'sh bo\'lishi mumkin emas.');
        } catch (\Throwable $exception) {
            $this->error('Noto\'g\'ri UTC foundation range: '.$exception->getMessage());

            return self::INVALID;
        }

        $dataset = (string) $this->option('dataset');
        $source = (string) $this->option('provider');
        $archives = collect(['M1', 'M5', 'M30'])->mapWithKeys(fn (string $timeframe) => [
            $timeframe => $training->ensureArchive($dataset, $source, $symbol, $timeframe, $from, $to),
        ]);
        $requestedCursor = $this->option('cursor') ? CarbonImmutable::parse((string) $this->option('cursor'), 'UTC')->utc() : null;
        $cursor = $requestedCursor ?: CarbonImmutable::instance($archives['M1']->backfill_cursor_at ?: $from)->utc();
        if ($cursor->lessThan($from)) $cursor = $from;
        if ($cursor->greaterThanOrEqualTo($to)) {
            $this->finaliseArchives($archives, $training, $to);
            $this->info('XAUUSD intraday training archive already complete.');

            return self::SUCCESS;
        }

        $lockPath = storage_path('app/market-training-backfill-intraday.lock');
        $lock = fopen($lockPath, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) fclose($lock);
            $this->line('XAUUSD intraday training backfill already running; this tick skipped.');

            return self::SUCCESS;
        }

        $previousTransport = config('services.dukascopy.transport');
        config()->set('services.dukascopy.transport', strtolower((string) $this->option('transport')));
        try {
            $chunkDays = max(1, min(14, (int) $this->option('chunk-days')));
            $maxChunks = max(0, (int) $this->option('max-chunks'));
            $completed = 0;
            while ($cursor->lessThan($to) && ($maxChunks === 0 || $completed < $maxChunks)) {
                $chunkFrom = $cursor;
                $chunkTo = min($cursor->addDays($chunkDays), $to);
                foreach ($archives as $archive) {
                    $archive->update(['status' => 'backfilling', 'last_attempt_at' => now(), 'last_chunk_from' => $chunkFrom, 'last_chunk_to' => $chunkTo, 'last_error' => null]);
                }
                $m1 = $provider->fetchCandles($symbol, $marketSymbol->provider_symbol ?? $symbol, 'M1', 1_000_000, $chunkFrom, $chunkTo);
                if ($m1 === []) throw new RuntimeException('Dukascopy M1 archive bo\'sh qaytdi; cursor advance qilinmadi.');
                $m5 = $this->aggregate($m1, 5);
                $m30 = $this->aggregate($m1, 30);
                $saved = [
                    'M1' => $training->upsertCandles($dataset, $source, $symbol, 'M1', $m1),
                    'M5' => $training->upsertCandles($dataset, $source, $symbol, 'M5', $m5),
                    'M30' => $training->upsertCandles($dataset, $source, $symbol, 'M30', $m30),
                ];
                $cursor = $chunkTo;
                $completed++;
                foreach ($archives as $timeframe => $archive) {
                    $coverage = $training->refreshCoverage($archive->fresh());
                    $archive->update([
                        'status' => $cursor->greaterThanOrEqualTo($to) ? 'complete' : 'partial',
                        'backfill_cursor_at' => $cursor,
                        'last_success_at' => now(),
                        'completed_chunks' => $archive->completed_chunks + 1,
                        'last_error' => null,
                        'metrics' => array_merge($archive->metrics ?? [], [
                            'source_role' => 'pre_2026_training_only',
                            'derived_from' => $timeframe === 'M1' ? null : 'M1',
                            'deterministic_aggregation' => $timeframe !== 'M1',
                            'last_chunk_rows' => $saved[$timeframe],
                            'last_coverage_rows' => $coverage['row_count'],
                            'timezone' => 'UTC', 'price_side' => 'BID',
                        ]),
                    ]);
                }
                $this->line(sprintf('XAUUSD intraday foundation: M1=%d M5=%d M30=%d %s -> %s; cursor=%s', $saved['M1'], $saved['M5'], $saved['M30'], $chunkFrom->format('Y-m-d'), $chunkTo->format('Y-m-d'), $cursor->toIso8601String()));
            }
        } catch (\Throwable $exception) {
            foreach ($archives as $archive) {
                $archive->increment('failed_chunks');
                $archive->update(['status' => 'blocked', 'last_error' => $exception->getMessage(), 'last_attempt_at' => now()]);
            }
            $this->error('Intraday training backfill stopped: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            config()->set('services.dukascopy.transport', $previousTransport);
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        foreach ($archives as $archive) $training->refreshCoverage($archive->fresh());
        $this->info('Intraday training checkpoint saved.');

        return self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $m1 @return array<int, array<string, mixed>> */
    private function aggregate(array $m1, int $minutes): array
    {
        $buckets = [];
        foreach ($m1 as $row) {
            $time = CarbonImmutable::parse((string) $row['time'], 'UTC');
            $bucket = $time->setTime($time->hour, intdiv($time->minute, $minutes) * $minutes, 0);
            $key = $bucket->format('Y-m-d H:i:s');
            if (! isset($buckets[$key])) {
                $buckets[$key] = ['time' => $key, 'open' => (float) $row['open'], 'high' => (float) $row['high'], 'low' => (float) $row['low'], 'close' => (float) $row['close'], 'volume' => (float) ($row['volume'] ?? 0), 'count' => 1];
                continue;
            }
            $buckets[$key]['high'] = max($buckets[$key]['high'], (float) $row['high']);
            $buckets[$key]['low'] = min($buckets[$key]['low'], (float) $row['low']);
            $buckets[$key]['close'] = (float) $row['close'];
            $buckets[$key]['volume'] += (float) ($row['volume'] ?? 0);
            $buckets[$key]['count']++;
        }

        return collect($buckets)->filter(fn (array $row): bool => $row['count'] === $minutes)->map(fn (array $row): array => collect($row)->except('count')->all())->values()->all();
    }

    private function finaliseArchives($archives, MarketTrainingDataService $training, CarbonImmutable $to): void
    {
        foreach ($archives as $archive) {
            $training->refreshCoverage($archive);
            $archive->update(['status' => 'complete', 'backfill_cursor_at' => $to, 'last_error' => null]);
        }
    }
}
