<?php

namespace App\Console\Commands;

use App\Services\UniversalKnowledgeGraphService;
use Illuminate\Console\Command;

class MineKnowledgeGraph extends Command
{
    protected $signature = 'knowledge:mine';

    protected $description = 'Mine Universal Trading Knowledge Graph from existing trading evidence';

    public function handle(UniversalKnowledgeGraphService $knowledgeGraph): int
    {
        $run = $knowledgeGraph->mine();

        $this->info("Knowledge mining completed: {$run->nodes_created} nodes, {$run->edges_created} edges, {$run->claims_created} claims.");

        return self::SUCCESS;
    }
}
