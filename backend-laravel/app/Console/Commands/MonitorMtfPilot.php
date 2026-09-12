<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperationalCommand;
use App\Services\MtfPilotMonitoringService;

class MonitorMtfPilot extends OperationalCommand
{
    protected $signature = 'trading:monitor-mtf-pilot
        {--symbol=XAUUSD : MTF pilot symbol}
        {--lookback-hours=24 : Evidence lookback window}
        {--strict : Return failure when a critical MTF check exists}
        {--json : Print the complete monitor report as JSON}';

    protected $description = 'Monitor H1 regime and M15 setup evidence inside the single XAUUSD organism; production execution remains M5';

    public function handle(MtfPilotMonitoringService $monitor): int
    {
        $report = $monitor->inspect(
            (string) $this->option('symbol'),
            max(1, (int) $this->option('lookback-hours')),
        );

        if ($this->option('json')) {
            $this->writeJson($report, pretty: true);
        } else {
            $this->info(sprintf(
                'MTF monitor %s: %s (score %s, run #%s).',
                $report['symbol'],
                strtoupper((string) $report['status']),
                $report['health_score'],
                $report['monitor_run_id'] ?? 'n/a',
            ));
            foreach ((array) ($report['checks'] ?? []) as $check) {
                $line = "[{$check['status']}] {$check['code']}: {$check['message']}";
                $check['status'] === 'critical' ? $this->error($line) : ($check['status'] === 'warning' ? $this->warn($line) : $this->line($line));
            }
            $this->comment('Monitor read-only: strategy, gate thresholds, paper promotion and official evidence were not changed.');
        }

        return $this->statusExitCode((string) $report['status'], (bool) $this->option('strict'));
    }
}
