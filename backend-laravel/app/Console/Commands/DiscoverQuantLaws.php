<?php

namespace App\Console\Commands;

use App\Services\UniversalQuantLawsService;
use Illuminate\Console\Command;

class DiscoverQuantLaws extends Command
{
    protected $signature = 'laws:discover';

    protected $description = 'Discover universal quant law candidates and promote validated laws';

    public function handle(UniversalQuantLawsService $laws): int
    {
        $run = $laws->discover();

        if (! $run) {
            $this->warn('Quant Laws tables topilmadi. Avval migration ishlashi kerak.');

            return self::SUCCESS;
        }

        $this->info("Quant laws discovery #{$run->id} completed: {$run->candidates_created} candidates, {$run->laws_promoted} laws, {$run->conflicts_found} conflicts.");

        return self::SUCCESS;
    }
}
