<?php

namespace App\Console\Commands\Trading;

use App\Services\LabLifecycleOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Input\InputOption;

class MonitorLifecycleCycle extends Command
{
    protected $name = 'trading:monitor-lifecycle-cycle';
    protected $description = 'Read-only view of the current NeuroTrader lifecycle cycle, generation, agents, queue, and recovery state.';

    protected function getOptions(): array
    {
        return [
            ['symbol', null, InputOption::VALUE_OPTIONAL, 'Trading symbol, e.g. XAUUSD', null],
            ['timeframe', null, InputOption::VALUE_OPTIONAL, 'Timeframe, e.g. H1', 'H1'],
            ['json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON'],
            ['brief', null, InputOption::VALUE_NONE, 'Output one short operator decision'],
        ];
    }

    public function handle(LabLifecycleOrchestrator $orchestrator): int
    {
        $symbol = $this->option('symbol') ?? config('services.lighthouse.symbol', 'XAUUSD');
        $timeframe = (string) $this->option('timeframe');

        $status = $orchestrator->status((string) $symbol, $timeframe);

        if ($this->option('brief')) {
            $brief = (array) ($status['brief'] ?? []);
            $this->line(sprintf('[%s] %s/%s — %s — %s',
                $brief['code'] ?? 'UNKNOWN', strtoupper((string) $symbol), $timeframe,
                $brief['message'] ?? 'Holat mavjud emas.', $brief['action'] ?? 'inspect'));
            return 0;
        }

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return 0;
        }

        $this->info('NeuroTrader Lifecycle — '.strtoupper($symbol).'/'.$timeframe);
        $this->line('Saqla: '.Carbon::now('Asia/Tashkent')->toDateTimeString().' (Asia/Tashkent)');

        $brief = (array) ($status['brief'] ?? []);
        $this->line(sprintf("\nQaror: [%s] %s — %s",
            $brief['code'] ?? '-', $brief['message'] ?? '-', $brief['action'] ?? '-'));

        $this->line("\n## Generation");
        $this->line(sprintf('  Generation: G%s (id=%s, status=%s)',
            $status['generation'] ?? '-',
            $status['generation_id'] ?? '-',
            $status['generation_status'] ?? '-',
        ));

        $this->line("\n## Agents by lifecycle state");
        $counts = (array) ($status['agent_counts'] ?? []);
        if ($counts === []) {
            $this->line('  (no agents)');
        } else {
            ksort($counts);
            foreach ($counts as $state => $count) {
                $this->line(sprintf('  %-24s %d', (string) $state, (int) $count));
            }
        }

        $this->line("\n## Concurrency");
        $this->line(sprintf('  Cycle locked: %s', $status['locked'] ? 'yes — another cycle is running' : 'no'));

        $queue = (array) ($status['queue'] ?? []);
        $this->line("\n## Queue and recovery");
        $this->line(sprintf('  Queue total: %s', $queue['total'] ?? 'unknown'));
        $this->line('  Recovery: '.json_encode($status['recovery'] ?? [], JSON_UNESCAPED_SLASHES));
        $gate = (array) ($status['learning_gate'] ?? []);
        $this->line(sprintf('  Next action: %s (actionable dojo: %s)', $status['next_action'] ?? ($gate['next_action'] ?? '-'), $gate['actionable_pending_dojo'] ?? 0));
        $population = (array) ($status['population_contract'] ?? []);
        $this->line(sprintf('  Population: latest %s/%s agents; complete=%s',
            $population['latest_actual'] ?? '-', $population['latest_planned'] ?? ($population['expected_per_normal_generation'] ?? 20),
            ($population['latest_complete'] ?? false) ? 'yes' : 'no'));
        $cohort = (array) ($status['cohort_diversity'] ?? []);
        $this->line(sprintf('  Cohort diversity: %s (unique=%s, duplicates=%s)',
            $cohort['variation_status'] ?? '-', $cohort['unique_cohorts'] ?? '-', $cohort['duplicate_cohort_count'] ?? '-'));
        $successor = (array) ($status['successor_request'] ?? []);
        if ($successor !== []) {
            $this->line(sprintf('  Successor request: %s (attempts=%d, last block=%s)',
                $successor['status'] ?? '-',
                (int) ($successor['creation_attempts'] ?? 0),
                $successor['last_block_reason'] ?? '-',
            ));
        }

        $checkpoint = (array) ($status['checkpoint'] ?? []);
        $this->line("\n## Checkpoint and alerts");
        $this->line(sprintf('  Last checkpoint: %s / %s / %s', $checkpoint['cycle_id'] ?? '-', $checkpoint['status'] ?? '-', $checkpoint['stage'] ?? '-'));
        $errors = (array) ($status['errors_today'] ?? []);
        $this->line(sprintf('  Lifecycle errors today: %d', (int) ($errors['count'] ?? 0)));
        if (($errors['latest']['safe_message'] ?? null) !== null) $this->line('  Latest safe error: '.$errors['latest']['safe_message']);

        return 0;
    }
}
