<?php

namespace App\Console\Commands;

use App\Models\MarketSymbol;
use App\Services\MarketData\HistoricalDataQualityService;
use Illuminate\Console\Command;

class CheckHistoricalDataQuality extends Command
{
    protected $signature = 'market-data:quality {symbol?} {--timeframe=H1} {--json}';

    protected $description = 'Hard-gate historical candle completeness before learning or promotion';

    public function handle(HistoricalDataQualityService $quality): int
    {
        $symbols = $this->argument('symbol')
            ? [strtoupper((string) $this->argument('symbol'))]
            : MarketSymbol::query()->where('is_active', true)->orderBy('symbol')->pluck('symbol')->all();
        $results = collect($symbols)->map(fn (string $symbol): array => $quality->inspect(
            $symbol,
            (string) $this->option('timeframe'),
            true,
        ));

        if ($this->option('json')) {
            $this->line($results->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($results as $result) {
                $this->line(sprintf(
                    '%s %s: %s, rows=%d, gaps=%d, missing_open_hours=%d',
                    $result['symbol'],
                    $result['timeframe'],
                    strtoupper($result['status']),
                    $result['row_count'],
                    $result['gap_intervals'],
                    $result['missing_open_hours'],
                ));
                foreach ($result['reasons'] as $reason) {
                    $this->warn('  - '.$reason);
                }
            }
        }

        return $results->contains(fn (array $result): bool => $result['status'] !== 'ready')
            ? self::FAILURE
            : self::SUCCESS;
    }
}
