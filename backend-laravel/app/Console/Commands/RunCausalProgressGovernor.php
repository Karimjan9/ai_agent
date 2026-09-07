<?php

namespace App\Console\Commands;

use App\Services\CausalProgressRatchetGovernorService;
use App\Services\DependencyAwareEdgeGenesisFoundryService;
use Illuminate\Console\Command;

class RunCausalProgressGovernor extends Command
{
    protected $signature = 'trading:causal-progress-governor {symbol=XAUUSD} {--timeframe=H1} {--apply : Permit settlement or replay mutations; without it every action is a read-only plan} {--persist-telemetry : Persist only the allocation/debt diagnostic snapshot} {--resume : Inspect or, with --apply, resume existing immutable Edge trials} {--consolidate : Inspect or, with --apply, run idempotent settlement/reconciliation only; never create a cohort} {--json}';
    protected $description = 'Allocate exploration/consolidation compute from durable causal progress and debt evidence';

    public function handle(CausalProgressRatchetGovernorService $governor, DependencyAwareEdgeGenesisFoundryService $edge): int
    {
        $symbol = strtoupper((string) $this->argument('symbol')); $timeframe = strtoupper((string) $this->option('timeframe'));
        $apply = (bool) $this->option('apply');
        $persistTelemetry = (bool) $this->option('persist-telemetry');
        $result = ['protocol' => CausalProgressRatchetGovernorService::PROTOCOL,
            'mode' => $apply ? 'apply' : 'read_only', 'telemetry_persisted' => $persistTelemetry,
            'allocation' => $governor->allocate($symbol, $timeframe, $persistTelemetry), 'resume_plan' => $governor->resumePlan($symbol, $timeframe),
            'kpis' => $governor->kpis($symbol, $timeframe), 'promotion_evidence' => false];
        if ($this->option('resume')) $result['existing_edge_trial_resume'] = $edge->resumePendingTrials($symbol, $timeframe, $apply);
        if ($this->option('consolidate')) $result['consolidation'] = $governor->consolidationPlan($symbol, $timeframe, $apply);
        if ($this->option('json')) $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        else $this->info('Causal Progress Governor: '.json_encode(['mode' => data_get($result, 'allocation.mode'), 'debt' => data_get($result, 'allocation.debt.score'), 'resume' => data_get($result, 'resume_plan.status')], JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
