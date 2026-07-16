<?php

namespace App\Console\Commands;

use App\Services\InstrumentIntelligenceService;
use Illuminate\Console\Command;

class RefreshSymbolProfiles extends Command
{
    protected $signature = 'profiles:refresh {--symbol=*} {--timeframe=*}';

    protected $description = 'Refresh instrument intelligence profiles for active market symbols';

    public function handle(InstrumentIntelligenceService $profiles): int
    {
        $symbols = $this->option('symbol') ?: null;
        $timeframes = $this->option('timeframe') ?: ['M15', 'H1'];
        $items = $profiles->refresh($symbols, $timeframes);

        $this->info("Symbol profiles refreshed: {$items->count()} profiles.");

        return self::SUCCESS;
    }
}
