<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabEvaluationRun;
use App\Services\CandidateGateDecisionService;
use App\Services\CandidateHandoffService;
use App\Services\LabAgentPreflightService;
use App\Services\LabCandidateSelectionService;
use App\Services\LabDatasetExportService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabGenerationReportService;
use App\Services\MarketData\MarketDataContinuityService;
use App\Services\MarketData\HistoricalDataQualityService;
use App\Services\SystemLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

class DispatchFullLabValidation extends Command
{
    protected $signature = 'trading:dispatch-full-validation {symbol?} {--timeframe=H1}';

    protected $description = 'Select the strongest screened agents from every pair and serialize full walk-forward validation';

    public function handle(LabDatasetExportService $datasets, MarketDataContinuityService $continuity, HistoricalDataQualityService $quality, LabCandidateSelectionService $selection, CandidateGateDecisionService $decisions, SystemLogService $logs, CandidateHandoffService $handoffs, LabAgentPreflightService $preflight, LabImmutableEvidenceService $evidence): int
    {
        $symbols = $this->argument('symbol') ? [strtoupper($this->argument('symbol'))] : ['XAUUSD', 'EURUSD', 'GBPUSD'];
        $rounds = [];
        $queuedAgents = [];

        $timeframe = strtoupper((string) $this->option('timeframe'));
        foreach ($symbols as $symbol) {
            $lab = AiLaboratory::where('symbol', $symbol)->where('timeframe', $timeframe)->first();
            if (! $lab || (string) $lab->lifecycle_mode !== 'lighthouse') {
                $this->info("{$symbol} {$timeframe}: shadow lab; full-validation dispatch skipped.");

                continue;
            }
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
            // A worker restart can finish the last screen after the terminal
            // boundary write, leaving a generation in `screening` even
            // though every agent is already terminal. Repair that mutable
            // projection before selection; never treat the cohort as an
            // active/incomplete generation forever.
            if ($roleCandidate
                && data_get($roleCandidate->trigger_context, 'role_complete_council') !== true
                && $this->closeTerminalScreeningBoundary($roleCandidate)) {
                $roleCandidate = $roleCandidate->fresh(['agents.modelVersion']);
            }
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

            // The full protocol deliberately uses two sealed sources: the
            // canonical rolling snapshot and a non-promotion foundation
            // archive. Prepare the latter before queue admission so the
            // worker cannot turn a missing archive into a strategy-looking
            // evaluation error.
            try {
                $foundationSnapshot = $datasets->ensureGenerationFoundationSnapshot($generation);
                if ($timeframe === 'M15') {
                    $datasets->ensureGenerationRegimeSnapshot($generation);
                }
            } catch (\Throwable $exception) {
                $this->warn("{$symbol}: foundation archive tayyor emas; full validation bloklandi: ".$exception->getMessage());

                continue;
            }
            $rollingManifest = data_get($generation->trigger_context, 'canonical_dataset_snapshots.price.manifest');
            $foundationManifest = $foundationSnapshot['manifest'] ?? data_get(
                $generation->fresh()->trigger_context,
                'canonical_dataset_snapshots.foundation.manifest',
            );
            $coverage = $quality->fullReplayCoverage(
                $symbol,
                $lab->timeframe,
                is_array($rollingManifest) ? $rollingManifest : null,
                is_array($foundationManifest) ? $foundationManifest : null,
            );
            if ($coverage['status'] !== 'ready') {
                $this->warn("{$symbol}: full replay history coverage blocked: ".implode(', ', (array) $coverage['reasons']).'.');

                continue;
            }

            // Selection completes before any heavy export. It is immutable
            // evidence for why a candidate received scarce replay capacity.
            $generation = $generation->fresh(['agents.modelVersion']);
            $screened = $generation->agents->where('lifecycle_status', 'screened')->values();
            $screened = $this->enforceGenerationDatasetConsistency($generation, $screened);
            if ($timeframe === 'M15') {
                $screened = $this->rescreenStaleM15RegimeEvidence($generation, $screened, $handoffs, $evidence);
            }
            // Full validation is opened by exactly one evidence-complete
            // screening survivor. A contextual near-miss may still inform the
            // targeted-mutation curriculum, but it must not consume the full
            // replay lane. This check is deliberately against the immutable
            // run/artifact plane, not only the mutable agent projection.
            $screened = $screened->filter(function ($agent) use ($evidence): bool {
                $decision = CandidateGateDecision::query()
                    ->where('lab_agent_id', $agent->id)
                    ->where('stage', 'screening')
                    ->latest('evaluated_at')
                    ->first();
                if (! $decision || $decision->decision !== 'passed') {
                    return false;
                }

                $run = LabEvaluationRun::query()
                    ->where('lab_agent_id', $agent->id)
                    ->where('phase', 'screening')
                    ->where('status', 'completed')
                    ->latest('id')
                    ->first();
                if (! $run) {
                    return false;
                }

                return $evidence->learningEligibility($run)['complete'] === true;
            })->values();
            $laneSelection = $selection->selectValidationLanes($screened);
            // Normal generations use the one-candidate bootstrap funnel. A
            // role-complete generation is the explicit exception: its four
            // complementary roles are the experiment, and each still enters
            // the same serialized full-replay queue and unchanged passport.
            if (! (bool) data_get($generation->trigger_context, 'role_complete_council', false)) {
                $laneSelection['agents'] = $laneSelection['agents']->take(1)->values();
            }
            $laneSelection['lanes'] = collect($laneSelection['lanes'] ?? [])
                ->only($laneSelection['agents']->pluck('id')->all())
                ->all();
            $agents = $laneSelection['agents'];
            $lanes = $laneSelection['lanes'];
            $selectedBeforePreflight = $agents->count();
            $agents = $agents->filter(function ($agent) use ($preflight, $generation, $handoffs, $evidence): bool {
                $contractRepair = $preflight->normalizeExecutionContractMetadata($agent);
                if ($contractRepair !== []) {
                    $agent = $agent->fresh(['modelVersion']);
                    $evidence->recordLifecycle($agent, 'execution_contract_metadata_normalized', [
                        ...$contractRepair,
                        'reason_code' => 'EXECUTION_CONTRACT_NUMERIC_SERIALIZATION_DRIFT',
                    ], 'full_validation', null, null, self::class);
                    $handoffs->record($generation, $agent, 'execution_contract_normalized', 'passed', 'EXECUTION_CONTRACT_NUMERIC_SERIALIZATION_DRIFT', [
                        ...$contractRepair,
                        'promotion_evidence' => false,
                    ]);
                }
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
                if ($selectedBeforePreflight === 0) {
                    $handoffs->noEligibleCandidate($generation);
                    app(LabGenerationReportService::class)->record($generation->fresh(), 'screening_no_full_candidate');
                    $this->warn("{$symbol}: screened cohortdan full validation uchun eligible candidate tanlanmadi; targeted curriculum handoff yozildi, promotion evidence yaratilmaydi.");
                } else {
                    $this->warn("{$symbol}: full validation preflightdan o'tadigan agent qolmadi.");
                }

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
                $foundationSnapshot = $datasets->ensureGenerationFoundationSnapshot($generation);
                $payloadHash = is_file($datasetPath) ? hash_file('sha256', $datasetPath) : null;
                $logs->write('FULL_VALIDATION_EXPORT_READY', 'Full-validation dataset export completed.', [
                    'symbol' => $symbol, 'timeframe' => $lab->timeframe, 'generation' => $generation->generation,
                    'foundation_dataset_hash' => $foundationSnapshot['sha256'] ?? null,
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
            // A screened generation's completed_at belongs to the screening
            // boundary.  Full validation reopens the lifecycle and must clear
            // that terminal timestamp, otherwise monitoring sees an active
            // generation with a contradictory terminal marker.
            $generation->update(['status' => 'full_validation', 'completed_at' => null]);
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

    /**
     * A screen produced before the closed-H1 regime contract is not eligible
     * for M15 full selection. Requeue it as technical research work so its
     * old score remains diagnostic evidence but can never enter a promotion
     * lane without a fresh regime-bound replay.
     */
    private function rescreenStaleM15RegimeEvidence(
        $generation,
        Collection $screened,
        CandidateHandoffService $handoffs,
        LabImmutableEvidenceService $evidence,
    ): Collection {
        if ($screened->isEmpty()) {
            return $screened;
        }

        $expectedHash = (string) data_get(
            $generation->trigger_context,
            'canonical_dataset_snapshots.regime.sha256',
            '',
        );
        if ($expectedHash === '') {
            return $screened;
        }

        $stale = $screened->filter(function ($agent) use ($expectedHash): bool {
            $observedHash = (string) data_get(
                $agent->modelVersion?->metadata,
                'last_screen_result.regime_snapshot_sha256',
                '',
            );

            // The mutable projection can be written by a long-lived worker
            // restart after the immutable run has already closed. Prefer the
            // durable request manifest as a fallback; otherwise every
            // scheduler poll would mistake a valid M15 screen for a legacy
            // result and reopen the generation indefinitely.
            if ($observedHash === '') {
                $requestMeta = LabEvaluationRun::query()
                    ->where('lab_agent_id', $agent->id)
                    ->where('phase', 'screening')
                    ->where('status', 'completed')
                    ->latest('id')
                    ->value('request_meta');
                $requestMetaPayload = is_array($requestMeta)
                    ? $requestMeta
                    : json_decode((string) $requestMeta, true);
                $observedHash = (string) data_get(
                    is_array($requestMetaPayload) ? $requestMetaPayload : [],
                    'dataset_manifest.regime_snapshot_sha256',
                    '',
                );
            }

            return $observedHash !== $expectedHash;
        })->values();
        if ($stale->isEmpty()) {
            return $screened;
        }

        foreach ($stale as $agent) {
            $agent->update([
                'lifecycle_status' => 'queued',
                'decision_reason' => 'M15 screen evidence predates the generation-frozen closed H1 regime; clean rescreen required before full validation. Strategy verdict withheld.',
            ]);
            $handoffs->record($generation, $agent, 'm15_rescreen_required', 'queued', 'M15_SCREEN_H1_REGIME_EVIDENCE_MISSING_OR_STALE', [
                'expected_regime_snapshot_sha256' => $expectedHash,
                'promotion_evidence' => false,
            ]);
            $evidence->recordLifecycle($agent, 'm15_rescreen_required', [
                'reason_code' => 'M15_SCREEN_H1_REGIME_EVIDENCE_MISSING_OR_STALE',
                'expected_regime_snapshot_sha256' => $expectedHash,
                'quality_verdict' => 'withheld',
                'promotion_evidence' => false,
            ], 'screening', null, null, self::class);
        }

        $generation->update(['status' => 'screening', 'completed_at' => null]);
        $jobs = $stale->map(fn ($agent) => new EvaluateLabAgentJob($agent->id, $agent->symbol, 'screen'))->all();
        $batch = Bus::batch($jobs)
            ->name("{$generation->laboratory->symbol} M15 closed-H1 rescreen G{$generation->generation}")
            ->allowFailures()
            ->onConnection((string) config('queue.default', 'redis'))
            ->onQueue((string) config('services.lab_queue.screening_queue', 'lab-screening'))
            ->dispatch();
        $this->info("{$generation->laboratory->symbol} M15: {$stale->count()} legacy screen result(s) rescreened under closed H1 regime; batch {$batch->id}.");

        return $screened->reject(fn ($agent): bool => $stale->contains('id', $agent->id))->values();
    }

    /**
     * Restore the screening terminal boundary after a worker interruption.
     * This only repairs a projection; it does not create strategy or
     * promotion evidence and it never closes a generation with open work.
     */
    private function closeTerminalScreeningBoundary($generation): bool
    {
        $openStatuses = [
            'draft', 'queued', 'screening', 'evaluation_error', 'full_queued',
            'full_validation', 'training',
        ];
        if ($generation->agents->contains(fn ($agent): bool => in_array($agent->lifecycle_status, $openStatuses, true))) {
            return false;
        }

        $screened = $generation->agents->where('lifecycle_status', 'screened')->isNotEmpty();
        $context = (array) ($generation->trigger_context ?? []);
        $context['screening_terminal_recovery'] = [
            'protocol' => 'generation_terminal_boundary_recovery_v1',
            'recovered_from_status' => 'screening',
            'status' => $screened ? 'screened' : 'technical_quarantine',
            'recovered_at' => now()->utc()->toIso8601String(),
            'all_agents_terminal' => true,
            'promotion_evidence' => false,
        ];
        $generation->update([
            'status' => $screened ? 'screened' : 'technical_quarantine',
            'completed_at' => now(),
            'trigger_context' => $context,
        ]);
        app(LabGenerationReportService::class)->record(
            $generation->fresh(['agents']),
            $screened ? 'screening_completed_recovered' : 'screening_technical_quarantine_recovered',
        );

        return true;
    }
}
