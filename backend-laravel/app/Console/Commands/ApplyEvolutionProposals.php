<?php

namespace App\Console\Commands;

use App\Models\EvolutionProposal;
use App\Services\EvolutionProposalApplicationService;
use Illuminate\Console\Command;

class ApplyEvolutionProposals extends Command
{
    protected $signature = 'trading:evolve {--limit=5}';

    protected $description = 'Apply pending strategy evolution proposals as new testing model versions';

    public function handle(EvolutionProposalApplicationService $applicationService): int
    {
        $proposals = EvolutionProposal::query()
            ->with('modelVersion')
            ->whereIn('status', ['pending', 'approved'])
            ->oldest()
            ->limit((int) $this->option('limit'))
            ->get();

        if ($proposals->isEmpty()) {
            $this->info('No evolution proposals to apply.');

            return self::SUCCESS;
        }

        $applied = 0;

        foreach ($proposals as $proposal) {
            $modelVersion = $applicationService->apply($proposal);

            if (! $modelVersion) {
                continue;
            }

            $applied++;
            $this->info("Applied proposal #{$proposal->id}: {$modelVersion->strategy}");
        }

        $this->info("Evolution applied: {$applied}");

        return self::SUCCESS;
    }
}
