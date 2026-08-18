<?php

namespace App\Console\Commands;

use App\Services\RuntimeMonitoringService;
use Illuminate\Console\Command;

class MonitorRuntime extends Command
{
    protected $signature = 'system:runtime-monitor
        {--json : Emit one compact JSON snapshot}
        {--persist : Store component health in service_health_checks}
        {--watch : Continue polling until the process is stopped}
        {--interval=30 : Poll interval in seconds when --watch is used}';

    protected $description = 'Monitor Redis, queues, AI service, scheduler and agent lifecycle state';

    public function handle(RuntimeMonitoringService $monitor): int
    {
        $watch = (bool) $this->option('watch');
        $interval = max(5, min(3600, (int) $this->option('interval')));

        do {
            $report = $monitor->inspect((bool) $this->option('persist'));
            $this->render($report);

            if (! $watch) {
                return ($report['overall'] ?? 'ok') === 'critical' ? self::FAILURE : self::SUCCESS;
            }

            sleep($interval);
        } while (true);
    }

    /** @param array<string, mixed> $report */
    private function render(array $report): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info(sprintf(
            'Runtime monitor %s: %s (critical=%d warning=%d)',
            $report['checked_at'] ?? now()->toIso8601String(),
            strtoupper((string) ($report['overall'] ?? 'unknown')),
            (int) ($report['critical'] ?? 0),
            (int) ($report['warning'] ?? 0),
        ));
        $this->table(
            ['Component', 'Status', 'Score', 'Message'],
            collect((array) ($report['checks'] ?? []))->map(fn (array $check, string $key): array => [
                $key,
                $check['status'] ?? 'unknown',
                $check['score'] ?? 0,
                $check['message'] ?? '',
            ])->values()->all(),
        );
    }
}
