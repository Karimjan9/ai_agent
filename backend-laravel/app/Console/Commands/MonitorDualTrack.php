<?php

namespace App\Console\Commands;

use App\Services\DualTrackMonitorService;
use App\Console\Commands\Concerns\OperationalCommand;

class MonitorDualTrack extends OperationalCommand
{
    protected $signature = 'trading:monitor-dual-track
        {symbol? : Laboratory symbol}
        {--timeframe=H1 : Laboratory timeframe}
        {--limit=100 : Number of recent observations}
        {--json : Print JSON}';

    protected $description = 'Monitor Champion/Council dual-track cells, disagreements and shadow routing';

    public function handle(DualTrackMonitorService $monitor): int
    {
        $result = $monitor->report(
            strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD')),
            strtoupper((string) $this->option('timeframe')),
            (int) $this->option('limit'),
        );

        if ($this->option('json')) {
            $this->writeJson($result, pretty: true);
            return self::SUCCESS;
        }

        $this->writeMetrics($result);
        return self::SUCCESS;
    }
}
