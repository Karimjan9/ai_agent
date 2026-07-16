<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Services\LabDatasetExportService;
use App\Services\MarketData\MarketDataContinuityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class DispatchFullLabValidation extends Command
{
    protected $signature = 'trading:dispatch-full-validation {symbol?} {--top=3}';

    protected $description = 'Select the strongest screened agents from every pair and serialize full walk-forward validation';

    public function handle(LabDatasetExportService $datasets, MarketDataContinuityService $continuity): int
    {
        $symbols = $this->argument('symbol') ? [strtoupper($this->argument('symbol'))] : ['XAUUSD', 'EURUSD', 'GBPUSD'];
        $top = max(1, min(3, (int) $this->option('top')));
        $rounds = [];

        foreach ($symbols as $symbol) {
            $lab = AiLaboratory::where('symbol', $symbol)->first();
            $generation = $lab?->generations()->with('agents.modelVersion')->latest('generation')->first();
            if (! $generation) {
                $this->warn("{$symbol}: generation topilmadi.");
                continue;
            }
            if ((string) config('services.market_data.provider', 'csv') !== 'csv'
                && ! $continuity->isReady((string) config('services.market_data.provider'), $symbol, $lab->timeframe)) {
                $this->warn("{$symbol}: feed healthy bo'lmaguncha full validation bloklandi.");
                continue;
            }
            if ($generation->status !== 'screened') {
                $this->info("{$symbol}: screening hali yakunlanmagan.");
                continue;
            }

            $agents = $generation->agents
                ->where('lifecycle_status', 'screened')
                ->sortByDesc(fn ($agent) => [(float) $agent->forward_score, (float) $agent->profit_factor, -(float) $agent->max_drawdown])
                ->take($top)
                ->values();
            if ($agents->isEmpty()) {
                $this->info("{$symbol}: full validation uchun screened kandidat yo'q.");
                continue;
            }

            $datasets->export($symbol, $lab->timeframe);
            foreach ($agents as $rank => $agent) {
                $agent->update(['lifecycle_status' => 'full_queued', 'decision_reason' => 'Top screening candidate #'.($rank + 1).'; queued for serialized full validation.']);
                $rounds[$rank][] = new EvaluateLabAgentJob($agent->id, $symbol, 'full');
            }
            $generation->update(['status' => 'full_validation']);
        }

        // Interleave pair ranks (XAU #1, EUR #1, GBP #1, then #2...) so one
        // market cannot monopolize the single expensive validation worker.
        $jobs = collect($rounds)->sortKeys()->flatMap(fn ($round) => $round)->all();
        if (! $jobs) return self::SUCCESS;

        $batch = Bus::batch($jobs)->name('Global full validation')->onConnection('database')->onQueue('lab-full-validation')->dispatch();
        $this->info("Global full validation batch {$batch->id}: ".count($jobs).' candidates.');

        return self::SUCCESS;
    }
}
