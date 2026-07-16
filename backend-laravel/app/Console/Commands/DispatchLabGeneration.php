<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Services\LabDatasetExportService;
use App\Services\LabPopulationService;
use App\Services\MarketData\MarketDataContinuityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class DispatchLabGeneration extends Command
{
    protected $signature = 'trading:dispatch-lab {symbol?} {--force-generation}';

    protected $description = 'Dispatch pair-local incremental screening for each draft laboratory agent';

    public function handle(LabPopulationService $populations, LabDatasetExportService $datasets, MarketDataContinuityService $continuity): int
    {
        $populations->ensureLaboratories();
        $symbols = $this->argument('symbol') ? [strtoupper($this->argument('symbol'))] : ['XAUUSD', 'EURUSD', 'GBPUSD'];

        foreach ($symbols as $symbol) {
            $lab = AiLaboratory::where('symbol', $symbol)->firstOrFail();
            if ((string) config('services.market_data.provider', 'csv') !== 'csv'
                && ! $continuity->isReady((string) config('services.market_data.provider'), $symbol, $lab->timeframe)) {
                $this->warn("{$symbol}: feed healthy bo'lmaguncha lab dispatch bloklandi.");
                continue;
            }
            $generation = $lab->generations()->with('agents')->latest('generation')->first();

            if (! $generation || $this->option('force-generation')) {
                $generation = $populations->build($symbol, 'new_data', (bool) $this->option('force-generation'));
            }

            if (! $generation) {
                $this->warn("{$symbol}: new learning evidence is not available.");
                continue;
            }

            $jobs = $generation->agents
                ->where('lifecycle_status', 'draft')
                ->map(fn ($agent) => new EvaluateLabAgentJob($agent->id, $symbol, 'screen'))
                ->all();

            if (! $jobs) {
                $this->info("{$symbol}: generation is already dispatched or evaluated.");
                continue;
            }

            $datasets->export($symbol, $lab->timeframe);
            $generation->agents()->where('lifecycle_status', 'draft')->update(['lifecycle_status' => 'queued']);
            $generation->update(['status' => 'screening']);

            $batch = Bus::batch($jobs)
                ->name("{$symbol} Lab G{$generation->generation} screening")
                ->onConnection('database')
                ->onQueue('lab-'.strtolower($symbol))
                ->dispatch();

            $this->info("{$symbol}: {$batch->id}, ".count($jobs).' jobs dispatched.');
        }

        return self::SUCCESS;
    }
}
