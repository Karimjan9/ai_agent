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
    protected $signature = 'trading:dispatch-lab {symbol?} {--timeframe=H1} {--force-generation}';

    protected $description = 'Dispatch pair-local incremental screening for each draft laboratory agent';

    public function handle(LabPopulationService $populations, LabDatasetExportService $datasets, MarketDataContinuityService $continuity): int
    {
        $populations->ensureLaboratories();
        $symbols = $this->argument('symbol') ? [strtoupper($this->argument('symbol'))] : ['XAUUSD', 'EURUSD', 'GBPUSD'];

        $timeframe = strtoupper((string) $this->option('timeframe'));
        foreach ($symbols as $symbol) {
            $lab = AiLaboratory::where('symbol', $symbol)->where('timeframe', $timeframe)->firstOrFail();
            $generation = $lab->generations()->with('agents')->latest('generation')->first();
            $replayActivation = $generation?->trigger_type === 'protocol_activation';
            if ((string) config('services.market_data.provider', 'csv') !== 'csv'
                && ! $replayActivation
                && ! $continuity->isReady((string) config('services.market_data.provider'), $symbol, $lab->timeframe)) {
                $this->warn("{$symbol}: feed healthy bo'lmaguncha lab dispatch bloklandi.");
                continue;
            }
            if ($replayActivation) {
                $this->info("{$symbol}: sealed historical replay dispatch; live-feed continuity paper trading uchun alohida gate bo'lib qoladi.");
            }
            if (! $generation || $this->option('force-generation')) {
                $generation = $populations->build($symbol, 'new_data', (bool) $this->option('force-generation'), $timeframe);
            }

            if (! $generation) {
                $this->warn("{$symbol}: new learning evidence is not available.");
                continue;
            }

            $datasets->export($symbol, $lab->timeframe);
            // A second scheduler/manual invocation may have waited for the
            // dataset lock. Re-read after export so it never queues the same
            // draft agents a second time.
            $generation = $generation->fresh(['agents']);
            $agentIds = $generation->agents->where('lifecycle_status', 'draft')->pluck('id');
            if ($agentIds->isEmpty()) {
                $this->info("{$symbol}: generation is already dispatched or evaluated.");
                continue;
            }
            $generation->agents()->whereIn('id', $agentIds)->update(['lifecycle_status' => 'queued']);
            $generation->update(['status' => 'screening']);
            $jobs = $agentIds->map(fn ($id) => new EvaluateLabAgentJob($id, $symbol, 'screen'))->all();

            $batch = Bus::batch($jobs)
                ->name("{$symbol} {$timeframe} Lab G{$generation->generation} screening")
                ->onConnection('database')
                ->onQueue('lab-'.strtolower($symbol))
                ->dispatch();

            $this->info("{$symbol}: {$batch->id}, ".count($jobs).' jobs dispatched.');
        }

        return self::SUCCESS;
    }
}
