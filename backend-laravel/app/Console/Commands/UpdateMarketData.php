<?php

namespace App\Console\Commands;

use App\Models\MarketSymbol;
use App\Services\MarketData\MarketDataService;
use Illuminate\Console\Command;
use Throwable;

class UpdateMarketData extends Command
{
    protected $signature = 'market-data:update
                            {--symbol=}
                            {--timeframe=H1}
                            {--limit=1000}';

    protected $description = 'Update market candle data';

    public function handle(MarketDataService $marketDataService): int
    {
        try {
            $query = MarketSymbol::query()->where('is_active', true);

            if ($this->option('symbol')) {
                $query->where('symbol', $this->option('symbol'));
            }

            $symbols = $query->get();

            if ($symbols->isEmpty()) {
                $this->warn('Active symbol topilmadi.');

                return self::SUCCESS;
            }

            foreach ($symbols as $symbol) {
                $saved = $marketDataService->updateCandles(
                    marketSymbol: $symbol,
                    timeframe: (string) $this->option('timeframe'),
                    limit: (int) $this->option('limit'),
                );

                $this->info("{$symbol->symbol} {$this->option('timeframe')}: {$saved} candle updated.");
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
