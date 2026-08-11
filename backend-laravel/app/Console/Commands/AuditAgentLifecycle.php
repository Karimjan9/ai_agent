<?php

namespace App\Console\Commands;

use App\Services\AgentLifecycleAuditService;
use Illuminate\Console\Command;

class AuditAgentLifecycle extends Command
{
    protected $signature = 'trading:audit-agent-lifecycle {symbol?} {--timeframe=} {--json} {--shallow : Skip expensive preflight, full-history and volume checks} {--no-persist : Do not append the audit summary to system_logs}';

    protected $description = 'Audit every agent lifecycle stage, queue, lineage, data contract, volume and promotion boundary';

    public function handle(AgentLifecycleAuditService $audit): int
    {
        $report = $audit->audit(
            $this->argument('symbol') ? (string) $this->argument('symbol') : null,
            $this->option('timeframe') ? (string) $this->option('timeframe') : null,
            ! (bool) $this->option('shallow'),
            ! (bool) $this->option('no-persist'),
        );

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $summary = (array) ($report['summary'] ?? []);
            $this->info(sprintf(
                'Agent lifecycle audit: %s; labs=%d, blocked=%d, attention=%d, in_progress=%d.',
                strtoupper((string) ($summary['status'] ?? 'unknown')),
                (int) ($summary['laboratory_count'] ?? 0),
                (int) ($summary['blocked_checks'] ?? 0),
                (int) ($summary['attention_checks'] ?? 0),
                (int) ($summary['in_progress_checks'] ?? 0),
            ));
            $this->table(
                ['Symbol', 'TF', 'G', 'Generation', 'Agents', 'Scope', 'Preflight', 'Forward/Elite', 'Promotion'],
                collect((array) ($report['laboratories'] ?? []))->map(function (array $scope): array {
                    $metrics = (array) ($scope['metrics'] ?? []);

                    return [
                        $scope['symbol'] ?? '-',
                        $scope['timeframe'] ?? '-',
                        $scope['generation'] ?? '-',
                        $scope['generation_status'] ?? '-',
                        $scope['agent_count'] ?? 0,
                        $scope['status'] ?? '-',
                        ((int) ($metrics['preflight_passed'] ?? 0)).'/'.((int) ($metrics['preflight_passed'] ?? 0) + (int) ($metrics['preflight_failed'] ?? 0)),
                        ($metrics['forward_elite_stage'] ?? '-').
                            ' F'.(int) ($metrics['forward_gate_passed'] ?? 0).
                            '/E'.(int) ($metrics['elite_portfolio_passed'] ?? 0),
                        'FAIL-CLOSED',
                    ];
                })->all(),
            );

            $findings = collect((array) ($report['findings'] ?? []))->take(30)->map(fn (array $finding): array => [
                $finding['severity'] ?? '-',
                $finding['code'] ?? '-',
                $finding['status'] ?? '-',
                $finding['message'] ?? '-',
            ])->all();
            if ($findings !== []) {
                $this->table(['Severity', 'Code', 'Status', 'Finding'], $findings);
            }
            foreach ((array) ($report['recommendations'] ?? []) as $recommendation) {
                $this->line('NEXT: '.$recommendation);
            }
        }

        return ($report['summary']['status'] ?? 'blocked') === 'blocked'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
