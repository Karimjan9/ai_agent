<?php

namespace App\Console\Commands;

use App\Models\ModelMarketPerformance;
use App\Services\LabIncrementalEvaluationService;
use App\Services\LabPopulationService;
use Illuminate\Console\Command;

class EvaluateLabIncrementally extends Command
{
    protected $signature = 'trading:lab-incremental';

    protected $description = 'Run hourly champion health checks and immediately trigger relearning after sustained degradation';

    public function handle(LabIncrementalEvaluationService $evaluations, LabPopulationService $populations): int
    {
        try {
            $summary = $evaluations->evaluateChampions();
            if (($summary['deferred'] ?? false) === true) {
                $this->warn('Incremental checks deferred: '.($summary['deferred_reason'] ?? 'admission_gate').'.');
            }
            $this->info("Incremental checks: {$summary['checked']} checked, {$summary['degraded']} degraded, {$summary['skipped']} skipped.");

            // A deferred health check has no new degradation evidence. Do
            // not build a degradation generation from an older counter while
            // the serialized full-validation lane is waiting.
            if (($summary['deferred'] ?? false) === true) {
                return self::SUCCESS;
            }

            $symbols = ModelMarketPerformance::query()
                ->where('status', 'champion')
                ->where('consecutive_no_improvement', '>=', 3)
                ->pluck('symbol')
                ->unique();

            foreach ($symbols as $symbol) {
                $generation = $populations->build($symbol, 'degradation');
                if ($generation) {
                    $this->warn("{$symbol}: degradation triggered generation {$generation->generation}.");
                }
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
