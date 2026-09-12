<?php

namespace App\Console\Commands;

use App\Services\AutonomousModeService;
use Illuminate\Console\Command;

class MonitorAutonomousMode extends Command
{
    protected $signature = 'ai:status
        {--symbol=XAUUSD : Governed organism symbol}
        {--timeframe=H1 : Compatibility input; XAUUSD is canonicalized to H1 storage}
        {--controller= : Read-only view override: strong or lightweight}
        {--json : Print machine-readable status}';

    protected $description = 'Read-only autonomous-mode status for a lightweight monitoring model';

    public function handle(AutonomousModeService $mode): int
    {
        try {
            $result = $mode->monitor(
                (string) $this->option('symbol'),
                (string) $this->option('timeframe'),
                filled($this->option('controller')) ? (string) $this->option('controller') : null,
            );
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf(
                '[%s][%s] %s/%s - %s; storage=%s; G%s %s/%s; queue=%s; scheduler=%s',
                strtoupper((string) $result['state']),
                strtoupper((string) $result['effective_controller_profile']),
                $result['symbol'],
                $result['timeframe'],
                $result['next_action'],
                $result['laboratory_storage_timeframe'],
                data_get($result, 'monitor.generation.generation', '-'),
                data_get($result, 'monitor.generation.actual_population', '-'),
                data_get($result, 'monitor.generation.planned_population', '-'),
                data_get($result, 'monitor.queue.total', 'unknown'),
                data_get($result, 'monitor.scheduler_healthy', false) ? 'healthy' : 'attention',
            ));
            $this->line(sprintf(
                'instrument-learning=%s; candidates=%s; invoked=%s; settled=%s; verified=%s',
                data_get($result, 'monitor.instrument_learning.status', 'unknown'),
                data_get($result, 'monitor.instrument_learning.block_1_candidate_inventory.trading_instruments', 0),
                data_get($result, 'monitor.instrument_learning.agent_runtime_funnel.attested_invocations', 0),
                data_get($result, 'monitor.instrument_learning.agent_runtime_funnel.settled_causal_invocations', 0),
                data_get($result, 'monitor.instrument_learning.block_2_verified_value.confirmed_research_posteriors', 0),
            ));
        }

        return self::SUCCESS;
    }
}
