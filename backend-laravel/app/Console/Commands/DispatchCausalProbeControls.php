<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Services\CandidateGateDecisionService;
use App\Services\CandidateHandoffService;
use App\Services\LabDatasetExportService;
use App\Services\LearningProtocolSafetyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/** Queue same-family controls for isolated probes so causal credit can be reconciled. */
class DispatchCausalProbeControls extends Command
{
    protected $signature = 'trading:dispatch-causal-probe-controls {symbol?}';

    protected $description = 'Dispatch bounded same-family controls for completed causal probes';

    public function handle(LabDatasetExportService $datasets, CandidateHandoffService $handoffs, CandidateGateDecisionService $decisions, LearningProtocolSafetyService $protocolSafety): int
    {
        if ($protocolSafety->generationCreationPaused()) {
            $this->info('Learning protocol paused: causal probe control replay deferred.');

            return self::SUCCESS;
        }
        $symbols = $this->argument('symbol') ? [strtoupper($this->argument('symbol'))] : ['XAUUSD', 'EURUSD', 'GBPUSD'];
        $jobs = [];
        foreach ($symbols as $symbol) {
            $lab = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', 'H1')->first();
            $generation = $lab?->generations()->whereIn('status', ['completed', 'full_validation'])->latest('generation')->first();
            if (! $generation) {
                continue;
            }
            if ((string) $lab->lifecycle_mode !== 'lighthouse') {
                $this->info("{$symbol} H1: shadow lab; causal control replay skipped.");

                continue;
            }
            $probes = LabAgent::query()->with(['modelVersion', 'mutationMemories'])
                ->where('lab_generation_id', $generation->id)->where('origin', 'causal_isolation')
                ->whereHas('mutationMemories', fn ($query) => $query->whereJsonContains('behavioral_effect->causal_credit->status', 'awaiting_paired_confirmation'))
                ->get();
            $controls = collect();
            foreach ($probes as $probe) {
                $control = LabAgent::query()->with('modelVersion')
                    ->where('lab_generation_id', $generation->id)->where('strategy_family', $probe->strategy_family)
                    ->where('origin', '!=', 'causal_isolation')->where('lifecycle_status', 'screened')
                    ->where('sample_count', '>=', 5)->where('profit_factor', '>=', .40)
                    ->get()->filter(fn (LabAgent $agent) => (int) data_get($agent->modelVersion?->metadata, 'last_screen_result.opportunity_metrics.valid_signal_opportunities', 0) > 0)
                    ->sortByDesc(fn (LabAgent $agent) => [(float) $agent->profit_factor, (int) $agent->sample_count])->first();
                if ($control) {
                    $controls->push($control);
                }
            }
            $controls = $controls->unique('id')->values();
            if ($controls->isEmpty()) {
                continue;
            }
            $datasetPath = $datasets->export($symbol, $lab->timeframe);
            foreach ($controls as $rank => $control) {
                $decision = $decisions->recordFullReplaySelection($control, true, 'CAUSAL_PROBE_ALTERNATIVE');
                $control->update(['lifecycle_status' => 'full_queued', 'decision_reason' => 'Causal probe control; replay evidence only, never direct promotion.']);
                $handoffs->record($generation, $control, 'causal_control_queued', 'completed', null, [
                    'selection_decision_id' => $decision->id, 'replay_purpose' => 'causal_probe_control', 'dataset_hash' => is_file($datasetPath) ? hash_file('sha256', $datasetPath) : null,
                    'rank' => $rank + 1,
                ]);
                $jobs[] = new EvaluateLabAgentJob($control->id, $symbol, 'full');
            }
            $this->info("{$symbol}: ".$controls->count().' causal control(s) queued.');
        }
        if ($jobs) {
            $batch = Bus::batch($jobs)->name('Causal probe controls')->onConnection((string) config('queue.default', 'redis'))->onQueue('lab-full-validation')->dispatch();
            $this->info("Causal control batch {$batch->id}: ".count($jobs).' jobs.');
        }

        return self::SUCCESS;
    }
}
