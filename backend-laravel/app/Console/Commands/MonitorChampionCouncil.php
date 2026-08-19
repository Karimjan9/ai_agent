<?php

namespace App\Console\Commands;

use App\Services\ChampionCouncilMonitorService;
use App\Console\Commands\Concerns\OperationalCommand;

class MonitorChampionCouncil extends OperationalCommand
{
    protected $signature = 'trading:monitor-champion-council
        {symbol? : Laboratory symbol}
        {--timeframe=H1 : Laboratory timeframe}
        {--json : Print JSON}';

    protected $description = 'Monitor Champion Council roles, passports, curriculum and synergy gates';

    public function handle(ChampionCouncilMonitorService $monitor): int
    {
        $result = $monitor->report(
            strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD')),
            strtoupper((string) $this->option('timeframe')),
        );
        if ($this->option('json')) {
            $this->writeJson($result, pretty: true);
            return self::SUCCESS;
        }
        $this->writeMetrics($result);
        return self::SUCCESS;
    }
}
