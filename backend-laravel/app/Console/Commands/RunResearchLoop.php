<?php

namespace App\Console\Commands;

use App\Services\ResearchLoopArbiterService;
use Illuminate\Console\Command;

class RunResearchLoop extends Command
{
    protected $signature = 'trading:run-research-loop
        {--symbol=XAUUSD : Unified organism symbol}
        {--dry-run : Select without persistence, leases or dispatch}
        {--json : Print the complete immutable decision}';

    protected $description = 'Select exactly one evidence-ranked XAUUSD research action through the canonical arbiter';

    public function handle(ResearchLoopArbiterService $arbiter): int
    {
        $result = $arbiter->tick(
            (string) $this->option('symbol'),
            (string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1'),
            (bool) $this->option('dry-run'),
        );
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info((string) ($result['action'] ?? 'WAIT').': '.(string) ($result['status'] ?? 'unknown'));
        }

        return self::SUCCESS;
    }
}
