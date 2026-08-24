<?php

namespace App\Console\Commands;

use App\Services\LabPopulationService;
use Illuminate\Console\Command;

class BuildLabGeneration extends Command
{
    protected $signature = 'trading:lab-generation {symbol?} {--trigger=new_data} {--timeframe=H1} {--force}';

    protected $description = 'Create a configurable AI Laboratory generation after new candles, drift, or degradation';

    public function handle(LabPopulationService $service): int
    {
        $symbols = $this->argument('symbol') ? [strtoupper($this->argument('symbol'))] : ['XAUUSD', 'EURUSD', 'GBPUSD'];
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $trigger = (string) $this->option('trigger');
        // Candidate handoff is invoked after screening/full selection has
        // already produced the current curriculum.  Historical learning is
        // refreshed by its own bounded scheduler lane; rescanning the full
        // candle-event plane synchronously here can hold generation creation
        // for minutes without creating a row.  The population builder still
        // consumes the latest append-only insights and checkpoint inputs.
        $refreshHistoricalLearning = ! in_array($trigger, ['candidate_handoff', 'data_edge_audit', 'operator_successor'], true);
        foreach ($symbols as $symbol) {
            $generation = $service->build(
                $symbol,
                $trigger,
                (bool) $this->option('force'),
                $timeframe,
                [],
                false,
                $refreshHistoricalLearning,
            );
            if ($generation) {
                $this->info("{$symbol} {$timeframe}: generation {$generation->generation}, {$generation->agents->count()} agents.");

                continue;
            }

            $outcome = $service->lastBuildOutcome();
            $reason = (string) ($outcome['reason_code'] ?? 'POPULATION_BUILD_UNSPECIFIED');
            $retryable = (bool) ($outcome['retryable'] ?? false) ? 'yes' : 'no';
            $this->warn("{$symbol} {$timeframe}: generation blocked ({$reason}); retryable={$retryable}.");
            if ($this->output->isVerbose()) {
                $this->line(json_encode($outcome['context'] ?? [], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }
        }

        return self::SUCCESS;
    }
}
