<?php

namespace App\Console\Commands;

use App\Services\LabHistoricalLearningService;
use Illuminate\Console\Command;

class LearnFromLabHistory extends Command
{
    protected $signature = 'trading:lab-learn-from-history {symbol?} {--timeframe=H1} {--json}';
    protected $description = 'Derive append-only evolution insights from immutable lab runs, gates and candle decisions';

    public function handle(LabHistoricalLearningService $learning): int
    {
        $symbols = $this->argument('symbol') ? [strtoupper((string) $this->argument('symbol'))] : ['XAUUSD', 'EURUSD', 'GBPUSD'];
        $rows = [];
        foreach ($symbols as $symbol) {
            $insights = $learning->refreshForLab($symbol, (string) $this->option('timeframe'));
            foreach ($insights as $insight) {
                $rows[] = [
                    'symbol' => $insight->symbol,
                    'timeframe' => $insight->timeframe,
                    'family' => $insight->strategy_family,
                    'target' => data_get($insight->recommended_mutations, 'primary_target'),
                    'quality' => $insight->evidence_quality,
                    'causal_prior_allowed' => (bool) $insight->causal_prior_allowed,
                    'confidence' => (float) $insight->confidence,
                    'insight_id' => $insight->insight_id,
                ];
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode(['protocol' => LabHistoricalLearningService::PROTOCOL, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($rows === []) {
            $this->warn('Immutable history asosida insight uchun agent evidence topilmadi.');
        } else {
            $this->table(['Symbol', 'TF', 'Family', 'Target', 'Quality', 'Causal', 'Confidence', 'Insight'], array_map(fn (array $row): array => [
                $row['symbol'], $row['timeframe'], $row['family'], $row['target'] ?: 'none',
                $row['quality'], $row['causal_prior_allowed'] ? 'yes' : 'no', $row['confidence'], $row['insight_id'],
            ], $rows));
        }
        return self::SUCCESS;
    }
}
