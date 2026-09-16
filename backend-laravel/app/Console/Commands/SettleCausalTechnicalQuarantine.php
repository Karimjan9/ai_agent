<?php

namespace App\Console\Commands;

use App\Models\AgentLearningCausalExperiment;
use App\Services\CausalLearningCohortService;
use Illuminate\Console\Command;

class SettleCausalTechnicalQuarantine extends Command
{
    protected $signature = 'trading:settle-causal-technical-quarantine
        {experiment : Exact causal experiment ID}
        {--json}';

    protected $description = 'Close an immutable causal triplet with a terminal technical arm without granting causal authority';

    public function handle(CausalLearningCohortService $cohorts): int
    {
        $experiment = AgentLearningCausalExperiment::query()->find((int) $this->argument('experiment'));
        $result = $experiment
            ? $cohorts->technicalTerminalDisposition($experiment, true)
            : [
                'eligible' => false,
                'status' => 'missing',
                'reason_codes' => ['CAUSAL_EXPERIMENT_MISSING'],
                'promotion_evidence' => false,
            ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info((string) ($result['status'] ?? 'unknown').': experiment '.(int) $this->argument('experiment'));
        }

        return self::SUCCESS;
    }
}
