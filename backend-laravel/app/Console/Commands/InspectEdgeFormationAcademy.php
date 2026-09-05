<?php

namespace App\Console\Commands;

use App\Services\XauusdEdgeFormationAcademyService;
use App\Services\DependencyAwareEdgeGenesisFoundryService;
use Illuminate\Console\Command;

class InspectEdgeFormationAcademy extends Command
{
    protected $signature = 'trading:edge-formation-academy {symbol=XAUUSD} {--timeframe=H1} {--reconcile : Backfill Academy projections from immutable settled Edge evidence only} {--json}';
    protected $description = 'Inspect the research-only XAUUSD Edge Formation Academy registry; never dispatches a replay';

    public function handle(XauusdEdgeFormationAcademyService $academy, DependencyAwareEdgeGenesisFoundryService $edge): int
    {
        $symbol = (string) $this->argument('symbol'); $timeframe = (string) $this->option('timeframe');
        $result = ['kpis' => $academy->kpis($symbol, $timeframe), 'promotion_evidence' => false];
        if ($this->option('reconcile')) $result['reconciliation'] = $edge->reconcileAcademyProjections($symbol, $timeframe, true);
        if ($this->option('json')) $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        else $this->info('Edge Formation Academy: '.json_encode($result, JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
