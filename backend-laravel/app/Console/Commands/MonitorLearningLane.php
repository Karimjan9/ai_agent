<?php

namespace App\Console\Commands;

use App\Models\LabLearningLaneDispatch;
use App\Models\LabLearningLanePair;
use App\Services\LearningLaneService;
use Illuminate\Console\Command;

/** Read-only operational monitor for the research-only learning lane. */
class MonitorLearningLane extends Command
{
    protected $signature = 'trading:monitor-learning-lane {symbol?} {--timeframe=H1} {--family=} {--json}';

    protected $description = 'Monitor paired learning observations, provisional skills and research replay status';

    public function handle(LearningLaneService $learning): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $family = (string) $this->option('family') ?: null;
        $status = $learning->status($symbol, $timeframe, $family);
        $dispatches = LabLearningLaneDispatch::query()
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->when($family, fn ($query) => $query->where('strategy_family', $family))
            ->get();
        $pairs = LabLearningLanePair::query()
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->when($family, fn ($query) => $query->where('strategy_family', $family))
            ->get();
        $payload = [
            ...$status,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'dispatch_statuses' => $dispatches->groupBy('status')->map->count()->all(),
            'pair_statuses' => $pairs->groupBy('status')->map->count()->all(),
            'top_frontier' => $pairs
                ->whereIn('status', ['screen_paired', 'provisional'])
                ->sortByDesc(fn ($pair): array => [
                    (bool) data_get($pair->target_delta, 'improved', false) ? 1 : 0,
                    abs((float) data_get($pair->target_delta, 'delta', 0)),
                ])
                ->take(8)
                ->map(fn ($pair): array => [
                    'pair_id' => $pair->id,
                    'agent_id' => $pair->candidate_agent_id,
                    'target' => $pair->target,
                    'role' => $pair->specialist_role,
                    'status' => $pair->status,
                    'target_delta' => $pair->target_delta,
                    'baseline_source' => $pair->baseline_source,
                    'promotion_evidence' => false,
                ])->values()->all(),
            'promotion_evidence' => false,
        ];
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Metric', 'Value'], collect($payload)->except(['top_frontier', 'dispatch_statuses', 'pair_statuses', 'symbol', 'timeframe'])->map(
            fn ($value, $key): array => [$key, is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        )->values()->all());
        $this->line('Dispatches: '.json_encode($payload['dispatch_statuses'], JSON_UNESCAPED_UNICODE));
        $this->line('Pairs: '.json_encode($payload['pair_statuses'], JSON_UNESCAPED_UNICODE));
        $this->line('Top frontier: '.json_encode($payload['top_frontier'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
