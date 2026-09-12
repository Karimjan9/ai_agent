<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\CanonicalLaboratoryScope;
use App\Services\FailureDojoService;
use App\Services\LearningLaneService;
use Illuminate\Console\Command;

class MonitorFailureDojo extends Command
{
    use CanonicalLaboratoryScope;

    protected $signature = 'trading:monitor-failure-dojo {symbol?} {--timeframe=H1} {--json} {--apply-context-firewall : Quarantine pending cells with incomplete regime/session/volume context}';

    protected $description = 'Show focused failure-state curriculum progress without promotion side effects';

    public function handle(FailureDojoService $dojo, LearningLaneService $learning): int
    {
        [$symbol, $timeframe] = $this->canonicalLaboratoryScope(
            (string) ($this->argument('symbol') ?: 'XAUUSD'),
            (string) $this->option('timeframe'),
        );
        $firewall = $dojo->reconcileContextFirewall($symbol, $timeframe, (bool) $this->option('apply-context-firewall'));
        $result = [
            'protocol' => FailureDojoService::PROTOCOL,
            'scope' => [$symbol, $timeframe],
            'progress' => $dojo->summary($symbol, $timeframe),
            'context_firewall' => $firewall,
            'learning_lane' => $learning->status($symbol, $timeframe),
            'promotion_evidence' => false,
        ];
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Failure Dojo: '.json_encode($result['progress'], JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
