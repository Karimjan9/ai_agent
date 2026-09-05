<?php

namespace App\Console\Commands;

use App\Services\CausalProgressRatchetGovernorService;
use App\Services\DependencyAwareEdgeGenesisFoundryService;
use Illuminate\Console\Command;

class RunCausalProgressGovernor extends Command
{
    protected $signature = 'trading:causal-progress-governor {symbol=XAUUSD} {--timeframe=H1} {--resume : Resume only existing immutable Edge trials} {--consolidate : Run idempotent settlement/reconciliation only; never create a cohort} {--json}';
    protected $description = 'Allocate exploration/consolidation compute from durable causal progress and debt evidence';

    public function handle(CausalProgressRatchetGovernorService $governor, DependencyAwareEdgeGenesisFoundryService $edge): int
    {
        $symbol = strtoupper((string) $this->argument('symbol')); $timeframe = strtoupper((string) $this->option('timeframe'));
        $result = ['protocol' => CausalProgressRatchetGovernorService::PROTOCOL,
            'allocation' => $governor->allocate($symbol, $timeframe, true), 'resume_plan' => $governor->resumePlan($symbol, $timeframe),
            'kpis' => $governor->kpis($symbol, $timeframe), 'promotion_evidence' => false];
        if ($this->option('resume')) $result['existing_edge_trial_resume'] = $edge->resumePendingTrials($symbol, $timeframe, true);
        if ($this->option('consolidate')) $result['consolidation'] = $governor->consolidationPlan($symbol, $timeframe, true);
        if ($this->option('json')) $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        else $this->info('Causal Progress Governor: '.json_encode(['mode' => data_get($result, 'allocation.mode'), 'debt' => data_get($result, 'allocation.debt.score'), 'resume' => data_get($result, 'resume_plan.status')], JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
