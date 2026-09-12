<?php

namespace App\Console\Commands;

use App\Jobs\RunScheduledArtisanCommandJob;
use App\Models\MtfAblationRun;
use App\Services\AutonomousModeService;
use App\Services\MtfResearchCohortService;
use App\Services\MtfResearchSnapshotService;
use App\Services\MtfStrategyResearchReportService;
use App\Services\MtfStrategyResearchService;
use Illuminate\Console\Command;

/** Admit at most one bounded MTF research action for the current cohort. */
class DispatchMtfResearchCycle extends Command
{
    protected $signature = 'trading:dispatch-mtf-research-cycle
        {--symbol=XAUUSD : Single-organism symbol}
        {--limit=4 : Maximum hypotheses in the next bounded batch}
        {--json : Print the decision as JSON}';

    protected $description = 'Autonomously advance one exact-control MTF research step without promotion authority';

    public function handle(
        AutonomousModeService $autonomy,
        MtfResearchCohortService $cohorts,
        MtfResearchSnapshotService $snapshots,
        MtfStrategyResearchReportService $reports,
        MtfStrategyResearchService $research,
    ): int {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', (string) $this->option('symbol')));
        if (! $autonomy->enabled($symbol, 'H1')) {
            return $this->decision(['status' => 'deferred', 'reason_code' => 'AUTONOMOUS_MODE_STOPPED']);
        }

        $cohort = $cohorts->current($symbol);
        if (($cohort['status'] ?? null) !== 'ready') {
            return $this->decision([
                'status' => 'deferred',
                'reason_code' => $cohort['reason_code'] ?? 'CURRENT_COHORT_UNAVAILABLE',
            ]);
        }
        if (! (bool) data_get($cohort, 'live_feed.ready', false)) {
            return $this->decision([
                'status' => 'deferred',
                'reason_code' => 'MTF_LIVE_FEED_NOT_READY',
                'live_feed' => $cohort['live_feed'],
            ]);
        }

        $control = MtfAblationRun::query()
            ->where('model_market_performance_id', (int) $cohort['candidate_id'])
            ->where('symbol', $symbol)
            ->where('data_hash', (string) $cohort['data_hash'])
            ->where('execution_hash', (string) $cohort['execution_hash'])
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();
        if (! $control || ! $snapshots->load($control)) {
            RunScheduledArtisanCommandJob::dispatch('trading:mtf-ablation', [
                '--symbol' => $symbol,
                '--candidate' => (int) $cohort['candidate_id'],
                '--json' => true,
            ], 'scheduler-research');

            return $this->decision([
                'status' => 'dispatched',
                'action' => 'seal_exact_frozen_control',
                'reason_code' => $control ? 'CONTROL_SNAPSHOT_INTEGRITY_FAILED' : 'CURRENT_COHORT_CONTROL_MISSING',
                'candidate_id' => (int) $cohort['candidate_id'],
                'data_hash' => (string) $cohort['data_hash'],
            ]);
        }

        $report = $reports->report(
            $symbol,
            720,
            (string) $cohort['data_hash'],
            (int) $cohort['candidate_id'],
        );
        $observations = collect((array) ($report['runs'] ?? []))
            ->where('data_hash', (string) $cohort['data_hash'])
            ->values()
            ->all();
        $limit = min(12, max(1, (int) $this->option('limit')));
        $frontier = collect($research->selectFrontier(
            $observations,
            (array) ($report['family_budget'] ?? []),
            12,
            (string) $cohort['data_hash'],
        ));
        if (! (bool) data_get($cohort, 'volume_research_freshness.ready', false)) {
            $frontier = $frontier->filter(
                fn (array $item): bool => (string) ($item['volume_lane'] ?? 'none') === 'none',
            );
        }
        $frontier = $frontier
            ->sortByDesc(fn (array $item): int => $this->economicInformationPriority((string) ($item['target_gate'] ?? '')))
            ->take($limit)
            ->values();
        if ($frontier->isEmpty()) {
            return $this->decision([
                'status' => 'complete',
                'action' => 'none',
                'reason_code' => 'CURRENT_COHORT_BOUNDED_FRONTIER_EXHAUSTED',
                'candidate_id' => (int) $cohort['candidate_id'],
                'data_hash' => (string) $cohort['data_hash'],
            ]);
        }

        $hypotheses = $frontier->pluck('key')->implode(',');
        RunScheduledArtisanCommandJob::dispatch('trading:mtf-strategy-research', [
            '--symbol' => $symbol,
            '--candidate' => (int) $cohort['candidate_id'],
            '--control-run' => (int) $control->id,
            '--hypotheses' => $hypotheses,
            '--validate-forward' => true,
            '--json' => true,
        ], 'scheduler-research');

        return $this->decision([
            'status' => 'dispatched',
            'action' => 'run_economic_information_batch',
            'reason_code' => 'CURRENT_COHORT_CONTROL_VERIFIED',
            'candidate_id' => (int) $cohort['candidate_id'],
            'control_run_id' => (int) $control->id,
            'data_hash' => (string) $cohort['data_hash'],
            'hypotheses' => $frontier->map(fn (array $item): array => [
                'key' => $item['key'],
                'target_gate' => $item['target_gate'],
                'information_priority' => $this->economicInformationPriority((string) $item['target_gate']),
            ])->all(),
        ]);
    }

    private function economicInformationPriority(string $target): int
    {
        return match ($target) {
            'stress_drawdown' => 100,
            'transition_cost_and_drawdown', 'range_coverage_and_cost_survival', 'volume_cost_survival' => 95,
            'monthly_survival', 'trend_up_stability', 'trend_down_opportunity_recall' => 90,
            'forward_profit_factor', 'range_profit_factor' => 85,
            'regime_coverage_and_drawdown' => 80,
            'false_positive_control' => 75,
            default => 50,
        };
    }

    /** @param array<string,mixed> $payload */
    private function decision(array $payload): int
    {
        $payload = [
            'protocol' => 'mtf_autonomous_research_dispatch_v1',
            ...$payload,
            'promotion_evidence' => false,
        ];
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info((string) ($payload['reason_code'] ?? $payload['status']));
        }

        // WAIT/exhaustion are healthy state-machine outcomes.  A later tick
        // re-observes the cohort; only the dispatched child owns heavy work.
        return self::SUCCESS;
    }
}
