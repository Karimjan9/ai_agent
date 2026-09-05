<?php

namespace App\Console\Commands;

use App\Services\AutonomousLearningProgressDirectorService;
use Illuminate\Console\Command;

class AdvanceAutonomousLearningProgress extends Command
{
    protected $signature = 'trading:advance-learning-progress {symbol?} {--timeframe=H1} {--apply} {--json}';
    protected $description = 'Advance exactly one safe Edge-to-Mastery research cohort after runtime and settlement checks.';

    public function handle(AutonomousLearningProgressDirectorService $director): int
    {
        $result = $director->advance(
            strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD')),
            strtoupper((string) $this->option('timeframe')),
            (bool) $this->option('apply'),
        );
        if ($this->option('json')) $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        else $this->info(($result['action'] ?? 'WAIT').': '.($result['reason'] ?? data_get($result, 'result.status', $result['status'] ?? 'unknown')));

        // Expected fail-closed waits are healthy scheduler outcomes. Unknown
        // exceptions still fail normally before this return is reached.
        return self::SUCCESS;
    }
}
