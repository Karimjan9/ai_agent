<?php

namespace App\Console\Commands;

use App\Services\TradingInstrumentOperatingSystemService;
use Illuminate\Console\Command;

class SeedTradingInstrumentRegistry extends Command
{
    protected $signature = 'instruments:seed';

    protected $description = 'Create or refresh the Trading Instrument Operating System registry';

    public function handle(TradingInstrumentOperatingSystemService $registry): int
    {
        $result = $registry->seedDefaults();
        $this->info(sprintf('Trading instrument registry ready: %d instruments, %d playbooks.', $result['instruments']->count(), $result['playbooks']->count()));

        return self::SUCCESS;
    }
}
