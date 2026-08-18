<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Services\LabBatchTerminalityService;
use Illuminate\Console\Command;

class ReconcileLabBatchTerminality extends Command
{
    protected $signature = 'trading:reconcile-lab-batches {symbol?} {--timeframe=H1} {--generation=} {--apply} {--json}';
    protected $description = 'Reconcile generation-scoped Redis/worker/job_batches terminality without changing strategy evidence';

    public function handle(LabBatchTerminalityService $terminality): int
    {
        $symbol = $this->argument('symbol') ? strtoupper((string) $this->argument('symbol')) : null;
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $labs = AiLaboratory::query()
            ->when($symbol, fn ($query) => $query->where('symbol', $symbol))
            ->where('timeframe', $timeframe)
            ->get();
        $rows = [];
        foreach ($labs as $lab) {
            $generation = $lab->generations()
                ->when($this->option('generation') !== null, fn ($query) => $query->where('generation', (int) $this->option('generation')))
                ->latest('generation')->first();
            if (! $generation) continue;
            $rows[] = [
                'symbol' => $lab->symbol,
                'timeframe' => $lab->timeframe,
                'generation' => $generation->generation,
                'result' => $terminality->reconcile($generation, (bool) $this->option('apply')),
            ];
        }
        if ($this->option('json')) {
            $this->line(json_encode([
                'protocol' => LabBatchTerminalityService::PROTOCOL,
                'apply' => (bool) $this->option('apply'),
                'rows' => $rows,
                'promotion_evidence' => false,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($rows as $row) {
                $final = data_get($row, 'result.finality.allowed') ? 'FINAL' : 'IN_PROGRESS';
                $this->line($row['symbol'].' '.$row['timeframe'].' G'.$row['generation'].' '.$final.'; reconciled='.count((array) data_get($row, 'result.reconciled_batch_ids', [])));
            }
        }

        return self::SUCCESS;
    }
}
