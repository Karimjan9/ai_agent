<?php

namespace App\Console\Commands;

use App\Services\LearningLaneService;
use Illuminate\Console\Command;

/** Supersede duplicate learning cells without deleting immutable evidence. */
class DeduplicateLearningPairs extends Command
{
    protected $signature = 'trading:deduplicate-learning-pairs {symbol?} {--timeframe=} {--family=} {--apply : Mark duplicate pair projections as superseded}';

    protected $description = 'Deduplicate research-only learning pairs while preserving response maps and active dispatches';

    public function handle(LearningLaneService $learning): int
    {
        if (! $this->option('apply')) {
            $this->warn('Dry-run only: pass --apply to supersede duplicate projections; immutable evidence is never deleted.');

            return self::SUCCESS;
        }

        $count = $learning->deduplicatePairs(
            $this->argument('symbol') ? strtoupper((string) $this->argument('symbol')) : null,
            $this->option('timeframe') ? strtoupper((string) $this->option('timeframe')) : null,
            $this->option('family') ? (string) $this->option('family') : null,
        );
        $this->info("{$count} duplicate learning pair projection(s) superseded.");

        return self::SUCCESS;
    }
}
