<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Retained as a safe compatibility command only.  EvolutionProposal is a
 * legacy write plane; canonical evolution is created by LabPopulationService
 * from immutable LabEvaluationRun evidence and never by this command.
 */
class ApplyEvolutionProposals extends Command
{
    protected $signature = 'trading:evolve {--limit=5}';

    protected $description = 'Deprecated compatibility alias; canonical Lab owns evolution';

    public function handle(): int
    {
        $this->warn('trading:evolve deprecated: EvolutionProposal apply qilinmadi. Canonical Lab evidence pipeline faol.');

        return self::SUCCESS;
    }
}
