<?php

namespace App\Console\Commands;

use App\Models\MarketSymbol;
use App\Services\MarketData\MarketDataService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class UpdateMarketData extends Command
{
    protected $signature = 'market-data:update
                            {--symbol=}
                            {--timeframe=H1}
                            {--limit=1000}
                            {--from=}
                            {--to=}';

    protected $description = 'Update market candle data';

    public function handle(MarketDataService $marketDataService): int
    {
        try {
            $query = MarketSymbol::query()->where('is_active', true)->orderBy('priority');

            if ($this->option('symbol')) {
                $query->where('symbol', $this->option('symbol'));
            }

            $symbols = $query->get();

            if ($symbols->isEmpty()) {
                $this->warn('Active symbol topilmadi.');

                return self::SUCCESS;
            }

            $failed = 0;
            foreach ($symbols as $symbol) {
                try {
                    $saved = $marketDataService->updateCandles(
                        marketSymbol: $symbol,
                        timeframe: (string) $this->option('timeframe'),
                        limit: (int) $this->option('limit'),
                        from: $this->option('from') ? CarbonImmutable::parse((string) $this->option('from'), 'UTC') : null,
                        to: $this->option('to') ? CarbonImmutable::parse((string) $this->option('to'), 'UTC') : null,
                    );

                    $this->info("{$symbol->symbol} {$this->option('timeframe')}: {$saved} candle updated.");
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("{$symbol->symbol}: {$e->getMessage()}");
                }
            }

            return $failed === $symbols->count() ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
