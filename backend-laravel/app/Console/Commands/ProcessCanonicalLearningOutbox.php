<?php

namespace App\Console\Commands;

use App\Models\CanonicalLearningOutbox;
use App\Services\CanonicalLearningOutboxService;
use Illuminate\Console\Command;

class ProcessCanonicalLearningOutbox extends Command
{
    protected $signature = 'trading:process-canonical-learning-outbox {--limit=100}';
    protected $description = 'Retry durable canonical learning settlements; completed replay is withheld until settlement succeeds';

    public function handle(CanonicalLearningOutboxService $outbox): int
    {
        $count = 0;
        CanonicalLearningOutbox::query()->whereIn('status', ['pending', 'retry_ready'])->oldest('id')
            ->limit(max(1, min(500, (int) $this->option('limit'))))->get()
            ->each(function (CanonicalLearningOutbox $row) use ($outbox, &$count): void {
                $outbox->process($row);
                $count++;
            });
        $this->info("{$count} canonical learning outbox item(s) processed.");
        return self::SUCCESS;
    }
}
