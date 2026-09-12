<?php

namespace App\Console\Commands;

use App\Services\AutonomousLearningProgressDirectorService;
use Illuminate\Console\Command;

class AdvanceAutonomousLearningProgress extends Command
{
    protected $signature = 'trading:advance-learning-progress
        {symbol?}
        {--timeframe= : Internal compatibility override; XAUUSD remains one MTF organism}
        {--apply}
        {--arbiter-authorized : Internal authority from the single Research Loop Arbiter}
        {--planning-only : Determine the evidence-ranked action without runtime materialization}
        {--json}';

    protected $description = 'Reconcile Edge-to-Mastery evidence for the single XAUUSD organism without stealing normal generation ownership.';

    public function handle(AutonomousLearningProgressDirectorService $director): int
    {
        $result = $director->advance(
            strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD')),
            strtoupper((string) ($this->option('timeframe') ?: config('services.xauusd_organism.laboratory_storage_timeframe', 'H1'))),
            (bool) $this->option('apply'),
            (bool) $this->option('arbiter-authorized'),
            (bool) $this->option('planning-only'),
        );
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(($result['action'] ?? 'WAIT').': '.($result['reason'] ?? data_get($result, 'result.status', $result['status'] ?? 'unknown')));
        }

        // Expected fail-closed waits are healthy scheduler outcomes. Unknown
        // exceptions still fail normally before this return is reached.
        return self::SUCCESS;
    }
}
