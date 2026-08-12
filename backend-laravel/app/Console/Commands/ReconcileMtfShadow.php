<?php

namespace App\Console\Commands;

use App\Services\PaperMtfShadowReconciliationService;
use Illuminate\Console\Command;

class ReconcileMtfShadow extends Command
{
    protected $signature = 'trading:reconcile-mtf-shadow
        {--symbol=XAUUSD : Shadow symbol}
        {--limit=20 : Maximum unsettled shadow observations}
        {--json : Print JSON instead of a table}';

    protected $description = 'Settle MTF shadow observations under the canonical paper execution contract';

    public function handle(PaperMtfShadowReconciliationService $service): int
    {
        $result = $service->reconcile((string) $this->option('symbol'), max(1, (int) $this->option('limit')));
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Checked', 'Closed', 'Pending', 'Skipped', 'Errors'], [[
                $result['checked'], $result['closed'], $result['pending'], $result['skipped'], $result['errors'],
            ]]);
            $this->comment('Shadow outcomes are research-only and never promotion evidence.');
        }

        return self::SUCCESS;
    }
}
