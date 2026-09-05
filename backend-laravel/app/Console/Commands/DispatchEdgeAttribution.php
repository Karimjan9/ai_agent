<?php

namespace App\Console\Commands;

use App\Models\LabAgent;
use App\Services\DependencyAwareEdgeGenesisFoundryService;
use Illuminate\Console\Command;

class DispatchEdgeAttribution extends Command
{
    protected $signature = 'trading:dispatch-edge-attribution {agent} {--dry-run}';
    protected $description = 'Materialize the five-arm post-edge component attribution cohort.';

    public function handle(DependencyAwareEdgeGenesisFoundryService $foundry): int
    {
        $agent = LabAgent::query()->with(['modelVersion', 'generation.laboratory'])->find((int) $this->argument('agent'));
        if (! $agent) { $this->error('Edge Genesis agent not found.'); return self::FAILURE; }
        if ($this->option('dry-run')) { $this->info('Would require EDGE_ATTRIBUTION phase and create five research-only attribution arms.'); return self::SUCCESS; }
        $result = $foundry->materializeAttribution($agent);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return ($result['status'] ?? null) === 'queued' ? self::SUCCESS : self::FAILURE;
    }
}
