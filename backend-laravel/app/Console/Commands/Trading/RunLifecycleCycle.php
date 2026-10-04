<?php

namespace App\Console\Commands\Trading;

use App\Services\LabLifecycleOrchestrator;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

class RunLifecycleCycle extends Command
{
    protected $name = 'trading:run-lifecycle-cycle';

    protected $description = 'Run one safe, idempotent lifecycle cycle for the governed XAUUSD multi-timeframe organism.';

    protected function getOptions(): array
    {
        return [
            ['symbol', null, InputOption::VALUE_OPTIONAL, 'Trading symbol, e.g. XAUUSD', null],
            ['timeframe', null, InputOption::VALUE_OPTIONAL, 'Internal laboratory storage key; XAUUSD is always routed to its organism anchor', null],
            ['cycle-id', null, InputOption::VALUE_OPTIONAL, 'Explicit cycle ID for resumption', null],
            ['start-cycle', null, InputOption::VALUE_NONE, 'Explicitly start one successor cycle despite a learning pause; promotion gates remain active'],
            ['expected-generation-id', null, InputOption::VALUE_OPTIONAL, 'Frozen generation authorized by the research-loop arbiter', null],
            ['settle-only', null, InputOption::VALUE_NONE, 'Drain only the frozen generation; never admit a successor in this invocation'],
            ['learning-confirmation', null, InputOption::VALUE_NONE, 'Open the exact causal learning-confirmation generation selected by the research-loop arbiter'],
            ['prospective-source-pair-id', null, InputOption::VALUE_OPTIONAL, 'Frozen screen pair selected for prospective repair', null],
            ['prospective-source-hash', null, InputOption::VALUE_OPTIONAL, 'Frozen prospective repair source hash', null],
            ['json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON'],
        ];
    }

    public function handle(LabLifecycleOrchestrator $orchestrator): int
    {
        $symbol = $this->option('symbol') ?? config('services.lighthouse.symbol', 'XAUUSD');
        $timeframe = (string) ($this->option('timeframe') ?: config('services.xauusd_organism.laboratory_storage_timeframe', 'H1'));
        $cycleId = $this->option('cycle-id') ? (string) $this->option('cycle-id') : null;
        $expectedGenerationId = $this->option('expected-generation-id') !== null
            ? (int) $this->option('expected-generation-id')
            : null;
        $prospectiveExpectation = $this->option('prospective-source-pair-id') !== null
            || $this->option('prospective-source-hash') !== null
            ? ['source_pair_id' => (int) $this->option('prospective-source-pair-id'),
                'source_hash' => (string) $this->option('prospective-source-hash')]
            : null;

        if (! (bool) config('services.lifecycle_orchestrator.enabled', true)) {
            $this->warn('Lifecycle orchestrator is disabled (NEUROTRADER_LIFECYCLE_ENABLED=false).');

            return 1;
        }

        $result = $orchestrator->run(
            (string) $symbol,
            $timeframe,
            $cycleId,
            (bool) $this->option('start-cycle'),
            $expectedGenerationId,
            (bool) $this->option('settle-only'),
            (bool) $this->option('learning-confirmation'),
            $prospectiveExpectation,
        );

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));

            return (int) ($result['status'] === 'blocked' ? 1 : 0);
        }

        $resultSymbol = strtoupper((string) $result['symbol']);
        $scope = $resultSymbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))
            ? sprintf(
                '%s organism (storage=%s, execution=%s)',
                $resultSymbol,
                $result['timeframe'],
                strtoupper((string) config('services.xauusd_organism.execution_timeframe', 'M5')),
            )
            : $resultSymbol.':'.$result['timeframe'];
        $this->info(sprintf('[%s] %s -> status=%s stage=%s',
            $result['cycle_id'] ?? 'cycle',
            $scope,
            $result['status'],
            $result['stage'] ?? '-',
        ));
        $this->line((string) ($result['summary'] ?? ''));

        return $result['status'] === 'blocked' ? 1 : 0;
    }
}
