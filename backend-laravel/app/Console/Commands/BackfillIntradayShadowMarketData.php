<?php

namespace App\Console\Commands;

use App\Models\Candle;
use App\Models\MarketCandleObservation;
use App\Models\MarketDataSyncState;
use App\Models\MarketSymbol;
use App\Models\Symbol;
use App\Services\MarketData\HistoricalDataQualityService;
use App\Services\MarketData\MarketDataService;
use App\Services\TemporalExecutionDataContractService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;

class BackfillIntradayShadowMarketData extends Command
{
    protected $signature = 'market-data:backfill-intraday-shadow
                            {--symbol=XAUUSD}
                            {--from= : UTC inclusive start; defaults to the persisted 2026 checkpoint}
                            {--to= : UTC exclusive end; defaults to the latest closed M1 boundary}
                            {--chunk-days=7 : Bounded UTC days per invocation}
                            {--max-chunks=1 : Number of checkpoint chunks; 0 means all}
                            {--derive-only : Build M5/M30 from stored M1 without another provider request}';

    protected $description = 'Backfill XAUUSD M1 BID candles and deterministically derive M5/M30 shadow data; never grants M1 execution authority';

    public function handle(
        MarketDataService $marketData,
        HistoricalDataQualityService $quality,
        TemporalExecutionDataContractService $temporalContract,
    ): int {
        $symbol = strtoupper((string) $this->option('symbol'));
        if ($symbol !== 'XAUUSD') {
            $this->error('Intraday shadow plane faqat XAUUSD uchun ruxsat etilgan.');

            return self::INVALID;
        }
        $marketSymbol = MarketSymbol::query()->where('symbol', $symbol)->where('is_active', true)->first();
        if (! $marketSymbol) {
            $this->error('Active XAUUSD market symbol topilmadi.');

            return self::FAILURE;
        }

        try {
            $trainingCutoff = CarbonImmutable::parse(
                (string) config('services.lab_selection.training_end_exclusive', '2026-01-01 00:00:00'),
                'UTC',
            )->utc();
            $checkpoint = MarketDataSyncState::query()->firstOrCreate(
                ['provider' => 'dukascopy_intraday_shadow', 'symbol' => $symbol, 'timeframe' => 'M1'],
                ['status' => 'pending', 'pending_from_at' => $trainingCutoff],
            );
            $checkpointDriven = ! $this->option('from');
            $from = $this->option('from')
                ? CarbonImmutable::parse((string) $this->option('from'), 'UTC')->utc()
                : ($checkpoint->pending_from_at
                    ? CarbonImmutable::instance($checkpoint->pending_from_at)->utc()
                    : ($checkpoint->last_confirmed_candle_at
                        ? CarbonImmutable::instance($checkpoint->last_confirmed_candle_at)->utc()->addMinute()
                        : $trainingCutoff));
            $to = $this->option('to')
                ? CarbonImmutable::parse((string) $this->option('to'), 'UTC')->utc()
                : CarbonImmutable::now('UTC')->setTime(CarbonImmutable::now('UTC')->hour, CarbonImmutable::now('UTC')->minute, 0);
            if ($from->greaterThanOrEqualTo($to)) {
                $this->info('XAUUSD intraday rolling data already current.');

                return self::SUCCESS;
            }
            if ($from->lessThan($trainingCutoff)) {
                throw new RuntimeException('Intraday shadow data 2026-01-01 dan oldin boshlanishi mumkin emas; pre-2026 uchun intraday-training archive ishlatiladi.');
            }
        } catch (\Throwable $exception) {
            $this->error('Noto\'g\'ri UTC range: '.$exception->getMessage());

            return self::INVALID;
        }

        $lockPath = storage_path('app/market-intraday-shadow-backfill.lock');
        $lock = fopen($lockPath, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) fclose($lock);
            $this->line('XAUUSD intraday rolling backfill already running; this tick skipped.');

            return self::SUCCESS;
        }
        $chunkDays = max(1, min(14, (int) $this->option('chunk-days')));
        $maxChunks = max(0, (int) $this->option('max-chunks'));
        $previousProvider = config('services.market_data.provider');
        $previousFallback = config('services.market_data.fallback_provider');
        config()->set('services.market_data.provider', 'dukascopy');
        config()->set('services.market_data.fallback_provider', null);

        try {
            $m1Saved = 0;
            $m5Saved = 0;
            $m30Saved = 0;
            $chunks = 0;
            while ($from->lessThan($to) && ($maxChunks === 0 || $chunks < $maxChunks)) {
                $chunkFrom = $from;
                $chunkTo = $from->addDays($chunkDays);
                if ($chunkTo->greaterThan($to)) $chunkTo = $to;
                if ($checkpointDriven) {
                    $checkpoint->update(['status' => 'backfilling', 'pending_from_at' => $chunkFrom, 'pending_to_at' => $chunkTo, 'last_attempt_at' => now(), 'last_error' => null]);
                }
                if (! $this->option('derive-only')) {
                    $m1Saved += $marketData->updateCandles($marketSymbol, 'M1', 100_000, $chunkFrom, $chunkTo);
                }
                $m5 = $this->derive($symbol, $chunkFrom, $chunkTo, 'M5', 5);
                $m30 = $this->derive($symbol, $chunkFrom, $chunkTo, 'M30', 30);
                $m5Saved += $m5['saved'];
                $m30Saved += $m30['saved'];
                $from = $chunkTo;
                $chunks++;
                if ($checkpointDriven) {
                    $checkpoint->update([
                        'status' => $from->greaterThanOrEqualTo($to) ? 'healthy' : 'partial',
                        'last_confirmed_candle_at' => $from->subMinute(),
                        'pending_from_at' => $from->greaterThanOrEqualTo($to) ? null : $from,
                        'pending_to_at' => $to,
                        'last_success_at' => now(),
                        'retry_count' => 0,
                        'metrics' => array_merge($checkpoint->metrics ?? [], [
                            'protocol' => 'xauusd_2026_intraday_shadow_v1',
                        'source' => 'dukascopy_jetta_bid_minute_archive',
                        'm5_m30_source' => 'deterministic_m1_aggregation',
                        'derive_only' => (bool) $this->option('derive-only'),
                        'last_chunk_m1_saved' => $m1Saved,
                        'last_chunk_m5_saved' => $m5Saved,
                        'last_chunk_m30_saved' => $m30Saved,
                        ]),
                    ]);
                }
            }
        } catch (\Throwable $exception) {
            if (isset($checkpointDriven) && $checkpointDriven) {
                $checkpoint->update(['status' => 'blocked', 'retry_count' => $checkpoint->retry_count + 1, 'last_error' => $exception->getMessage(), 'last_attempt_at' => now()]);
            }
            $this->error('M1 shadow backfill failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            config()->set('services.market_data.provider', $previousProvider);
            config()->set('services.market_data.fallback_provider', $previousFallback);
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $m1Quality = $quality->inspect($symbol, 'M1', true);
        $m5Quality = $quality->inspect($symbol, 'M5', true);
        $m30Quality = $quality->inspect($symbol, 'M30', true);
        $contract = $temporalContract->certify([
            'provider' => 'dukascopy_jetta_bid_minute_archive',
            'm1_canonical' => $m1Quality['status'] === 'ready',
            'm5_canonical' => $m5Quality['status'] === 'ready',
            // BID OHLCV is sufficient for shadow research but not for an
            // executable M1 fill model. These stay deliberately false.
            'bid_ask_history' => false,
            'spread_history' => false,
            'slippage_model' => false,
            'latency_model' => false,
            'deterministic_aggregation' => true,
            'gap_audit' => $m1Quality['status'] === 'ready' && $m5Quality['status'] === 'ready' && $m30Quality['status'] === 'ready',
            'closed_at_available_at' => true,
            'backward_only_alignment' => true,
        ]);

        $this->table(
            ['Timeframe', 'Saved', 'Rows', 'Quality'],
            [
                ['M1', $m1Saved, $m1Quality['row_count'], $m1Quality['status']],
                ['M5', $m5Saved, $m5Quality['row_count'], $m5Quality['status']],
                ['M30', $m30Saved, $m30Quality['row_count'], $m30Quality['status']],
            ],
        );
        $this->line(json_encode([
            'protocol' => $contract['protocol'],
            'm1_execution' => $contract['m1_execution'],
            'm1_false_precision_blocked' => $contract['m1_false_precision_blocked'],
            'missing_execution_requirements' => $contract['missing_requirements'],
            'source' => 'Dukascopy Jetta BID minute archive',
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /** @return array{saved:int, skipped_incomplete:int} */
    private function derive(string $symbol, CarbonImmutable $from, CarbonImmutable $to, string $timeframe, int $minutes): array
    {
        $symbolId = Symbol::query()->where('code', $symbol)->value('id');
        if (! $symbolId) {
            throw new RuntimeException("{$symbol} canonical symbol topilmadi.");
        }
        // A rolling fetch normally starts one minute after its persisted
        // checkpoint.  Include the preceding bucket here so the first M5 or
        // M30 candle is rebuilt as a complete candle rather than silently
        // discarded at that boundary.
        $queryFrom = $from->setTime($from->hour, intdiv($from->minute, $minutes) * $minutes, 0);
        $rows = Candle::query()
            ->where('symbol_id', $symbolId)
            ->where('timeframe', 'M1')
            ->where('time', '>=', $queryFrom)
            ->where('time', '<', $to)
            ->orderBy('time')
            ->get(['time', 'open', 'high', 'low', 'close', 'volume']);
        $buckets = [];
        foreach ($rows as $row) {
            $time = CarbonImmutable::parse($row->time, 'UTC');
            $bucket = $time->setTime($time->hour, intdiv($time->minute, $minutes) * $minutes, 0);
            $key = $bucket->format('Y-m-d H:i:s');
            if (! isset($buckets[$key])) {
                $buckets[$key] = ['time' => $key, 'open' => (float) $row->open, 'high' => (float) $row->high, 'low' => (float) $row->low, 'close' => (float) $row->close, 'volume' => (float) $row->volume, 'count' => 1];
                continue;
            }
            $buckets[$key]['high'] = max($buckets[$key]['high'], (float) $row->high);
            $buckets[$key]['low'] = min($buckets[$key]['low'], (float) $row->low);
            $buckets[$key]['close'] = (float) $row->close;
            $buckets[$key]['volume'] += (float) $row->volume;
            $buckets[$key]['count']++;
        }

        $now = now();
        $complete = collect($buckets)->filter(fn (array $row): bool => $row['count'] === $minutes)->map(fn (array $row): array => [
            'symbol_id' => $symbolId,
            'timeframe' => $timeframe,
            'time' => $row['time'],
            'open' => $row['open'],
            'high' => $row['high'],
            'low' => $row['low'],
            'close' => $row['close'],
            'volume' => $row['volume'],
            'provider' => 'dukascopy_m1_derived',
            'created_at' => $now,
            'updated_at' => $now,
        ])->values();
        $complete->chunk(1000)->each(function ($chunk) use ($symbol, $timeframe, $now): void {
            Candle::upsert($chunk->all(), ['symbol_id', 'timeframe', 'time'], ['open', 'high', 'low', 'close', 'volume', 'provider', 'updated_at']);
            MarketCandleObservation::upsert($chunk->map(fn (array $row): array => [
                'provider' => 'dukascopy_m1_derived', 'symbol' => $symbol, 'timeframe' => $timeframe,
                'time' => $row['time'], 'open' => $row['open'], 'high' => $row['high'], 'low' => $row['low'], 'close' => $row['close'], 'volume' => $row['volume'], 'created_at' => $now, 'updated_at' => $now,
            ])->all(), ['provider', 'symbol', 'timeframe', 'time'], ['open', 'high', 'low', 'close', 'volume', 'updated_at']);
        });

        return ['saved' => $complete->count(), 'skipped_incomplete' => count($buckets) - $complete->count()];
    }
}
