<?php

namespace App\Console\Commands;

use App\Services\RealityVerificationService;
use Illuminate\Console\Command;

class VerifyReality extends Command
{
    protected $signature = 'reality:verify';

    protected $description = 'Verify knowledge, laws and theories against operational market reality evidence';

    public function handle(RealityVerificationService $reality): int
    {
        $run = $reality->verify();

        if (! $run) {
            $this->warn('Reality Verification tables topilmadi. Avval migration ishlashi kerak.');

            return self::SUCCESS;
        }

        $this->info("Reality verification #{$run->id} completed: {$run->items_scored} scored, {$run->certified_count} certified, {$run->cemetery_count} cemetery entries.");

        return self::SUCCESS;
    }
}
