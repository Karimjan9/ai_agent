<?php

namespace App\Console\Commands;

use App\Models\Candle;
use App\Models\MarketCandleObservation;
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
                            {--from= : UTC inclusive start; defaults to 30 days ago}
                            {--to= : UTC exclusive end; defaults to the latest closed M1 boundary}';

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
            $from = $this->option('from')
                ? CarbonImmutable::parse((string) $this->option('from'), 'UTC')->utc()
                : CarbonImmutable::now('UTC')->subDays(30)->startOfDay();
            $to = $this->option('to')
                ? CarbonImmutable::parse((string) $this->option('to'), 'UTC')->utc()
                : CarbonImmutable::now('UTC')->setTime(CarbonImmutable::now('UTC')->hour, CarbonImmutable::now('UTC')->minute, 0);
            if ($from->greaterThanOrEqualTo($to)) {
                throw new RuntimeException('Intraday range bo\'sh bo\'lishi mumkin emas.');
            }
        } catch (\Throwable $exception) {
            $this->error('Noto\'g\'ri UTC range: '.$exception->getMessage());

            return self::INVALID;
        }

        $previousProvider = config('services.market_data.provider');
        $previousFallback = config('services.market_data.fallback_provider');
        config()->set('services.market_data.provider', 'dukascopy');
        config()->set('services.market_data.fallback_provider', null);

        try {
            $m1Saved = $marketData->updateCandles($marketSymbol, 'M1', 1_000_000, $from, $to);
        } catch (\Throwable $exception) {
            $this->error('M1 shadow backfill failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            config()->set('services.market_data.provider', $previousProvider);
            config()->set('services.market_data.fallback_provider', $previousFallback);
        }

        $m5 = $this->derive($symbol, $from, $to, 'M5', 5);
        $m30 = $this->derive($symbol, $from, $to, 'M30', 30);
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
                ['M5', $m5['saved'], $m5Quality['row_count'], $m5Quality['status']],
                ['M30', $m30['saved'], $m30Quality['row_count'], $m30Quality['status']],
            ],
        );
        $this->line(json_encode([
            'protocol' => $contract['protocol'],
            'm1_execution' => $contract['m1_execution'],
            'm1_false_precision_blocked' => $contract['m1_false_precision_blocked'],
            'missing_execution_requirements' => $contract['missing_requirements'],
            'source' => 'Dukascopy Jetta BID minute archive',
        ], JSON_UNESCAPED_SLASHES));

        return $m1Quality['status'] === 'ready' && $m5Quality['status'] === 'ready' && $m30Quality['status'] === 'ready'
            ? self::SUCCESS
            : self::FAILURE;
    }

    /** @return array{saved:int, skipped_incomplete:int} */
    private function derive(string $symbol, CarbonImmutable $from, CarbonImmutable $to, string $timeframe, int $minutes): array
    {
        $symbolId = Symbol::query()->where('code', $symbol)->value('id');
        if (! $symbolId) {
            throw new RuntimeException("{$symbol} canonical symbol topilmadi.");
        }
        $rows = Candle::query()
            ->where('symbol_id', $symbolId)
            ->where('timeframe', 'M1')
            ->where('time', '>=', $from)
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
