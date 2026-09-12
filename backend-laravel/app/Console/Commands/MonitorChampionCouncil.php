<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperationalCommand;
use App\Services\ChampionCouncilMonitorService;

class MonitorChampionCouncil extends OperationalCommand
{
    protected $signature = 'trading:monitor-champion-council
        {symbol? : Laboratory symbol}
        {--timeframe=H1 : Laboratory timeframe}
        {--json : Print JSON}';

    protected $description = 'Monitor Champion Council roles, passports, curriculum and synergy gates';

    public function handle(ChampionCouncilMonitorService $monitor): int
    {
        [$symbol, $timeframe] = $this->canonicalLaboratoryScope(
            (string) ($this->argument('symbol') ?: 'XAUUSD'),
            (string) $this->option('timeframe'),
        );
        $result = $monitor->report(
            $symbol,
            $timeframe,
        );
        if ($this->option('json')) {
            $this->writeJson($result, pretty: true);

            return self::SUCCESS;
        }
        $this->writeMetrics($result);

        return self::SUCCESS;
    }
}
