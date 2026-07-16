<?php

namespace App\Console\Commands;

use App\Services\PaperTradingExecutionService;
use Illuminate\Console\Command;

class MonitorPaperTrading extends Command
{
    protected $signature = 'trading:paper-monitor';

    protected $description = 'Open, reconcile, and score paper orders for forward-validated laboratory challengers';

    public function handle(PaperTradingExecutionService $execution): int
    {
        $stats = $execution->run();

        $this->info("Paper execution: {$stats['candidates']} candidates, {$stats['opened']} opened, {$stats['closed']} closed.");

        return self::SUCCESS;
    }
}
