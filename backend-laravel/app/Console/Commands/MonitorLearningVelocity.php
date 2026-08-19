<?php

namespace App\Console\Commands;

use App\Services\LearningVelocityGateService;
use App\Console\Commands\Concerns\OperationalCommand;

/** Read-only monitor for research backpressure and evidence throughput. */
class MonitorLearningVelocity extends OperationalCommand
{
    protected $signature = 'trading:monitor-learning-velocity {symbol?} {--timeframe=H1} {--full : Run the detailed per-agent audit} {--json}';

    protected $description = 'Monitor screen-to-replay learning velocity and generation admission';

    public function handle(LearningVelocityGateService $velocity): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $payload = $this->option('full')
            ? $velocity->inspect($symbol, $timeframe)
            : $velocity->summary($symbol, $timeframe);
        if ($this->option('json')) {
            $this->writeJson($payload);

            return self::SUCCESS;
        }

        $this->writeMetrics($payload, ['observations']);
        $this->line('Generations: '.json_encode($payload['observations'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
