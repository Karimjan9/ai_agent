<?php

namespace App\Console\Commands;

use App\Services\QuantCivilizationService;
use Illuminate\Console\Command;

class SyncQuantCivilization extends Command
{
    protected $signature = 'civilization:sync';

    protected $description = 'Synchronize AI Civilization agents, credits, council decisions, goals and memory';

    public function handle(QuantCivilizationService $civilization): int
    {
        $decision = $civilization->synchronize();

        if (! $decision) {
            $this->warn('AI Civilization tables topilmadi. Avval migration ishlashi kerak.');

            return self::SUCCESS;
        }

        $this->info("AI Civilization sync completed: council decision #{$decision->id} {$decision->final_decision}, consensus {$decision->consensus_score}%.");

        return self::SUCCESS;
    }
}
