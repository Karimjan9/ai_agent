<?php

namespace App\Console\Commands;

use App\Services\AcademyExperimentSettlementReconcilerService;
use Illuminate\Console\Command;

class ReconcileAcademyExperiments extends Command
{
    protected $signature = 'trading:reconcile-academy-experiments {symbol=XAUUSD} {--timeframe=H1} {--apply : Persist only eligible Academy settlement projections} {--limit=25} {--json}';
    protected $description = 'Reconcile already completed Academy evidence; never dispatches a replay or alters authority';

    public function handle(AcademyExperimentSettlementReconcilerService $reconciler): int
    {
        $result = $reconciler->reconcile(
            strtoupper((string) $this->argument('symbol')),
            strtoupper((string) $this->option('timeframe')),
            (bool) $this->option('apply'),
            (int) $this->option('limit'),
        );
        if ($this->option('json')) $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        else $this->info('Academy settlement: '.($result['status'] ?? 'unknown'));

        return self::SUCCESS;
    }
}
