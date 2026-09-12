<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperationalCommand;
use App\Services\DualTrackMonitorService;

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
        [$symbol, $timeframe] = $this->canonicalLaboratoryScope(
            (string) ($this->argument('symbol') ?: 'XAUUSD'),
            (string) $this->option('timeframe'),
        );
        $result = $monitor->report(
            $symbol,
            $timeframe,
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
