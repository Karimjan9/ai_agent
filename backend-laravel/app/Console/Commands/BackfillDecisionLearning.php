<?php

namespace App\Console\Commands;

use App\Models\ModelMarketPerformance;
use App\Services\DecisionLearningService;
use Illuminate\Console\Command;

class BackfillDecisionLearning extends Command
{
    protected $signature = 'trading:backfill-decision-learning {--symbol=}';
    protected $description = 'Derive entry/exit/architecture lessons from completed historical laboratory evidence';

    public function handle(DecisionLearningService $learning): int
    {
        $query = ModelMarketPerformance::query()->with('modelVersion')->where('evidence_status', 'valid');
        if ($symbol = $this->option('symbol')) $query->where('symbol', strtoupper((string) $symbol));
        $processed = 0;
        $query->orderBy('id')->chunkById(100, function ($performances) use ($learning, &$processed): void {
            foreach ($performances as $performance) {
                if (! is_array($performance->metrics) || $performance->metrics === []) continue;
                $learning->learn($performance, $performance->metrics);
                $processed++;
            }
        });
        $this->info("Decision-learning evidence processed for {$processed} performances.");
        return self::SUCCESS;
    }
}
