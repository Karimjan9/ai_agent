<?php

namespace App\Console\Commands;

use App\Services\CanonicalSkillCartridgeService;
use Illuminate\Console\Command;

class ReconcileSkillCartridges extends Command
{
    protected $signature = 'trading:reconcile-skill-cartridges {symbol?} {--timeframe=H1} {--json}';
    protected $description = 'Terminalize stale observed Skill Zoo rows that have no settled paired executable intervention.';

    public function handle(CanonicalSkillCartridgeService $cartridges): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD')); $timeframe = strtoupper((string) $this->option('timeframe'));
        $result = [...$cartridges->reconcileLegacy($symbol, $timeframe), 'revision_backfill' => $cartridges->backfillImmutableRevisions($symbol, $timeframe)];
        if ($this->option('json')) $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        else $this->info('Skill cartridge reconciliation complete.');
        return self::SUCCESS;
    }
}
