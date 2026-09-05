<?php

namespace App\Console\Commands;

use App\Services\CounterfactualCouncilCourtService;
use App\Services\LegacyControlDebtFirewallService;
use App\Services\MemoryOpportunityService;
use App\Services\SettlementWatermarkService;
use Illuminate\Console\Command;

class ReconcileEvolutionaryAuthority extends Command
{
    protected $signature = 'trading:reconcile-evolutionary-authority {symbol?} {--timeframe=H1} {--plan-council} {--json}';
    protected $description = 'Reconcile settlement taxonomy and legacy control debt; optionally create frozen council court cases.';

    public function handle(SettlementWatermarkService $watermarks, LegacyControlDebtFirewallService $legacy, MemoryOpportunityService $memory, CounterfactualCouncilCourtService $court): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $payload = ['symbol' => $symbol, 'timeframe' => $timeframe, 'settlements' => $watermarks->reconcile($symbol, $timeframe),
            'legacy_control_debt' => $legacy->reconcile($symbol, $timeframe), 'memory_opportunities' => $memory->metrics($symbol, $timeframe),
            'council_court' => $this->option('plan-council') ? $court->plan($symbol, $timeframe) : ['status' => 'not_requested'], 'promotion_evidence' => false];
        if ($this->option('json')) $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        else $this->info('Evolutionary authority reconciliation completed.');
        return self::SUCCESS;
    }
}
