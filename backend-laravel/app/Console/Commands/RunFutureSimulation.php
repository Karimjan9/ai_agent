<?php

namespace App\Console\Commands;

use App\Services\FutureSimulationService;
use Illuminate\Console\Command;

class RunFutureSimulation extends Command
{
    protected $signature = 'future:simulate
                            {--symbol=XAUUSD}
                            {--timeframe=H1}
                            {--scenarios=1000}';

    protected $description = 'Run Future Simulation Engine for latest market genome';

    public function handle(FutureSimulationService $futureSimulation): int
    {
        $run = $futureSimulation->simulate(
            (string) $this->option('symbol'),
            (string) $this->option('timeframe'),
            (int) $this->option('scenarios'),
        );

        if (! $run) {
            $this->warn('Latest Market Genome topilmadi. Avval market-data:update ishlashi kerak.');

            return self::SUCCESS;
        }

        $this->info("Future simulation #{$run->id} completed: {$run->scenario_count} scenarios, future confidence {$run->future_confidence}%.");

        return self::SUCCESS;
    }
}
