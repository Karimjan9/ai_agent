<?php

namespace App\Console\Commands;

use App\Services\CausalIntelligenceService;
use Illuminate\Console\Command;

class DiscoverCausalIntelligence extends Command
{
    protected $signature = 'causal:discover';

    protected $description = 'Build causal graph, counterfactuals, interventions and root-cause library';

    public function handle(CausalIntelligenceService $causal): int
    {
        $run = $causal->discover();

        if (! $run) {
            $this->warn('Causal Intelligence tables topilmadi. Avval migration ishlashi kerak.');

            return self::SUCCESS;
        }

        $this->info("Causal discovery #{$run->id} completed: {$run->edges_created} edges, {$run->effects_estimated} effects, {$run->interventions_created} interventions.");

        return self::SUCCESS;
    }
}
