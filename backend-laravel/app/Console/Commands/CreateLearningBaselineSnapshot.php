<?php

namespace App\Console\Commands;

use App\Models\CandidateGateDecision;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\PaperConfidenceCalibration;
use App\Models\PaperSignal;
use App\Models\PaperSignalOutcome;
use App\Models\SelectionEvent;
use App\Models\SystemEvent;
use App\Services\TradingDeploymentSafetyService;
use Illuminate\Console\Command;

class CreateLearningBaselineSnapshot extends Command
{
    protected $signature = 'trading:baseline-snapshot {--label=pre-rescue}';
    protected $description = 'Persist an immutable current-state baseline before learning-protocol changes';

    public function handle(): int
    {
        $payload = [
            'forward_validated' => ModelMarketPerformance::where('status', 'forward_validated')->count(),
            'paper_signals' => PaperSignal::count(), 'paper_outcomes' => PaperSignalOutcome::count(),
            'calibrations' => PaperConfidenceCalibration::count(), 'selection_events' => SelectionEvent::count(),
            'gate_transitions' => CandidateGateDecision::where('stage', 'statistical_forward_gate')->count(),
            'generations' => LabGeneration::query()->select('id', 'generation', 'status')->latest('id')->take(12)->get()->map->only(['id', 'generation', 'status'])->all(),
            'live_safety' => app(TradingDeploymentSafetyService::class)->status(),
        ];
        $key = 'learning_baseline:'.(string) $this->option('label');
        SystemEvent::updateOrCreate(['event_key' => $key], [
            'event_type' => 'learning_baseline_snapshot', 'agent' => 'operations', 'severity' => 'info',
            'summary' => 'Immutable learning-protocol baseline recorded.', 'payload' => $payload, 'occurred_at' => now(),
        ]);
        $this->line(json_encode($payload, JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }
}
