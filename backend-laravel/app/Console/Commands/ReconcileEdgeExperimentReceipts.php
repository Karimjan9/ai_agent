<?php

namespace App\Console\Commands;

use App\Services\EdgeExperimentSettlementReconcilerService;
use Illuminate\Console\Command;

class ReconcileEdgeExperimentReceipts extends Command
{
    protected $signature = 'trading:reconcile-edge-experiment-receipts {symbol=XAUUSD} {--timeframe=H1} {--apply : Persist only immutable terminal Edge receipts} {--limit=25} {--json}';
    protected $description = 'Project already-terminal Edge evidence into receipts; never dispatches a replay or grants authority';

    public function handle(EdgeExperimentSettlementReconcilerService $reconciler): int
    {
        $result = $reconciler->reconcile(
            strtoupper((string) $this->argument('symbol')),
            strtoupper((string) $this->option('timeframe')),
            (bool) $this->option('apply'),
            (int) $this->option('limit'),
        );
        if ($this->option('json')) $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        else $this->info('Edge experiment receipts: '.($result['status'] ?? 'unknown'));

        return self::SUCCESS;
    }
}
