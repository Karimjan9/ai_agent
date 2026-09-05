<?php

namespace App\Console\Commands;

use App\Services\DependencyAwareEdgeGenesisFoundryService;
use Illuminate\Console\Command;

class EdgeGenesisStatus extends Command
{
    protected $signature = 'trading:edge-genesis-status {symbol?} {--timeframe=H1} {--json}';
    protected $description = 'Show research-only Dependency-Aware Edge Genesis progress and compute-waste telemetry.';

    public function handle(DependencyAwareEdgeGenesisFoundryService $foundry): int
    {
        $result = $foundry->dashboard(strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD')), strtoupper((string) $this->option('timeframe')));
        if ($this->option('json')) $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        else $this->table(array_keys($result), [array_map(fn ($value) => is_scalar($value) || $value === null ? $value : json_encode($value), array_values($result))]);
        return self::SUCCESS;
    }
}
