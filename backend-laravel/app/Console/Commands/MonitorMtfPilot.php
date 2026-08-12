<?php

namespace App\Console\Commands;

use App\Services\MtfPilotMonitoringService;
use Illuminate\Console\Command;

class MonitorMtfPilot extends Command
{
    protected $signature = 'trading:monitor-mtf-pilot
        {--symbol=XAUUSD : MTF pilot symbol}
        {--lookback-hours=24 : Evidence lookback window}
        {--strict : Return failure when a critical MTF check exists}
        {--json : Print the complete monitor report as JSON}';

    protected $description = 'Monitor closed H1 context, M15 execution, Risk Sentinel, paper lifecycle, shadow twin and ablation controls';

    public function handle(MtfPilotMonitoringService $monitor): int
    {
        $report = $monitor->inspect(
            (string) $this->option('symbol'),
            max(1, (int) $this->option('lookback-hours')),
        );

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

        return $this->option('strict') && $report['status'] === 'critical'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
