<?php

namespace App\Console\Commands;

use App\Services\MarketHealthService;
use Illuminate\Console\Command;

class CheckMarketHealth extends Command
{
    protected $signature = 'market:health {--recover : Allow MT5/Wine recovery script execution} {--strict : Return failure when any feed is stale or lost}';

    protected $description = 'Check MT5 market feed health, send alerts, and optionally run recovery.';

    public function handle(MarketHealthService $health): int
    {
        $checks = $health->check((bool) $this->option('recover'));
        $lost = $checks->where('status', 'lost')->count();
        $stale = $checks->where('status', 'stale')->count();

        $this->info("Market health checked: {$checks->count()} feeds, {$lost} lost, {$stale} stale.");

        return $this->option('strict') && ($lost > 0 || $stale > 0)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
