<?php

namespace App\Console\Commands;

use App\Models\TrainingSession;
use Illuminate\Console\Command;

class RepairTrainingMetrics extends Command
{
    protected $signature = 'training:repair-metrics {--dry-run}';

    protected $description = 'Backfill training-session risk metrics from the immutable strategy score records';

    public function handle(): int
    {
        $repaired = 0;

        TrainingSession::query()
            ->with('strategyScores')
            ->where('average_profit_factor', '<=', 0)
            ->orderBy('id')
            ->each(function (TrainingSession $session) use (&$repaired): void {
                $scores = $session->strategyScores;
                if ($scores->isEmpty()) {
                    return;
                }

                $metrics = [
                    'average_profit_factor' => round((float) $scores->avg('profit_factor'), 2),
                    'average_drawdown' => round((float) $scores->avg('max_drawdown_percent'), 2),
                    'average_stability_score' => (int) round((float) $scores->avg('stability_score')),
                ];

                if ($metrics['average_profit_factor'] <= 0) {
                    return;
                }

                $repaired++;
                $this->line("Session #{$session->id}: PF {$metrics['average_profit_factor']}");

                if (! $this->option('dry-run')) {
                    $session->update($metrics);
                }
            });

        $this->info("Training metric repair: {$repaired} session(s) ".($this->option('dry-run') ? 'would be repaired.' : 'repaired.'));

        return self::SUCCESS;
    }
}
