<?php

namespace App\Console\Commands;

use App\Services\TradingDeploymentSafetyService;
use Illuminate\Console\Command;

class CheckTradingDeploymentSafety extends Command
{
    protected $signature = 'trading:deployment-safety';
    protected $description = 'Show paper, OANDA practice and live-trading safety gates';
    public function handle(TradingDeploymentSafetyService $safety): int
    {
        $status = $safety->status();
        $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
