<?php

namespace App\Console\Commands;

use App\Services\AutonomousModeService;
use App\Services\InstrumentInvocationLedgerService;
use Illuminate\Console\Command;

/** SQL projection only: no new replay, generation, or independent-window authority. */
class ReconcileInstrumentPairs extends Command
{
    protected $signature = 'trading:reconcile-instrument-pairs {symbol=XAUUSD} {--timeframe=H1} {--pair-id=} {--limit=1} {--dry-run} {--autonomous} {--json}';

    protected $description = 'Close pending instrument projections against their exact sealed control without rewriting settled receipts';

    public function handle(InstrumentInvocationLedgerService $ledger, AutonomousModeService $autonomy): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        if ($this->option('autonomous') && ! $autonomy->enabled($symbol, $timeframe)) {
            $this->line(json_encode(['status' => 'autonomous_mode_stopped', 'promotion_evidence' => false]));
            return self::SUCCESS;
        }
        $pairId = (int) $this->option('pair-id');
        $plan = $ledger->pendingResearchPairs($symbol, $timeframe, (int) $this->option('limit'), $pairId ?: null);
        $results = $this->option('dry-run') ? [] : array_map(fn (array $row): array => $ledger->reconcileResearchPair($row['pair_id']), $plan);
        $this->line(json_encode(['protocol' => 'instrument_pending_pair_reconciliation_v1',
            'status' => $this->option('dry-run') ? 'dry_run' : 'completed', 'plan' => $plan,
            'results' => $results, 'promotion_evidence' => false], JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
