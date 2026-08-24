<?php

namespace App\Console\Commands\Trading;

use App\Services\LabLifecycleOrchestrator;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

class RunLifecycleCycle extends Command
{
    protected $name = 'trading:run-lifecycle-cycle';
    protected $description = 'Run one safe, idempotent, resumable NeuroTrader agent lifecycle cycle for a symbol/timeframe.';

    protected function getOptions(): array
    {
        return [
            ['symbol', null, InputOption::VALUE_OPTIONAL, 'Trading symbol, e.g. XAUUSD', null],
            ['timeframe', null, InputOption::VALUE_OPTIONAL, 'Timeframe, e.g. H1', 'H1'],
            ['cycle-id', null, InputOption::VALUE_OPTIONAL, 'Explicit cycle ID for resumption', null],
            ['start-cycle', null, InputOption::VALUE_NONE, 'Explicitly start one successor cycle despite a learning pause; promotion gates remain active'],
            ['json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON'],
        ];
    }

    public function handle(LabLifecycleOrchestrator $orchestrator): int
    {
        $symbol = $this->option('symbol') ?? config('services.lighthouse.symbol', 'XAUUSD');
        $timeframe = (string) $this->option('timeframe');
        $cycleId = $this->option('cycle-id') ? (string) $this->option('cycle-id') : null;

        if (! (bool) config('services.lifecycle_orchestrator.enabled', true)) {
            $this->warn('Lifecycle orchestrator is disabled (NEUROTRADER_LIFECYCLE_ENABLED=false).');
            return 1;
        }

        $result = $orchestrator->run((string) $symbol, $timeframe, $cycleId, (bool) $this->option('start-cycle'));

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
            return (int) ($result['status'] === 'blocked' ? 1 : 0);
        }

        $this->info(sprintf('[%s] %s:%s → status=%s stage=%s',
            $result['cycle_id'] ?? 'cycle',
            strtoupper($result['symbol']),
            $result['timeframe'],
            $result['status'],
            $result['stage'] ?? '-',
        ));
        $this->line((string) ($result['summary'] ?? ''));

        return $result['status'] === 'blocked' ? 1 : 0;
    }
}
