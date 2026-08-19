<?php

namespace App\Console\Commands;

use App\Models\LabFailureDojoRun;
use App\Services\StrategicResearchDirectorService;
use Illuminate\Console\Command;

/** Materializes bounded research plans from the failure Dojo. */
class CompileStrategicResearchPlans extends Command
{
    protected $signature = 'trading:compile-strategic-research-plans
        {symbol?}
        {--timeframe=H1}
        {--limit=100}
        {--status=pending}
        {--after-id=0}
        {--dry-run}';

    protected $description = 'Compile strategic research actions, prediction and counterfactual contracts';

    public function handle(StrategicResearchDirectorService $director): int
    {
        $status = strtolower((string) $this->option('status'));
        $afterId = max(0, (int) $this->option('after-id'));
        $runs = LabFailureDojoRun::query()
            ->where('timeframe', strtoupper((string) $this->option('timeframe')))
            ->when($this->argument('symbol'), fn ($query, $symbol) => $query->where('symbol', strtoupper((string) $symbol)))
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            // The scheduled lane is an append-only compiler. Once a pending
            // run has a director plan it is not repeatedly rewritten; new
            // Dojo rows will be picked up on the next tick.
            ->when($status === 'pending', fn ($query) => $query->whereNull('evidence->strategic_research_director'))
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(max(1, min(500, (int) $this->option('limit'))))
            ->get();

        $compiled = 0;
        $top = [];
        foreach ($runs as $run) {
            $plan = $director->planFor($run);
            $top[] = [
                'run_id' => $run->id,
                'action' => data_get($plan, 'decision_action'),
                'value' => data_get($plan, 'experiment_value.score'),
                'status' => data_get($plan, 'status'),
            ];
            if (! $this->option('dry-run')) $director->materialize($run);
            $compiled++;
        }

        $payload = [
            'protocol' => StrategicResearchDirectorService::PROTOCOL,
            'dry_run' => (bool) $this->option('dry-run'),
            'inspected' => $runs->count(),
            'compiled' => $compiled,
            'after_id' => $afterId,
            'next_after_id' => $runs->last()?->id,
            'has_more' => $runs->count() === max(1, min(500, (int) $this->option('limit'))),
            'top_actions' => collect($top)->sortByDesc('value')->take(10)->values()->all(),
            'promotion_evidence' => false,
        ];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
