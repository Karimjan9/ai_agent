<?php

namespace App\Console\Commands;

use App\Services\LearningVelocityGateService;
use Illuminate\Console\Command;

/** Read-only monitor for research backpressure and evidence throughput. */
class MonitorLearningVelocity extends Command
{
    protected $signature = 'trading:monitor-learning-velocity {symbol?} {--timeframe=H1} {--json}';

    protected $description = 'Monitor screen-to-replay learning velocity and generation admission';

    public function handle(LearningVelocityGateService $velocity): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $payload = $velocity->inspect($symbol, $timeframe);
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Metric', 'Value'], collect($payload)
            ->except(['observations'])
            ->map(fn ($value, $key): array => [
                $key,
                is_scalar($value) || $value === null
                    ? $value
                    : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])->values()->all());
        $this->line('Generations: '.json_encode($payload['observations'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
