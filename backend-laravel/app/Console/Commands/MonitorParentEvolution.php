<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperationalCommand;
use App\Services\ParentAwareCreditService;

class MonitorParentEvolution extends OperationalCommand
{
    protected $signature = 'trading:monitor-parent-evolution
        {symbol? : Laboratory symbol}
        {--timeframe=H1 : Laboratory timeframe}
        {--json : Print JSON}';

    protected $description = 'Monitor autonomous/mentored parent evolution, contextual trust, credits and counterfactuals';

    public function handle(ParentAwareCreditService $evolution): int
    {
        [$symbol, $timeframe] = $this->canonicalLaboratoryScope(
            (string) ($this->argument('symbol') ?: 'XAUUSD'),
            (string) $this->option('timeframe'),
        );
        $result = $evolution->monitor(
            $symbol,
            $timeframe,
        );
        if ($this->option('json')) {
            $this->writeJson($result, pretty: true);

            return self::SUCCESS;
        }
        $this->writeMetrics($result, ['protocol', 'promotion_evidence']);

        return self::SUCCESS;
    }
}
