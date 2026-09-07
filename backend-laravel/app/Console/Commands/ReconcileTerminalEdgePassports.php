<?php

namespace App\Console\Commands;

use App\Services\DependencyAwareEdgeGenesisFoundryService;
use Illuminate\Console\Command;

class ReconcileTerminalEdgePassports extends Command
{
    protected $signature = 'trading:reconcile-terminal-edge-passports {symbol=XAUUSD} {--timeframe=H1} {--apply : Persist only a derived terminal passport status} {--json}';
    protected $description = 'Close stale Edge passport projections only after all immutable packet arms are terminal';

    public function handle(DependencyAwareEdgeGenesisFoundryService $edge): int
    {
        $result = $edge->reconcileTerminalPassportStates(
            strtoupper((string) $this->argument('symbol')),
            strtoupper((string) $this->option('timeframe')),
            (bool) $this->option('apply'),
        );
        if ($this->option('json')) $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        else $this->info('Edge terminal passport projection: '.($result['status'] ?? 'unknown'));

        return self::SUCCESS;
    }
}
