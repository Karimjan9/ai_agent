<?php

namespace App\Console\Commands;

use App\Services\ScreeningLearningOutboxService;
use Illuminate\Console\Command;

class ProcessScreeningLearningOutbox extends Command
{
    protected $signature = 'trading:process-screening-learning-outbox {--limit=100}';
    protected $description = 'Retry non-critical screening learning writes after facts and gate decisions are durable';
    public function handle(ScreeningLearningOutboxService $outbox): int
    {
        $this->info('Processed '.$outbox->process((int) $this->option('limit')).' screening-learning outbox item(s).');
        return self::SUCCESS;
    }
}
