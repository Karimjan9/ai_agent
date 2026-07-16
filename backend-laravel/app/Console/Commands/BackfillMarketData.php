<?php

namespace App\Console\Commands;

use App\Models\Candle;
use App\Models\MarketSymbol;
use App\Models\Symbol;
use App\Services\MarketData\MarketDataService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class BackfillMarketData extends Command
{
    protected $signature = 'market-data:backfill
                            {--symbol=XAUUSD}
                            {--timeframe=H1}
                            {--from=2004-01-01}
                            {--to=}
                            {--chunk-days=3}
                            {--limit=5000}';

    protected $description = 'Backfill market data in small persisted chunks.';

    public function handle(MarketDataService $marketDataService): int
    {
        $marketSymbol = MarketSymbol::query()
            ->where('symbol', (string) $this->option('symbol'))
            ->where('is_active', true)
            ->first();

        if (! $marketSymbol) {
            $this->error('Active symbol topilmadi.');

            return self::FAILURE;
        }

        $timeframe = (string) $this->option('timeframe');
        $from = $this->startTime($marketSymbol, $timeframe);
        $to = $this->option('to')
            ? CarbonImmutable::parse((string) $this->option('to'), 'UTC')
            : CarbonImmutable::now('UTC');

        if ($from >= $to) {
            $this->info('Backfill uchun yangi period yo\'q.');

            return self::SUCCESS;
        }

        $chunkDays = max(1, (int) $this->option('chunk-days'));
        $cursor = $from;
        $total = 0;
        $failedChunks = 0;

        while ($cursor < $to) {
            $chunkTo = $cursor->addDays($chunkDays);
            if ($chunkTo > $to) {
                $chunkTo = $to;
            }

            try {
                $saved = $marketDataService->updateCandles(
                    marketSymbol: $marketSymbol,
                    timeframe: $timeframe,
                    limit: (int) $this->option('limit'),
                    from: $cursor,
                    to: $chunkTo,
                );
                $total += $saved;
                $this->info("{$marketSymbol->symbol} {$timeframe}: {$saved} candles {$cursor->format('Y-m-d H:i')} -> {$chunkTo->format('Y-m-d H:i')}");
            } catch (Throwable $e) {
                $failedChunks++;
                $this->warn("Chunk skipped {$cursor->format('Y-m-d H:i')} -> {$chunkTo->format('Y-m-d H:i')}: {$e->getMessage()}");
            }

            $cursor = $chunkTo;
        }

        $this->info("Backfill finished. Total candles processed: {$total}; failed chunks: {$failedChunks}");

        return $failedChunks === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function startTime(MarketSymbol $marketSymbol, string $timeframe): CarbonImmutable
    {
        if ($this->option('from')) {
            return CarbonImmutable::parse((string) $this->option('from'), 'UTC');
        }

        $symbol = Symbol::query()->where('code', $marketSymbol->symbol)->first();
        $latest = $symbol
            ? Candle::query()->where('symbol_id', $symbol->id)->where('timeframe', $timeframe)->max('time')
            : null;

        return $latest
            ? CarbonImmutable::parse($latest, 'UTC')->addHour()
            : CarbonImmutable::now('UTC')->subDays(30);
    }
}
