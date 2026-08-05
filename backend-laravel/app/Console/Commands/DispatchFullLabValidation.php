<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Services\LabDatasetExportService;
use App\Services\LabCandidateSelectionService;
use App\Services\MarketData\MarketDataContinuityService;
use App\Services\SystemLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class DispatchFullLabValidation extends Command
{
    protected $signature = 'trading:dispatch-full-validation {symbol?} {--timeframe=H1}';

    protected $description = 'Select the strongest screened agents from every pair and serialize full walk-forward validation';

    public function handle(LabDatasetExportService $datasets, MarketDataContinuityService $continuity, LabCandidateSelectionService $selection, \App\Services\CandidateGateDecisionService $decisions, SystemLogService $logs, \App\Services\CandidateHandoffService $handoffs): int
    {
        $symbols = $this->argument('symbol') ? [strtoupper($this->argument('symbol'))] : ['XAUUSD', 'EURUSD', 'GBPUSD'];
        $rounds = [];
        $queuedAgents = [];

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
            $hasScreenedFollowUp = $generation->status === 'completed'
                && $generation->agents()->where('lifecycle_status', 'screened')->exists()
                && ! $generation->agents()->whereIn('lifecycle_status', ['queued', 'screening', 'training', 'full_queued', 'full_validation'])->exists();
            // The scheduled full selector can run just before the last
            // screening job finishes. A completed generation with remaining
            // screened council children is a valid second research wave; do
            // not leave those targeted lanes stranded until a manual retry.
            if ($generation->status !== 'screened' && ! $hasScreenedFollowUp) {
            $this->info("{$symbol} {$timeframe}: screening hali yakunlanmagan.");
                continue;
            }

            // Selection completes before any heavy export. It is immutable
            // evidence for why a candidate received scarce replay capacity.
            $generation = $generation->fresh(['agents.modelVersion']);
            $screened = $generation->agents->where('lifecycle_status', 'screened')->values();
            $laneSelection = $selection->selectValidationLanes($screened);
            $agents = $laneSelection['agents'];
            $lanes = $laneSelection['lanes'];
            $selectionIds = [];
            foreach ($screened as $screenedAgent) {
                $isSelected = $agents->contains('id', $screenedAgent->id);
                $lane = $lanes[$screenedAgent->id] ?? 'none';
                $selectionReason = match ($lane) {
                    'causal_probe' => 'CAUSAL_PROBE_ONLY',
                    'causal_probe_control' => 'CAUSAL_PROBE_ALTERNATIVE',
                    'portfolio_member' => 'PORTFOLIO_MEMBER_REPLAY',
                    'targeted_research' => 'TARGETED_RESEARCH_ONLY',
                    default => null,
                };
                $decision = $decisions->recordFullReplaySelection($screenedAgent, $isSelected, $selectionReason);
                $selectionIds[$screenedAgent->id] = $decision->id;
                $handoffs->record($generation, $screenedAgent, 'selection_passed', $isSelected ? 'completed' : 'not_selected',
                    $isSelected ? null : 'NO_ELIGIBLE_CANDIDATE', ['selection_decision_id' => $decision->id,
                        'selection_lane' => $lane,
                        'next_action' => match ($lane) {
                            'causal_probe' => 'causal_probe_full_replay',
                            'causal_probe_control' => 'causal_probe_control_replay',
                            'portfolio_member' => 'portfolio_combined_replay',
                            'targeted_research' => 'targeted_research_full_replay',
                            'general_candidate', 'orthogonal_specialist' => 'export',
                            default => 'targeted_generation',
                        }]);
            }
            if ($agents->isEmpty()) {
                $handoffs->noEligibleCandidate($generation);
                app(\App\Services\LabGenerationReportService::class)->record($generation->fresh(), 'screening_no_full_candidate');
                $this->info("{$symbol}: full validation uchun screened kandidat yo'q.");
                continue;
            }

            $exportStarted = microtime(true);
            try {
                $datasetPath = $datasets->export($symbol, $lab->timeframe);
                $payloadHash = is_file($datasetPath) ? hash_file('sha256', $datasetPath) : null;
                $logs->write('FULL_VALIDATION_EXPORT_READY', 'Full-validation dataset export completed.', [
                    'symbol' => $symbol, 'timeframe' => $lab->timeframe, 'generation' => $generation->generation,
                    'duration_ms' => (int) ((microtime(true) - $exportStarted) * 1000),
                ], 'info', 'lab_validation', 'dataset_export', 'ready');
                foreach ($agents as $agent) {
                    $handoffs->record($generation, $agent, 'export_locked', 'completed', null, ['selection_decision_id' => $selectionIds[$agent->id] ?? null,
                        'payload_hash' => $payloadHash, 'duration_ms' => (int) ((microtime(true) - $exportStarted) * 1000),
                        'idempotency_key' => hash('sha256', "{$generation->id}|{$agent->id}|{$payloadHash}")]);
                }
            } catch (\Throwable $exception) {
                $logs->write('FULL_VALIDATION_EXPORT_FAILED', 'Full-validation dataset export failed; no replay was dispatched.', [
                    'symbol' => $symbol, 'timeframe' => $lab->timeframe, 'generation' => $generation->generation,
                    'duration_ms' => (int) ((microtime(true) - $exportStarted) * 1000), 'reason' => $exception->getMessage(),
                ], 'warning', 'lab_validation', 'dataset_export', 'failed');
                $this->error("{$symbol}: dataset export failed; no full replay dispatched.");
                continue;
            }
            $logs->write('FULL_VALIDATION_SELECTION_COMPLETE', 'Full-validation candidate selection completed.', [
                'symbol' => $symbol, 'timeframe' => $lab->timeframe, 'generation' => $generation->generation,
                'screened_count' => $screened->count(), 'selected_count' => $agents->count(), 'selection_lanes' => $lanes,
            ], 'info', 'lab_validation', 'candidate_selection', 'completed');
            foreach ($agents as $rank => $agent) {
                $agent->update(['lifecycle_status' => 'full_queued', 'decision_reason' => 'Dynamic evidence-frontier candidate #'.($rank + 1).'; queued for serialized full validation.']);
                $handoffs->record($generation, $agent, 'queued', 'completed', null, ['rank' => $rank + 1, 'selection_decision_id' => $selectionIds[$agent->id] ?? null,
                    'selection_lane' => $lanes[$agent->id] ?? 'unknown',
                    'idempotency_key' => hash('sha256', "{$generation->id}|{$agent->id}|full")]);
                $rounds[$rank][] = new EvaluateLabAgentJob($agent->id, $symbol, 'full');
                $queuedAgents[] = ['generation' => $generation, 'agent' => $agent, 'selection_decision_id' => $selectionIds[$agent->id] ?? null];
            }
            $generation->update(['status' => 'full_validation']);
        }

        // Interleave pair ranks (XAU #1, EUR #1, GBP #1, then #2...) so one
        // market cannot monopolize the single expensive validation worker.
        $jobs = collect($rounds)->sortKeys()->flatMap(fn ($round) => $round)->all();
        if (! $jobs) return self::SUCCESS;

        $batch = Bus::batch($jobs)->name('Global full validation')->onConnection('database')->onQueue('lab-full-validation')->dispatch();
        foreach ($queuedAgents as $queued) {
            $handoffs->record($queued['generation'], $queued['agent'], 'queue_job_id', 'completed', null, [
                'queue_job_id' => $batch->id, 'queue_batch_id' => $batch->id, 'attempt' => 0,
                'selection_decision_id' => $queued['selection_decision_id'], 'failure_reason' => null, 'next_action' => 'worker_reservation',
            ]);
        }
        $this->info("Global full validation batch {$batch->id}: ".count($jobs).' candidates.');

        return self::SUCCESS;
    }
}
