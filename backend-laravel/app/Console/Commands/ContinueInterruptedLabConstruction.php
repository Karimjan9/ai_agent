<?php

namespace App\Console\Commands;

use App\Services\LabPopulationService;
use Illuminate\Console\Command;

class ContinueInterruptedLabConstruction extends Command
{
    protected $signature = 'trading:continue-lab-construction {generationId : Immutable generation database ID} {--max-seats=3 : Missing seats to construct in this bounded run}';

    protected $description = 'Create only missing planned seats of a timed-out lab constructor; dispatch stays blocked until 100% complete';

    public function handle(LabPopulationService $population): int
    {
        $result = $population->continueInterruptedConstruction(
            (int) $this->argument('generationId'),
            (int) $this->option('max-seats'),
        );
        $generation = $result['generation'] ?? null;
        if (! $generation) {
            $this->error('Generation continuation unavailable: '.($result['status'] ?? 'unknown'));
            return self::FAILURE;
        }
        $this->info(sprintf(
            'G%s: %s; this run created slots [%s]; completed %d/%d.',
            $generation->generation,
            $result['status'],
            implode(',', (array) ($result['created_slots'] ?? [])),
            count((array) ($result['completed_slots'] ?? [])),
            (int) data_get($generation->trigger_context, 'constructor_continuation.planned_slots', $generation->population_size),
        ));
        if (($result['failures'] ?? []) !== []) {
            $this->warn('Constructor failures: '.json_encode($result['failures']));
        }
        return self::SUCCESS;
    }
}
