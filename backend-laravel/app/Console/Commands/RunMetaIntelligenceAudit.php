<?php

namespace App\Console\Commands;

use App\Services\MetaIntelligenceService;
use Illuminate\Console\Command;

class RunMetaIntelligenceAudit extends Command
{
    protected $signature = 'meta:audit';

    protected $description = 'Run Meta Intelligence audit for knowledge health, belief decay and blind spots';

    public function handle(MetaIntelligenceService $metaIntelligence): int
    {
        $run = $metaIntelligence->runAudit();

        if (! $run) {
            $this->warn('Meta Intelligence tables topilmadi. Avval migration ishlashi kerak.');

            return self::SUCCESS;
        }

        $this->info("Meta audit #{$run->id} completed: health {$run->knowledge_health_score}%, contradictions {$run->contradictions_found}, unknown zones {$run->unknown_zones_found}.");

        return self::SUCCESS;
    }
}
