<?php

namespace App\Console\Commands;

use App\Models\MarketSymbol;
use App\Services\MarketData\HistoricalDataQualityService;
use App\Services\MarketData\MarketDataService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class RepairHistoricalDataGaps extends Command
{
    protected $signature = 'market-data:repair-gaps
                            {symbol?}
                            {--timeframe=H1}
                            {--chunk-days=7}
                            {--max-ranges=10}
                            {--dry-run}';

    protected $description = 'Backfill known historical market-open gaps in bounded provider requests';

    public function handle(MarketDataService $marketData, HistoricalDataQualityService $quality): int
    {
        $symbols = $this->argument('symbol')
            ? MarketSymbol::query()->where('symbol', strtoupper((string) $this->argument('symbol')))->get()
            : MarketSymbol::query()->where('is_active', true)->orderBy('symbol')->get();
        $timeframe = (string) $this->option('timeframe');
        $chunkDays = max(1, (int) $this->option('chunk-days'));
        $maxRanges = max(1, min(100, (int) $this->option('max-ranges')));
        $failed = false;

        foreach ($symbols as $marketSymbol) {
            $report = $quality->inspect($marketSymbol->symbol, $timeframe, true);
            $ranges = collect($report['gap_examples'])->take($maxRanges);
            if ($ranges->isEmpty()) {
                $this->info("{$marketSymbol->symbol}: repair qilinadigan historical gap yo'q.");
                continue;
            }

            foreach ($ranges as $range) {
                $from = CarbonImmutable::parse($range['after'], 'UTC')->addHour();
                $end = CarbonImmutable::parse($range['before'], 'UTC');
                $this->line("{$marketSymbol->symbol}: {$from->toIso8601String()} -> {$end->toIso8601String()}");
                if ($this->option('dry-run')) {
                    continue;
                }

                for ($cursor = $from; $cursor->lessThan($end); $cursor = $chunkEnd) {
                    $chunkEnd = $cursor->addDays($chunkDays);
                    if ($chunkEnd->greaterThan($end)) {
                        $chunkEnd = $end;
                    }
                    try {
                        $saved = $marketData->updateCandles(
                            marketSymbol: $marketSymbol,
                            timeframe: $timeframe,
                            limit: 5000,
                            from: $cursor,
                            to: $chunkEnd,
                        );
                        $this->info("  {$saved} candles: {$cursor->format('Y-m-d H:i')} -> {$chunkEnd->format('Y-m-d H:i')}");
                    } catch (Throwable $exception) {
                        $failed = true;
                        $this->error('  '.$exception->getMessage());
                    }
                }
            }

            if (! $this->option('dry-run')) {
                $after = $quality->inspect($marketSymbol->symbol, $timeframe, true);
                $this->line("{$marketSymbol->symbol}: remaining missing_open_hours={$after['missing_open_hours']}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
