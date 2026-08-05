<?php

namespace App\Console\Commands;

use App\Services\LearningProtocolSafetyService;
use Illuminate\Console\Command;

class ResumeLabGenerationCreation extends Command
{
    protected $signature = 'trading:resume-lab-generation {--reason= : Auditable reason for resuming population creation}';
    protected $description = 'Resume bounded lab-generation creation after an execution-protocol freeze has been verified';

    public function handle(LearningProtocolSafetyService $safety): int
    {
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('Resume reason is required.');
            return self::INVALID;
        }

        $safety->resumeGenerationCreation($reason);
        $this->info('Lab-generation creation resumed; promotion gates remain unchanged.');
        return self::SUCCESS;
    }
}
