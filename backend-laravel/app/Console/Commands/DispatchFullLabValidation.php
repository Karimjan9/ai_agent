<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\LabEvaluationRun;
use App\Services\CandidateGateDecisionService;
use App\Services\CandidateHandoffService;
use App\Services\LabAgentPreflightService;
use App\Services\LabCandidateSelectionService;
use App\Services\LabDatasetExportService;
use App\Services\LabGenerationReportService;
use App\Services\MarketData\MarketDataContinuityService;
use App\Services\SystemLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

class DispatchFullLabValidation extends Command
{
    protected $signature = 'trading:dispatch-full-validation {symbol?} {--timeframe=H1}';

    protected $description = 'Select the strongest screened agents from every pair and serialize full walk-forward validation';

    public function handle(LabDatasetExportService $datasets, MarketDataContinuityService $continuity, LabCandidateSelectionService $selection, CandidateGateDecisionService $decisions, SystemLogService $logs, CandidateHandoffService $handoffs, LabAgentPreflightService $preflight): int
    {
        $symbols = $this->argument('symbol') ? [strtoupper($this->argument('symbol'))] : ['XAUUSD', 'EURUSD', 'GBPUSD'];
        $rounds = [];
        $queuedAgents = [];

        $timeframe = strtoupper((string) $this->option('timeframe'));
        foreach ($symbols as $symbol) {
            $lab = AiLaboratory::where('symbol', $symbol)->where('timeframe', $timeframe)->first();
            // Role-complete council research is deliberately role-first. Do
            // not let an older completed generation steal the full-replay
            // lane from four mandatory specialist passports. This branch is
            // only valid when all four roles have screened evidence; it never
            // opens combined council replay.
            $roleFirstReplay = false;
            $roleCandidate = $lab?->generations()
                ->with('agents.modelVersion')
                ->where('status', 'screening')
                ->latest('generation')
                ->first();
            $generation = null;
            if ($roleCandidate && $this->roleCompleteReplayReady($roleCandidate)) {
                $generation = $roleCandidate;
                $roleFirstReplay = true;
            }
            // Non-council generations retain the ordinary newest eligible
            // frontier behavior.
            if (! $generation) {
                $generation = $lab?->generations()
                    ->with('agents.modelVersion')
                    ->whereIn('status', ['screened', 'completed'])
                    ->whereHas('agents', fn ($query) => $query->where('lifecycle_status', 'screened'))
                    ->latest('generation')
                    ->first();
            }
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
            if ($generation->status !== 'screened' && ! $hasScreenedFollowUp && ! $roleFirstReplay) {
                $this->info("{$symbol} {$timeframe}: screening hali yakunlanmagan.");

                continue;
            }

            // Selection completes before any heavy export. It is immutable
            // evidence for why a candidate received scarce replay capacity.
            $generation = $generation->fresh(['agents.modelVersion']);
            $screened = $generation->agents->where('lifecycle_status', 'screened')->values();
            $screened = $this->enforceGenerationDatasetConsistency($generation, $screened);
            $laneSelection = $selection->selectValidationLanes($screened);
            $agents = $laneSelection['agents'];
            $lanes = $laneSelection['lanes'];
            $agents = $agents->filter(function ($agent) use ($preflight, $generation, $handoffs): bool {
                $inspection = $preflight->inspect($agent, 'full_validation');
                if ($inspection['passed']) {
                    return true;
                }
                $preflight->quarantine($agent, $inspection, 'full_validation_dispatch');
                $handoffs->record($generation, $agent, 'selection_failed', 'failed', 'LAB_AGENT_PREFLIGHT_FAILED', [
                    'preflight' => $inspection,
                    'promotion_evidence' => false,
                ]);

                return false;
            })->values();
            if ($agents->isEmpty()) {
                $this->warn("{$symbol}: full validation preflightdan o'tadigan agent qolmadi.");

                continue;
            }
            $councilCoverage = (array) ($laneSelection['council_role_coverage'] ?? []);
            if ((bool) data_get($councilCoverage, 'full_replay_required', false)) {
                $context = (array) $generation->trigger_context;
                $context['council_role_coverage'] = [
                    ...$councilCoverage,
                    'checked_at' => now()->utc()->toIso8601String(),
                ];
                $generation->update(['trigger_context' => $context]);
                if ((array) data_get($councilCoverage, 'missing_roles', []) !== []) {
                    $this->warn("{$symbol}: council role full-replay coverage missing: ".implode(', ', (array) data_get($councilCoverage, 'missing_roles', [])));
                }
            }
            $selectionIds = [];
            foreach ($screened as $screenedAgent) {
                $isSelected = $agents->contains('id', $screenedAgent->id);
                $lane = $lanes[$screenedAgent->id] ?? 'none';
                $selectionReason = match ($lane) {
                    'causal_probe' => 'CAUSAL_PROBE_ONLY',
                    'causal_probe_control' => 'CAUSAL_PROBE_ALTERNATIVE',
                    'portfolio_member' => 'PORTFOLIO_MEMBER_REPLAY',
                    'targeted_research' => 'TARGETED_RESEARCH_ONLY',
                    'volume_context' => 'VOLUME_CONTEXT_STANDALONE',
                    'council_role_full_replay' => 'COUNCIL_ROLE_FULL_REPLAY',
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
                            'volume_context' => 'volume_context_full_replay',
                            'general_candidate', 'orthogonal_specialist' => 'export',
                            default => 'targeted_generation',
                        }]);
            }
            if ($agents->isEmpty()) {
                $handoffs->noEligibleCandidate($generation);
                app(LabGenerationReportService::class)->record($generation->fresh(), 'screening_no_full_candidate');
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
        if (! $jobs) {
            return self::SUCCESS;
        }

        $batch = Bus::batch($jobs)->name('Global full validation')->onConnection((string) config('queue.default', 'redis'))->onQueue('lab-full-validation')->dispatch();
        foreach ($queuedAgents as $queued) {
            $handoffs->record($queued['generation'], $queued['agent'], 'queue_job_id', 'completed', null, [
                'queue_job_id' => $batch->id, 'queue_batch_id' => $batch->id, 'attempt' => 0,
                'selection_decision_id' => $queued['selection_decision_id'], 'failure_reason' => null, 'next_action' => 'worker_reservation',
            ]);
        }
        $this->info("Global full validation batch {$batch->id}: ".count($jobs).' candidates.');

        return self::SUCCESS;
    }

    private function roleCompleteReplayReady($generation): bool
    {
        if (! (bool) data_get($generation->trigger_context, 'role_complete_council', false)) {
            return false;
        }

        $required = [
            'trend_up_specialist',
            'trend_down_specialist',
            'range_specialist',
            'transition_risk_router',
        ];
        $roleAgents = $generation->agents
            ->filter(fn ($agent): bool => filled(data_get($agent->modelVersion?->metadata, 'role_complete_council.role')))
            ->groupBy(fn ($agent): string => (string) data_get($agent->modelVersion?->metadata, 'role_complete_council.role'));

        foreach ($required as $role) {
            $agent = $roleAgents->get($role)?->first();
            if (! $agent || $agent->lifecycle_status !== 'screened') {
                return false;
            }
        }

        return true;
    }

    /**
     * A rolling candle window can move while a long screening batch drains.
     * Such children remain immutable diagnostic evidence, but they cannot be
     * compared in one full-validation frontier. Keep the dominant frozen
     * snapshot cohort and quarantine only outliers; no strategy gate or
     * promotion evidence is manufactured from the mismatch.
     */
    private function enforceGenerationDatasetConsistency($generation, Collection $screened): Collection
    {
        if ($screened->isEmpty()) {
            return $screened;
        }

        $runs = LabEvaluationRun::query()
            ->where('lab_generation_id', $generation->id)
            ->where('phase', 'screening')
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->get()
            ->groupBy('lab_agent_id');
        $hashByAgent = $screened->mapWithKeys(function ($agent) use ($runs): array {
            $run = $runs->get($agent->id)?->first();

            return [$agent->id => $run?->data_hash];
        });
        $observed = $hashByAgent->filter(fn ($hash): bool => is_string($hash) && $hash !== '');
        if ($observed->isEmpty()) {
            return $screened;
        }

        $counts = $observed->countBy()->sortDesc();
        $dominantHash = (string) $counts->keys()->first();
        $outliers = $screened->filter(fn ($agent): bool => (string) ($hashByAgent->get($agent->id) ?? '') !== $dominantHash
        )->values();
        if ($outliers->isEmpty()) {
            return $screened;
        }

        $context = (array) $generation->trigger_context;
        $context['dataset_consistency'] = [
            'protocol' => 'lab_generation_dataset_consistency_v1',
            'checked_at' => now()->utc()->toIso8601String(),
            'dominant_screen_data_hash' => $dominantHash,
            'data_hash_counts' => $counts->all(),
            'outlier_agent_ids' => $outliers->pluck('id')->all(),
            'rule' => 'mixed rolling-window evidence is technical quarantine only; no strategy verdict or promotion evidence is created',
            'promotion_evidence' => false,
        ];
        $generation->update(['trigger_context' => $context]);
        foreach ($outliers as $agent) {
            $agent->update([
                'lifecycle_status' => 'technical_quarantine',
                'decision_reason' => 'Technical quarantine: screening dataset hash did not match the generation snapshot cohort; strategy verdict withheld.',
            ]);
        }

        $eligible = $screened->reject(fn ($agent): bool => $outliers->contains('id', $agent->id))->values();
        if ($eligible->isEmpty()) {
            $generation->update(['status' => 'technical_quarantine', 'completed_at' => now()]);
        }

        return $eligible;
    }
}
