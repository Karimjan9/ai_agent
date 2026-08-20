<?php

namespace App\Console\Commands;

use App\Services\DualTrackEvidenceWorkerService;
use Illuminate\Console\Command;

class ProcessDualTrackEvidence extends Command
{
    protected $signature = 'trading:process-dual-track-evidence {--limit=10}';
    protected $description = 'Process red-team, Council ablation, forward-proof and prioritized memory work items.';

    public function handle(DualTrackEvidenceWorkerService $worker): int
    {
        $result = $worker->process(max(1, (int) $this->option('limit')));
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
