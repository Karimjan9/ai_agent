<?php

namespace App\Console\Commands;

use App\Services\LighthouseVerticalLoopMonitoringService;
use Illuminate\Console\Command;

class MonitorLighthouseVerticalLoop extends Command
{
    protected $signature = 'trading:monitor-lighthouse-loop
        {--symbol=XAUUSD : Lighthouse symbol; only XAUUSD is accepted}
        {--timeframe=H1 : Lighthouse timeframe; only H1 is accepted}
        {--no-persist : Do not append a monitoring snapshot or health row}
        {--strict : Return failure when the readiness contract is blocked}
        {--json : Print the complete readiness report as JSON}';

    protected $description = 'Monitor the XAUUSD H1 candidate-to-reality vertical loop without changing strategy evidence';

    public function handle(LighthouseVerticalLoopMonitoringService $monitor): int
    {
        $report = $monitor->inspect(
            (string) $this->option('symbol'),
            (string) $this->option('timeframe'),
            ! (bool) $this->option('no-persist'),
        );

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'Lighthouse loop %s %s: %s (stage=%s, score=%s, run #%s).',
                $report['symbol'] ?? '-',
                $report['timeframe'] ?? '-',
                strtoupper((string) ($report['status'] ?? 'unknown')),
                $report['current_stage'] ?? '-',
                $report['health_score'] ?? 0,
                $report['monitor_run_id'] ?? 'n/a',
            ));
            foreach ((array) ($report['checks'] ?? []) as $check) {
                $line = "[{$check['status']}] {$check['code']}: {$check['message']}";
                $check['status'] === 'blocked' ? $this->error($line) : ($check['status'] === 'attention' ? $this->warn($line) : $this->line($line));
            }
            $this->comment('Monitor read-only: candidates, gates, thresholds, paper promotion and live state were not changed.');
            $this->comment('NEXT: '.($report['next_operator_action'] ?? 'Review the JSON report.'));
        }

        return $this->option('strict') && ($report['status'] ?? null) === 'critical'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
