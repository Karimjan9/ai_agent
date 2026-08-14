<?php

namespace App\Console\Commands;

use App\Services\ParentAwareCreditService;
use Illuminate\Console\Command;

class MonitorParentEvolution extends Command
{
    protected $signature = 'trading:monitor-parent-evolution
        {symbol? : Laboratory symbol}
        {--timeframe=H1 : Laboratory timeframe}
        {--json : Print JSON}';

    protected $description = 'Monitor autonomous/mentored parent evolution, contextual trust, credits and counterfactuals';

    public function handle(ParentAwareCreditService $evolution): int
    {
        $result = $evolution->monitor(
            strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD')),
            strtoupper((string) $this->option('timeframe')),
        );
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }
        $this->table(['Metric', 'Value'], collect($result)->except(['protocol', 'promotion_evidence'])->map(
            fn ($value, $key): array => [(string) $key, is_scalar($value) || $value === null ? $value : json_encode($value)],
        )->values()->all());
        return self::SUCCESS;
    }
}
