<?php

namespace App\Console\Commands;

use App\Services\AutonomousTheoryGenerationService;
use Illuminate\Console\Command;

class GenerateAutonomousTheories extends Command
{
    protected $signature = 'theory:generate';

    protected $description = 'Generate higher-order quant theories from laws, causal evidence and root causes';

    public function handle(AutonomousTheoryGenerationService $theoryGeneration): int
    {
        $run = $theoryGeneration->generate();

        if (! $run) {
            $this->warn('Theory Generation tables topilmadi. Avval migration ishlashi kerak.');

            return self::SUCCESS;
        }

        $this->info("Theory generation #{$run->id} completed: {$run->theories_generated} theories, {$run->battles_created} battles, {$run->predictions_created} predictions.");

        return self::SUCCESS;
    }
}
