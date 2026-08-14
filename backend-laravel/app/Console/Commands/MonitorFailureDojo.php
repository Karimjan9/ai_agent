<?php

namespace App\Console\Commands;

use App\Services\FailureDojoService;
use App\Services\LearningLaneService;
use Illuminate\Console\Command;

class MonitorFailureDojo extends Command
{
    protected $signature = 'trading:monitor-failure-dojo {symbol?} {--timeframe=H1} {--json}';

    protected $description = 'Show focused failure-state curriculum progress without promotion side effects';

    public function handle(FailureDojoService $dojo, LearningLaneService $learning): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $result = [
            'protocol' => FailureDojoService::PROTOCOL,
            'scope' => [$symbol, $timeframe],
            'progress' => $dojo->progress($symbol, $timeframe),
            'learning_lane' => $learning->status($symbol, $timeframe),
            'promotion_evidence' => false,
        ];
        if ($this->option('json')) $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        else $this->info('Failure Dojo: '.json_encode($result['progress'], JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
