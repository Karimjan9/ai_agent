<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Services\LabDatasetExportService;
use App\Services\LabCandidateSelectionService;
use App\Services\MarketData\MarketDataContinuityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class DispatchFullLabValidation extends Command
{
    protected $signature = 'trading:dispatch-full-validation {symbol?} {--timeframe=H1}';

    protected $description = 'Select the strongest screened agents from every pair and serialize full walk-forward validation';

    public function handle(LabDatasetExportService $datasets, MarketDataContinuityService $continuity, LabCandidateSelectionService $selection): int
    {
        $symbols = $this->argument('symbol') ? [strtoupper($this->argument('symbol'))] : ['XAUUSD', 'EURUSD', 'GBPUSD'];
        $rounds = [];

        $timeframe = strtoupper((string) $this->option('timeframe'));
        foreach ($symbols as $symbol) {
            $lab = AiLaboratory::where('symbol', $symbol)->where('timeframe', $timeframe)->first();
            $generation = $lab?->generations()->with('agents.modelVersion')->latest('generation')->first();
            if (! $generation) {
                $this->warn("{$symbol}: generation topilmadi.");
                continue;
            }
            $replayActivation = $generation->trigger_type === 'protocol_activation';
            if ((string) config('services.market_data.provider', 'csv') !== 'csv'
                && ! $replayActivation
                && ! $continuity->isReady((string) config('services.market_data.provider'), $symbol, $lab->timeframe)) {
                $this->warn("{$symbol}: feed healthy bo'lmaguncha full validation bloklandi.");
                continue;
            }
            if ($generation->status !== 'screened') {
            $this->info("{$symbol} {$timeframe}: screening hali yakunlanmagan.");
                continue;
            }

            $datasets->export($symbol, $lab->timeframe);
            // Export is serialized, but scheduler and a manual command can
            // both be waiting for it. Reload candidate state afterwards so a
            // waiter cannot enqueue an already full_queued agent twice.
            $generation = $generation->fresh(['agents.modelVersion']);
            $screened = $generation->agents
                ->where('lifecycle_status', 'screened')
                ->values();
            $agents = $selection->select($screened);
            if ($agents->isEmpty()) {
                $this->info("{$symbol}: full validation uchun screened kandidat yo'q.");
                continue;
            }
            foreach ($agents as $rank => $agent) {
                $agent->update(['lifecycle_status' => 'full_queued', 'decision_reason' => 'Dynamic evidence-frontier candidate #'.($rank + 1).'; queued for serialized full validation.']);
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
