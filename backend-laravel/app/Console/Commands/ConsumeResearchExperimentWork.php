<?php

namespace App\Console\Commands;

use App\Services\ResearchExperimentWorkConsumerService;
use Illuminate\Console\Command;

class ConsumeResearchExperimentWork extends Command
{
    protected $signature = 'trading:consume-research-work
        {workItem : Leased research work item id}
        {--lease-token= : Fenced lease token}
        {--fence=0 : Fenced lease version}
        {--json}';

    protected $description = 'Execute exactly one continuation leased by the Research Loop Arbiter';

    public function handle(ResearchExperimentWorkConsumerService $consumer): int
    {
        $result = $consumer->execute(
            (int) $this->argument('workItem'),
            (string) $this->option('lease-token'),
            (int) $this->option('fence'),
        );
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info((string) ($result['status'] ?? 'unknown').': '.(string) ($result['reason'] ?? 'research continuation processed'));
        }

        // A fail-closed dependency is a healthy state-machine outcome. Real
        // exceptions still fail before this return.
        return self::SUCCESS;
    }
}
