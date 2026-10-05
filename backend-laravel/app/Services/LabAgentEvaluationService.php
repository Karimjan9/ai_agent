<?php

namespace App\Services;

use App\Exceptions\ReplayLaneBusyException;
use App\Jobs\ProcessLabScreeningLearningProjection;
use App\Models\AgentLearningCausalExperiment;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\ModelVersion;
use App\Services\MarketData\CandlePayloadService;
use App\Services\MarketData\MarketVolumeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class LabAgentEvaluationService
{
    public function __construct(private CandlePayloadService $candles, private MarketChampionService $champions, private LabDatasetExportService $datasets, private ScreeningLearningOutboxService $screeningOutbox, private CandidateGateDecisionService $gateDecisions, private ShadowVetoLedgerService $shadowVetoLedger, private CandidateHandoffService $handoffs, private CounterfactualBlameGraphService $blameGraph, private LearningProtocolSafetyService $protocolSafety, private LabImmutableEvidenceService $evidence, private StrategyParameterSchemaService $schemas, private MarketVolumeService $volumes, private AgentKnowledgeService $knowledge, private ParentContributionGraphService $parentGraphService, private LabGenerationContextService $generationContext, private LabInstrumentResearchService $instrumentResearch) {}

    public function evaluate(LabAgent $agent, ?LabEvaluationRun $run = null): void
    {
        $agent->loadMissing('modelVersion', 'generation');
        $councilPurpose = $agent->modelVersion
            ? app(SpecialistCouncilLifecycleService::class)->evaluationPurposeForModel($agent->modelVersion) : null;
        if ($councilPurpose === 'research'
            || ($councilPurpose === null && data_get($agent->modelVersion?->metadata, 'specialist_council') !== null)
            || ($agent->generation && app(SpecialistCouncilPreparationService::class)->isResearchGeneration($agent->generation))) {
            throw new RuntimeException('SPECIALIST_COUNCIL_RESEARCH_ONLY_FULL_VALIDATION_FORBIDDEN');
        }
        $agent->loadMissing('generation');
        if (data_get($agent->generation?->trigger_context, 'mtf_bundle_manifest.validation_bundle_protocol') === MultiTimeframeSnapshotService::DISCOVERY_BUNDLE_PROTOCOL) {
            throw new RuntimeException('DISCOVERY_ONLY_BUNDLE_FULL_VALIDATION_FORBIDDEN');
        }
        $run ??= $this->evidence->beginRun($agent, 'full_validation', 'full', ['source' => 'direct_evaluation']);
        $agent->load('modelVersion', 'generation');
        $model = $agent->modelVersion;
        $edgeGenesisReplay = data_get($model->metadata, 'edge_genesis.protocol') === DependencyAwareEdgeGenesisFoundryService::PROTOCOL;
        $runtimeTimeframe = $this->replayTimeframe($agent);
        $mtfBundle = $this->replayMtfBundle($agent, $edgeGenesisReplay);
        $modelVolumeEnabled = $this->volumeEnabled($model);
        $foundationSnapshotKey = $modelVolumeEnabled && $mtfBundle === null
            ? 'foundation_volume'
            : 'foundation';
        $edgeGenesisPreflight = app(DependencyAwareEdgeGenesisFoundryService::class)->preflight($agent);
        if (! (bool) data_get($edgeGenesisPreflight, 'allowed', true)) {
            $agent->update(['lifecycle_status' => 'technical_quarantine', 'decision_reason' => 'Edge Genesis preflight failed: INVALID_EDGE_OBSERVABILITY.']);
            $this->evidence->finishRun($run, 'completed', ['edge_genesis_preflight' => $edgeGenesisPreflight], [], ['technical_quarantine' => true]);

            return;
        }
        $playbookPreflight = app(FullStackPlaybookMasteryService::class)->preflight($agent);
        if (! (bool) data_get($playbookPreflight, 'allowed', true)) {
            $agent->update(['lifecycle_status' => 'technical_quarantine', 'decision_reason' => 'Full Stack Playbook preflight failed: INVALID_PLAYBOOK_EXECUTION_CONTRACT.']);
            $this->evidence->finishRun($run, 'completed', ['full_stack_playbook_preflight' => $playbookPreflight], [], ['technical_quarantine' => true]);

            return;
        }
        $isM15 = strtoupper((string) $agent->timeframe) === 'M15';
        $rawResponse = null;
        $runtimePolicy = null;
        $cacheHit = false;
        $currentCodeHash = $this->evidence->codeHash();
        $currentParameterHash = $this->evidence->parameterHash($agent);
        $currentSnapshotHash = (string) data_get(
            $agent->generation?->trigger_context,
            'canonical_dataset_snapshots.price.sha256',
            data_get($agent->generation?->trigger_context, 'canonical_dataset_snapshots.volume.sha256', '')
        );
        $currentFoundationHash = (string) data_get(
            $agent->generation?->trigger_context,
            "canonical_dataset_snapshots.{$foundationSnapshotKey}.sha256",
            ''
        );
        $currentSnapshotPath = (string) data_get(
            $agent->generation?->trigger_context,
            'canonical_dataset_snapshots.price.path',
            data_get($agent->generation?->trigger_context, 'canonical_dataset_snapshots.volume.path', ''),
        );
        $currentFoundationPath = (string) data_get(
            $agent->generation?->trigger_context,
            "canonical_dataset_snapshots.{$foundationSnapshotKey}.path",
            '',
        );
        $currentFoundationRowCount = (int) data_get(
            $agent->generation?->trigger_context,
            "canonical_dataset_snapshots.{$foundationSnapshotKey}.manifest.row_count",
            0,
        );
        $currentRegimeHash = (string) data_get(
            $agent->generation?->trigger_context,
            'canonical_dataset_snapshots.regime.sha256',
            '',
        );
        $currentRegimePath = (string) data_get(
            $agent->generation?->trigger_context,
            'canonical_dataset_snapshots.regime.path',
            '',
        );
        $currentSnapshotFileHash = is_file($currentSnapshotPath) ? hash_file('sha256', $currentSnapshotPath) : null;
        $currentFoundationFileHash = is_file($currentFoundationPath) ? hash_file('sha256', $currentFoundationPath) : null;
        $currentRegimeFileHash = is_file($currentRegimePath) ? hash_file('sha256', $currentRegimePath) : null;
        $currentMtfBundleHash = (string) data_get($mtfBundle, 'bundle_hash', '');
        // Full replay is research/training evidence. Its primary dataset is
        // the immutable pre-2026 foundation, never the paper snapshot.
        $currentReplayHash = $currentMtfBundleHash !== ''
            ? $currentMtfBundleHash
            : ($currentFoundationHash !== '' ? $currentFoundationHash : $currentSnapshotHash);
        $currentReplayPath = $currentMtfBundleHash !== ''
            ? (string) data_get($mtfBundle, 'entry_dataset_path', '')
            : ($currentFoundationPath !== '' ? $currentFoundationPath : $currentSnapshotPath);
        $currentReplayFileHash = is_file($currentReplayPath) ? hash_file('sha256', $currentReplayPath) : null;
        $cached = data_get($model->metadata, 'full_validation_batch');
        $cachedRuntimePolicy = (array) data_get($cached, 'full_replay_runtime_policy', []);
        $configuredFoundationThreshold = max(1, (int) config('services.lab_selection.full_replay_bounded_cohort_foundation_rows', 100000));
        $configuredMaxCohortSize = $this->fullReplayMaxCohortSize($agent);
        $cacheRuntimePolicyMatches = data_get($cachedRuntimePolicy, 'protocol') === 'full_replay_runtime_budget_v1'
            && $currentFoundationRowCount > 0
            && (int) data_get($cachedRuntimePolicy, 'foundation_row_count', -1) === $currentFoundationRowCount
            && (int) data_get($cachedRuntimePolicy, 'foundation_threshold_rows', -1) === $configuredFoundationThreshold
            && (int) data_get($cachedRuntimePolicy, 'max_cohort_size', -1) === $configuredMaxCohortSize;
        $cacheIsSealed = ! $edgeGenesisReplay
            && ! $this->isCausalLearningConfirmation($agent)
            // The legacy metadata cache is sealed before the council plan's
            // capital/window policy is bound. Native councils use Python's
            // exact request cache instead; never reuse a decorated old arm.
            && data_get($model->metadata, 'specialist_council') === null
            && data_get($model->metadata, 'specialist_council_evaluation') === null
            && (int) data_get($cached, 'generation_id') === (int) $agent->lab_generation_id
            && is_array(data_get($cached, 'item'))
            && is_array(data_get($cached, 'request_manifest'))
            && hash_equals($currentCodeHash, (string) data_get($cached, 'code_hash', ''))
            && hash_equals($currentParameterHash, (string) data_get($cached, 'parameter_hash', ''))
            && $currentReplayHash !== ''
            && hash_equals($currentReplayHash, (string) data_get($cached, 'data_hash', ''))
            && ($currentMtfBundleHash !== ''
                ? hash_equals($currentMtfBundleHash, (string) data_get($cached, 'mtf_bundle_hash', ''))
                : (is_string($currentReplayFileHash) && hash_equals($currentReplayHash, $currentReplayFileHash)))
            && $currentFoundationHash !== ''
            && hash_equals($currentFoundationHash, (string) data_get($cached, 'foundation_data_hash', ''))
            && is_string($currentFoundationFileHash)
            && hash_equals($currentFoundationHash, $currentFoundationFileHash)
            && (! $isM15
                || ($currentRegimeHash !== ''
                    && is_string($currentRegimeFileHash)
                    && hash_equals($currentRegimeHash, $currentRegimeFileHash)
                    && hash_equals($currentRegimeHash, (string) data_get($cached, 'regime_data_hash', ''))))
            && $cacheRuntimePolicyMatches;
        if ($cacheIsSealed) {
            $item = $cached['item'];
            $runtimePolicy = $cachedRuntimePolicy;
            $cacheHit = true;
            // A cached cohort result is reusable only when this new
            // immutable attempt receives the exact request manifest that
            // produced it. Without a request artifact the trace/ledger would
            // be detached from the dataset and cannot enter learning.
            $this->evidence->attachRequest($run, (array) $cached['request_manifest'], [
                'request_id' => 'cached-'.$run->run_id,
                'data_hash' => (string) data_get($cached, 'data_hash', ''),
            ]);
        } else {
            // Evaluate the selected generation cohort together.  This gives CSCV
            // and DSR a real candidate distribution instead of a meaningless
            // one-strategy batch.  The first serialized job caches each peer's
            // result; following jobs persist their own cached result without
            // repeating the expensive Python replay.
            $cohort = LabAgent::query()->with('modelVersion')->where('lab_generation_id', $agent->lab_generation_id)
                ->whereIn('lifecycle_status', ['full_queued', 'training'])->orderBy('id')->get();
            if ($cohort->isEmpty()) {
                $cohort = collect([$agent]);
            }
            $cohort = $this->closedResearchCohort($cohort, $model, $agent);
            // Promotion and research-only learning jobs may share a
            // generation, but they must never share a sealed cohort cache.
            // Otherwise a learning near-miss could alter the request
            // manifest of a promotion candidate (or vice versa).
            $learningLane = data_get($model->metadata, 'learning_lane.protocol') === LearningLaneService::PROTOCOL
                && data_get($model->metadata, 'learning_lane.promotion_evidence', false) !== true;
            $cohort = $cohort->filter(function (LabAgent $peer) use ($learningLane): bool {
                $peerLearning = data_get($peer->modelVersion?->metadata, 'learning_lane.protocol') === LearningLaneService::PROTOCOL
                    && data_get($peer->modelVersion?->metadata, 'learning_lane.promotion_evidence', false) !== true;

                return $peerLearning === $learningLane;
            })->values();
            if ($cohort->isEmpty()) {
                $cohort = collect([$agent]);
            }
            $portfolioMemberOnly = data_get($model->metadata, 'portfolio_research_contract.protocol') === 'portfolio_member_research_v1';
            // Portfolio members are independent sealed hypotheses. Replaying
            // several of them in one Python cohort multiplies the worst-case
            // runtime and can turn useful full evidence into a transport
            // timeout. The later combined portfolio replay remains the place
            // where complementary members are evaluated together.
            if ($portfolioMemberOnly) {
                $cohort = collect([$agent]);
            }
            // Seal the long foundation archive before choosing the replay
            // budget. The rolling snapshot is exported only after the final
            // cohort is known, so a removed volume specialist cannot force a
            // different expensive dataset contract by accident.
            $volumeEnabled = $cohort->contains(fn (LabAgent $peer): bool => $this->volumeEnabled($peer->modelVersion));
            $foundationSnapshot = $this->datasets->ensureGenerationFoundationSnapshot(
                $agent->generation,
                $volumeEnabled && $mtfBundle === null,
            );
            $regimeSnapshot = $mtfBundle === null && $isM15
                ? $this->datasets->ensureGenerationRegimeSnapshot($agent->generation)
                : null;
            $foundationRowCount = (int) data_get($foundationSnapshot, 'manifest.row_count', 0);
            $boundedThreshold = max(1, (int) config('services.lab_selection.full_replay_bounded_cohort_foundation_rows', 100000));
            $maxCohortSize = $this->fullReplayMaxCohortSize($agent);
            $datasetSnapshot = $this->datasets->ensureGenerationSnapshot($agent->generation, $volumeEnabled);
            if (! $portfolioMemberOnly) {
                // A previous cohort can finish before a sibling times out. Keep
                // its sealed item eligible for the next bounded cohort so the
                // Python candidate cache can reuse it and CSCV/DSR does not
                // silently forget a valid peer. Only exact generation,
                // code/data/foundation/runtime-policy matches are admitted.
                $cohort = $this->mergeSealedCohortPeers(
                    $cohort,
                    $agent->lab_generation_id,
                    $currentCodeHash,
                    $currentReplayHash,
                    (string) ($foundationSnapshot['sha256'] ?? ''),
                    $foundationRowCount,
                    $boundedThreshold,
                    $maxCohortSize,
                );
            }
            $originalCohortSize = $cohort->count();
            $boundedCohort = $foundationRowCount >= $boundedThreshold && $originalCohortSize > $maxCohortSize;
            $runtimePolicy = [
                'protocol' => 'full_replay_runtime_budget_v1',
                'mode' => $boundedCohort ? 'bounded_cohort' : 'full_eligible_cohort',
                'original_cohort_size' => $originalCohortSize,
                'selected_cohort_size' => $boundedCohort ? $maxCohortSize : $originalCohortSize,
                'max_cohort_size' => $maxCohortSize,
                'foundation_row_count' => $foundationRowCount,
                'foundation_threshold_rows' => $boundedThreshold,
                'reason' => $boundedCohort
                    ? ($maxCohortSize === 1
                        ? ($this->isCausalLearningConfirmation($agent)
                            ? 'CAUSAL_CONFIRMATION_ATOMIC_REPLAY'
                            : 'EDGE_GENESIS_ATOMIC_REPLAY')
                        : 'FOUNDATION_REPLAY_RUNTIME_BUDGET')
                    : 'NO_RUNTIME_CAP_REQUIRED',
                'hard_timeout_seconds' => $this->isCausalLearningConfirmation($agent)
                    ? (int) config('services.lab_selection.causal_replay_hard_timeout_seconds', 900)
                    : 3600,
                'transport_timeout_seconds' => $this->isCausalLearningConfirmation($agent)
                    ? (int) config('services.lab_selection.causal_replay_timeout_seconds', 960)
                    : (int) config('services.lab_selection.full_replay_timeout_seconds', 3900),
                'fold_budget' => $this->isCausalLearningConfirmation($agent)
                    ? [
                        'folds' => (int) config('services.learning_lane.causal_fold_count', 9),
                        'max_rows_per_fold' => (int) config('services.learning_lane.causal_max_rows_per_fold', 4096),
                        'per_fold_seconds' => $this->causalPerFoldBudgetSeconds(),
                        'audit_trace_rows' => (int) config('services.learning_lane.causal_audit_trace_rows', 512),
                        'fail_fast' => true,
                        'checkpoint_each_fold' => true,
                    ]
                    : null,
                'promotion_evidence' => false,
            ];
            if ($boundedCohort) {
                // Every serialized job must receive its own result. Keep the
                // current agent in the selected pair even when queue ordering
                // or a lifecycle transition makes it absent from the query.
                $currentPeer = $cohort->first(fn (LabAgent $peer): bool => (int) $peer->getKey() === (int) $agent->getKey()) ?: $agent;
                $cohort = $cohort
                    ->reject(fn (LabAgent $peer): bool => (int) $peer->getKey() === (int) $agent->getKey())
                    ->take($maxCohortSize - 1)
                    ->push($currentPeer)
                    ->unique(fn (LabAgent $peer): int => (int) $peer->getKey())
                    ->values();
                $runtimePolicy['selected_cohort_size'] = $cohort->count();
            }
            // A full replay must never inherit a stale archive from an older
            // generation. Export the immutable canonical snapshot at dispatch
            // time; the first serialized cohort job then caches that exact
            // result for every peer in the batch.
            $selectedVolumeEnabled = $cohort->contains(fn (LabAgent $peer): bool => $this->volumeEnabled($peer->modelVersion));
            if ($selectedVolumeEnabled !== $volumeEnabled) {
                $volumeEnabled = $selectedVolumeEnabled;
                $foundationSnapshot = $this->datasets->ensureGenerationFoundationSnapshot(
                    $agent->generation,
                    $volumeEnabled && $mtfBundle === null,
                );
                $datasetSnapshot = $this->datasets->ensureGenerationSnapshot($agent->generation, $volumeEnabled);
            }
            // The paper snapshot is retained in the generation context for
            // the paper lane, but it is never sent as full-replay input.
            $dataset = $mtfBundle !== null
                ? (string) $mtfBundle['entry_dataset_path']
                : $foundationSnapshot['path'];
            $manifest = (array) ($foundationSnapshot['manifest'] ?? []);
            $manifest['paper'] = $datasetSnapshot['manifest'];
            if ($mtfBundle !== null) {
                $manifest['mtf_foundation_bundle'] = (array) $mtfBundle['manifest'];
                $manifest['mtf_bundle_hash'] = (string) $mtfBundle['bundle_hash'];
                $manifest['mtf_execution_timeframe'] = $runtimeTimeframe;
                $manifest['snapshot_sha256'] = (string) $mtfBundle['bundle_hash'];
            }
            if ($regimeSnapshot !== null) {
                $manifest['regime'] = $regimeSnapshot['manifest'];
            }
            $replayDatasetHash = $mtfBundle !== null
                ? (string) $mtfBundle['bundle_hash']
                : (string) $foundationSnapshot['sha256'];
            $request = [
                'symbol' => $agent->symbol,
                'timeframe' => $runtimeTimeframe,
                'strategy' => 'all', 'evaluation_mode' => 'replay',
                'strategies' => $cohort->map(fn (LabAgent $peer): array => $this->screeningStrategyPayload(
                    $peer,
                    $runtimeTimeframe,
                    $mtfBundle,
                    $replayDatasetHash,
                ))->all(),
                'initial_balance' => 10000, 'risk_per_trade' => 1, 'dataset_path' => $dataset,
                'replay_dataset_hash' => $replayDatasetHash,
                'full_replay_runtime_policy' => $runtimePolicy,
                'volume_context' => $volumeEnabled
                    ? $this->volumeContextOrFail(
                        $agent->symbol,
                        $runtimeTimeframe,
                        $mtfBundle ?? $foundationSnapshot,
                    )
                    : $this->disabledVolumeContext(),
                'policy_context' => [
                    'trial_ledger' => app(LabTrialLedgerService::class)->selectionContext($agent->symbol, $agent->timeframe),
                    'full_replay_runtime_policy' => $runtimePolicy,
                    'data_boundary' => [
                        'protocol' => 'pre_2026_training_paper_only_v1',
                        'training_end_exclusive' => '2026-01-01T00:00:00Z',
                        'paper_allowed_for_replay' => false,
                        'paper_allowed_for_mutation' => false,
                        'promotion_evidence' => false,
                    ],
                    // Each cohort member keeps its own one-gene contract;
                    // the Python replay selects the contract by strategy so
                    // a sibling's mutation can never be used for plateau
                    // evidence by mistake.
                    'repair_contracts' => $cohort->mapWithKeys(function (LabAgent $peer): array {
                        $diff = (array) $peer->parameter_diff;
                        $changedGene = count($diff) === 1 ? array_key_first($diff) : null;

                        return [(string) $peer->id => [
                            'changed_gene' => $changedGene,
                            'repair_attempt' => (int) data_get($peer->modelVersion->metadata, 'repair_lineage.attempt', 0),
                            'parent_model_version_id' => $peer->parent_a_model_version_id ?: $peer->parent_b_model_version_id,
                            'parent_model_version_ids' => $this->parentGraphService->ids($peer),
                            'single_gene' => count($diff) === 1,
                        ]];
                    })->all(),
                    // All three pre-registered causal arms receive the same
                    // bounded research holding overlay. This is not a genome
                    // mutation: it is a shared label-horizon policy used only
                    // by the cold-start confirmation replay, so a legacy
                    // time_stop=0 model cannot leak across fold boundaries.
                    'learning_confirmation_contracts' => $cohort->mapWithKeys(function (LabAgent $peer): array {
                        $receipt = (array) data_get($peer->modelVersion?->metadata, 'learning_receipt', []);
                        $role = (string) data_get($receipt, 'causal_influence', '');
                        $cohortRole = (string) data_get($peer->modelVersion?->metadata, 'causal_learning_cohort.role', '');
                        if (! in_array($role, ['memory_guided', 'hypothesis_guided', 'causal_repair_guided', 'blinded_counterfactual', 'frozen_control'], true)
                            || ! in_array($cohortRole, ['memory_guided', 'hypothesis_guided', 'repair_guided', 'blinded', 'frozen_control'], true)
                            || data_get($receipt, 'integrity.valid') !== true) {
                            return [];
                        }
                        $holding = max(1, (int) config('services.learning_lane.confirmation_maximum_holding_bars', 240));

                        return [(string) $peer->id => [
                            'protocol' => 'bounded_cold_start_learning_confirmation_v1',
                            'role' => $role,
                            'cohort_role' => $cohortRole,
                            'causal_intent_id' => data_get($receipt, 'causal_intent_id'),
                            'maximum_holding_bars' => $holding,
                            'purge_bars' => $holding,
                            'embargo_bars' => 1,
                            'fold_count' => (int) config('services.learning_lane.causal_fold_count', 9),
                            'max_rows_per_fold' => (int) config('services.learning_lane.causal_max_rows_per_fold', 4096),
                            'per_fold_budget_seconds' => $this->causalPerFoldBudgetSeconds(),
                            'audit_trace_rows' => (int) config('services.learning_lane.causal_audit_trace_rows', 512),
                            'minimum_trades_per_window' => (int) config('services.learning_lane.causal_minimum_trades_per_window', 8),
                            'minimum_powered_windows' => (int) config('services.learning_lane.causal_minimum_powered_windows', 6),
                            'minimum_positive_windows' => (int) config('services.learning_lane.causal_minimum_positive_windows', 4),
                            'declared_time_stop_candles' => max(0, (int) data_get($peer->modelVersion?->parameters, 'time_stop_candles', 0)),
                            'execution_overlay' => 'shared_confirmation_maximum_holding_horizon',
                            'admitted' => true,
                            'blocker' => null,
                            'promotion_evidence' => false,
                        ]];
                    })->all(),
                    'edge_genesis_contracts' => $cohort->mapWithKeys(function (LabAgent $peer): array {
                        $edge = (array) data_get($peer->modelVersion?->metadata, 'edge_genesis', []);
                        if (data_get($edge, 'protocol') !== DependencyAwareEdgeGenesisFoundryService::PROTOCOL) {
                            return [];
                        }
                        $phase = (string) data_get($edge, 'phase', 'EDGE_DISCOVERY');
                        $attribution = data_get($peer->modelVersion?->metadata, 'edge_genesis_attribution.protocol') === DependencyAwareEdgeGenesisFoundryService::PROTOCOL;
                        $validation = (array) data_get($edge, 'validation_contract', []);
                        $folds = $attribution ? 9 : (int) ($validation['fold_count'] ?? ($phase !== 'EDGE_DISCOVERY' ? 9 : 2));

                        return [$peer->modelVersion->strategy => [
                            'protocol' => 'bounded_edge_genesis_replay_v1',
                            'phase' => $phase,
                            'arm' => data_get($edge, 'arm'),
                            'attribution_arm' => $attribution
                                ? data_get($peer->modelVersion?->metadata, 'edge_genesis_attribution.arm')
                                : null,
                            'context' => (array) data_get($edge, 'context', []),
                            'maximum_holding_bars' => 240,
                            'purge_bars' => 240,
                            'embargo_bars' => 1,
                            'fold_count' => $folds,
                            'fold_offset' => $attribution ? 5 : (int) ($validation['offset'] ?? 0),
                            'fold_universe_count' => $attribution ? 14 : (int) ($validation['universe_folds'] ?? $folds),
                            'window_plan_hash' => $attribution
                                ? data_get($edge, 'frozen_window_plan.window_plan_hash')
                                : ($validation['window_plan_hash'] ?? null),
                            'window_stage' => $attribution ? 'nine_fold_authority'
                                : (string) ($validation['stage'] ?? ($phase !== 'EDGE_DISCOVERY' ? 'nine_fold_authority' : 'two_fold_discovery')),
                            'max_rows_per_fold' => 4096,
                            'audit_trace_rows' => 512,
                            // Full MTF/toolbox state is intentionally allowed
                            // more time than scalar learning mutations, while
                            // the AI process still enforces a 20-minute total
                            // Edge boundary and a 240-second absolute fold cap.
                            'per_fold_budget_seconds' => 180,
                            'minimum_trades_per_window' => 1,
                            'admitted' => true,
                            'risk_governor_frozen' => true,
                            'promotion_evidence' => false,
                        ]];
                    })->all(),
                    // A provisional cartridge uses its own typed five-arm
                    // confirmation grammar. It shares the causal replay
                    // engine, but never impersonates a learning-policy
                    // confirmation or a promotable Edge passport.
                    'skill_cartridge_confirmation_contracts' => $cohort->mapWithKeys(function (LabAgent $peer): array {
                        $contract = $this->skillCartridgeConfirmationContract($peer);
                        if ($contract === null) {
                            return [];
                        }

                        return [$peer->modelVersion->strategy => $contract];
                    })->all(),
                ],
                'execution' => $this->executionAssumptions($agent->symbol),
                'execution_contract' => app(ExecutionContractService::class)->for(
                    $agent->symbol,
                    $runtimeTimeframe,
                ),
                'mtf_pilot' => app(MultiTimeframePilotService::class)->requestPayload(
                    $agent->symbol,
                    $runtimeTimeframe,
                    $model->strategy,
                    $currentMtfBundleHash ?: ($currentRegimeHash ?: null),
                ),
                'emit_decision_trace' => true,
            ];
            if ($mtfBundle === null) {
                $request['foundation_dataset_path'] = $foundationSnapshot['path'];
            }
            $request = $this->applyMtfReplayBundle($request, $mtfBundle);
            // A council seat is not only a label on the model version.  Its
            // standalone passport must be replayed inside the sealed niche
            // it owns, otherwise a trend-up child can borrow range/trend-down
            // outcomes before the combined council replay.  The singleton
            // member route uses the same deterministic router as the later
            // portfolio replay; it is still subject to every normal full,
            // forward and paper gate and never creates promotion evidence by
            // itself.
            $researchMembers = $cohort->filter(fn (LabAgent $peer): bool => data_get($peer->modelVersion->metadata, 'portfolio_research_contract.protocol') === 'portfolio_member_research_v1'
            );
            if ($researchMembers->isNotEmpty()) {
                $request['portfolio_members'] = $researchMembers->map(fn (LabAgent $peer): array => [
                    ...$this->screeningStrategyPayload(
                        $peer,
                        $runtimeTimeframe,
                        $mtfBundle,
                        $replayDatasetHash,
                    ),
                    'strategy' => $peer->modelVersion->strategy,
                    'base_strategy' => $this->schemas->runtimeBaseStrategy($peer->modelVersion->strategy, data_get($peer->modelVersion->metadata, 'base_strategy'), $peer->strategy_family),
                    'version' => $peer->modelVersion->version,
                    'parameters' => $peer->modelVersion->parameters ?? [],
                    'member_key' => 'lab_agent:'.$peer->id,
                    'role' => data_get($peer->modelVersion->metadata, 'portfolio_council_lane.role', 'specialist'),
                    'target_regime' => $this->normalizeCouncilTarget(data_get($peer->modelVersion->metadata, 'portfolio_research_contract.target_regime'), ['trend_up', 'trend_down', 'range']),
                    'target_volatility' => $this->normalizeCouncilTarget(data_get($peer->modelVersion->metadata, 'portfolio_research_contract.target_volatility'), ['high_volatility', 'normal_volatility', 'low_volatility']),
                    'target_direction' => $this->normalizeCouncilTarget(data_get($peer->modelVersion->metadata, 'portfolio_research_contract.target_direction'), ['BUY', 'SELL']),
                    'target_session' => $this->normalizeCouncilTarget(data_get($peer->modelVersion->metadata, 'portfolio_research_contract.target_session'), ['asia', 'london', 'new_york', 'overlap']),
                    'target_venue_phase' => $this->normalizeCouncilTarget(
                        data_get(
                            $peer->modelVersion->metadata,
                            'portfolio_research_contract.target_venue_phase',
                            data_get($peer->modelVersion->metadata, 'specialist_council_membership.contextual_cell.venue_phase'),
                        ),
                        app(MarketSessionCalendarService::class)->researchPhases(),
                    ),
                    'specialist_context_contract' => $this->specialistContextContract(
                        data_get($peer->modelVersion->metadata, 'portfolio_research_contract.contextual_specialist_cell'),
                    ),
                ])->values()->all();
            }
            // M15 entries use the generation-frozen H1 regime. The Python
            // engine delays each H1 state by one H1 bar before merging, so an
            // open H1 candle cannot influence an earlier M15 decision.
            if ($regimeSnapshot !== null) {
                $request['regime_dataset_path'] = $regimeSnapshot['path'];
            }
            $timeout = $this->isCausalLearningConfirmation($agent)
                ? min(960, max(120, (int) config('services.lab_selection.causal_replay_timeout_seconds', 960)))
                : min(3900, max(60, (int) config('services.lab_selection.full_replay_timeout_seconds', 3900)));
            $requestId = 'full-'.$agent->id.'-'.bin2hex(random_bytes(6));
            $request = $this->bindCouncilEvaluationRequests($request, $cohort->pluck('modelVersion')->all());
            $request = app(ResearchReleaseSealService::class)->bindRequest($run, $request);
            $this->evidence->attachRequest($run, $request, [
                'request_id' => $requestId,
                'dataset_manifest' => $manifest,
                // The Edge passport deliberately owns the immutable H1
                // archive identity while M5/M15/H1/H4 are its temporal
                // execution bundle. Both identities remain in the manifest.
                'data_hash' => $mtfBundle !== null ? (string) $mtfBundle['bundle_hash'] : null,
            ]);
            $this->assertAiReplayHealthy($requestId, $run);
            $response = Http::connectTimeout(15)->timeout($timeout)->withOptions([
                // Keep the transport limit explicit for Windows/cURL too;
                // Http::timeout() maps to this option, but the duplicate
                // declaration makes the bounded contract visible in traces.
                'connect_timeout' => 15,
                'timeout' => $timeout,
                // Guzzle's high-level timeout is not consistently enforced by
                // the Windows cURL handler when a synchronous AI replay is
                // abandoned. Pin the native millisecond limits as well.
                'curl' => [
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_CONNECTTIMEOUT_MS => 15000,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_TIMEOUT_MS => $timeout * 1000,
                ],
            ])->acceptJson()->withHeaders([
                'X-Internal-Token' => (string) config('services.internal_api.token'),
                'X-Lab-Request-Id' => $requestId,
            ])->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/run-all', $request);
            if ($response->failed()) {
                throw new RuntimeException($response->body());
            }
            $rawResponse = $response->json();
            $items = collect($rawResponse['leaderboard'] ?? [])->keyBy('strategy');
            foreach ($cohort as $peer) {
                $peerItem = $items->get($peer->modelVersion->strategy);
                if (! $peerItem) {
                    throw new RuntimeException('Missing cohort lab agent result.');
                }
                $this->attestSpecialistCouncilReplay($peer->modelVersion, $run, (array) ($peerItem['result'] ?? []));
                $peerItem['result'] = array_merge((array) ($peerItem['result'] ?? []), [
                    'data_manifest' => $manifest,
                    'full_replay_runtime_policy' => $runtimePolicy,
                ]);
                $items->put($peer->modelVersion->strategy, $peerItem);
                // Every causal arm is an independent singleton hypothesis.
                // Reusing its full response for another arm would be false
                // evidence, while retaining it in model metadata duplicates
                // several megabytes already sealed by the artifact plane.
                if ($this->isCausalLearningConfirmation($peer)
                    || data_get($peer->modelVersion?->metadata, 'edge_genesis.protocol') === DependencyAwareEdgeGenesisFoundryService::PROTOCOL) {
                    continue;
                }
                $peerModel = $peer->modelVersion;
                $peerModel->update(['metadata' => array_merge($peerModel->metadata ?? [], ['full_validation_batch' => [
                    'protocol' => 'sealed_replay_cache_v2',
                    'generation_id' => $agent->lab_generation_id,
                    'item' => $peerItem,
                    'code_hash' => $currentCodeHash,
                    'parameter_hash' => $this->evidence->parameterHash($peer),
                    'data_hash' => $currentReplayHash,
                    'foundation_data_hash' => (string) ($foundationSnapshot['sha256'] ?? ''),
                    'regime_data_hash' => (string) ($regimeSnapshot['sha256'] ?? ''),
                    'mtf_bundle_hash' => $currentMtfBundleHash,
                    'request_manifest' => $request,
                    'full_replay_runtime_policy' => $runtimePolicy,
                ]])]);
            }
            $item = $items->get($model->strategy);
            if (! $item) {
                throw new RuntimeException('Empty lab agent result.');
            }
        }
        $this->attestSpecialistCouncilReplay($model, $run, (array) ($item['result'] ?? []));
        // Full replay evidence is unusable without the exact canonical
        // execution hash. Never persist a score from a response that omitted
        // the contract or silently changed spread/gap policy.
        $returnedExecutionContract = data_get($item, 'result.execution_contract', data_get($item, 'execution_contract'));
        if (! is_array($returnedExecutionContract)
            || ! app(ExecutionContractService::class)->matches($returnedExecutionContract, $agent->symbol, $this->replayTimeframe($agent))) {
            throw new RuntimeException('FULL_REPLAY_EXECUTION_CONTRACT_MISSING_OR_MISMATCH');
        }
        $fullEvidence = $this->evidence->replayEvidenceCompleteness($run, (array) ($item['result'] ?? []));
        if (! $fullEvidence['complete']) {
            $this->evidence->finishRun($run, 'technical_error', (array) ($item['result'] ?? []), [], [
                'reason_code' => 'INCOMPLETE_LAB_EVIDENCE',
                'evidence_quality' => $fullEvidence,
                'quality_verdict' => 'withheld',
                'promotion_evidence' => false,
            ]);
            throw new RuntimeException('FULL_REPLAY_EVIDENCE_INCOMPLETE: '.implode(',', $fullEvidence['reason_codes']));
        }

        // Close the immutable evidence chain before any mutable projection
        // (performance, ledger, knowledge card or handoff) consumes it.  The
        // knowledge service is intentionally fail-closed and therefore must
        // not be asked to learn from a run that is still marked `started`.
        // This ordering also makes a post-replay projection failure distinct
        // from a missing replay artifact.
        if ($rawResponse !== null) {
            $cohortResultCount = is_array($rawResponse['leaderboard'] ?? null) ? count($rawResponse['leaderboard']) : 0;
            if ($this->isCausalLearningConfirmation($agent) && $cohortResultCount === 1) {
                $this->evidence->recordArtifact($run, 'cohort_response_manifest', [
                    'protocol' => 'singleton_causal_cohort_response_manifest_v1',
                    'cohort_result_count' => 1,
                    'strategy' => (string) data_get($item, 'strategy', $model->strategy),
                    'raw_response_sha256' => hash('sha256', (string) json_encode(
                        $rawResponse,
                        JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
                    )),
                    'evaluation_response_is_authoritative' => true,
                    'promotion_evidence' => false,
                ], [
                    'cohort_result_count' => 1,
                    'source' => 'singleton_causal_full_validation',
                    'full_replay_runtime_policy' => $runtimePolicy,
                ]);
            } else {
                $this->evidence->recordArtifact($run, 'cohort_response', (array) $rawResponse, [
                    'cohort_result_count' => $cohortResultCount,
                    'source' => 'full_validation_cohort',
                    'full_replay_runtime_policy' => $runtimePolicy,
                ]);
            }
        }
        $evidenceResponse = $item['result'] ?? [];
        $this->evidence->finishRun($run, 'completed', $evidenceResponse, [
            'agent_result' => $item['result'] ?? [],
            'cache_hit' => $cacheHit,
            'cohort_result_count' => is_array($rawResponse['leaderboard'] ?? null) ? count($rawResponse['leaderboard']) : 1,
            'full_replay_runtime_policy' => $runtimePolicy,
        ], ['cache_hit' => $cacheHit, 'cohort_generation_id' => $agent->lab_generation_id]);

        DB::transaction(function () use ($agent, $model, $item, $run) {
            // The cohort cache is written through each peer model before this
            // projection transaction. Refresh the current model so the
            // projection update cannot overwrite full_validation_batch,
            // including its runtime-policy and file-hash contract, with a
            // stale pre-replay metadata snapshot.
            $model->refresh();
            $fullResult = $item['result'] ?? [];
            $fullResult['evidence_run_id'] = $run->run_id;
            $fullResult['forward_score'] = $item['forward_score'] ?? 0;
            $fullResult['forward_window_scores'] = $item['forward_window_scores'] ?? [];
            $fullResult['rolling_windows_count'] = $item['rolling_windows_count'] ?? 0;
            $fullResult['train_score'] = $item['train_score'] ?? 0;
            $fullResult['validation_score'] = $item['validation_score'] ?? 0;
            $fullResult['is_overfit'] = $item['is_overfit'] ?? false;
            // Council disagreement is a learning artifact. The full trace is
            // already sealed in the evidence plane; this compact ledger keeps
            // role disagreement queryable without exposing trace data to any
            // promotion selector.
            app(CouncilDisagreementService::class)->recordResult($fullResult, [
                'symbol' => $agent->symbol,
                'timeframe' => $agent->timeframe,
                'family' => $agent->strategy_family,
                'evidence_run_id' => $run->run_id,
            ]);
            $result = $this->evidence->projectionPayload($fullResult);
            $model->update([
                'best_score' => max((float) $model->best_score, (float) $item['score']),
                'best_winrate' => $result['winrate'] ?? 0,
                'best_profit' => $result['net_profit_percent'] ?? 0,
                'best_drawdown' => $result['max_drawdown_percent'] ?? 0,
                'metadata' => $this->mergeRefreshedModelMetadata($model, ['last_result' => $result]),
            ]);
            $this->shadowVetoLedger->record($agent, $result, 'full_replay');
            app(AgentCompetencyTensorService::class)->project($agent->fresh(['modelVersion']), $result, (string) $run->run_id);
            // Preserve the sealed niche contract on the full-replay result.
            // It is used only by the separate portfolio-member gate; it must
            // never be interpreted as standalone forward evidence.
            if ($portfolioContract = data_get($model->metadata, 'portfolio_research_contract')) {
                $result['portfolio_research_contract'] = $portfolioContract;
            }
            // Pass the sealed model instance, not only its runtime strategy
            // label. Multiple generations can use the same family/label;
            // evidence must remain attributed to this exact lab agent.
            $performance = $this->champions->evaluate($model->strategy, $agent->symbol, $agent->timeframe, (int) $item['score'], $result, $model);
            if ((int) $performance->model_version_id !== (int) $model->getKey()) {
                throw new RuntimeException('Full replay evidence attribution mismatch.');
            }
            // Academy cohorts use this existing evaluator, but their
            // multi-arm conclusion belongs to the Academy trial contract.
            // The adapter waits for every immutable arm result and cannot
            // promote a model; it only records a research settlement.
            app(AcademyExperimentMaterializerService::class)->settleOutcome($agent->fresh(['modelVersion', 'generation.agents.modelVersion']));
            // Knowledge-card writes are a learning projection.  A storage
            // fault must remain observable but must never erase or downgrade
            // the immutable replay/gate evidence that just completed.
            try {
                $knowledgeAgent = $agent->fresh(['modelVersion', 'generation']);
                $knowledgePerformance = $performance->fresh();
                $this->knowledge->recordFullReplay(
                    $knowledgeAgent,
                    $knowledgePerformance,
                    [...((array) $knowledgePerformance->metrics), 'evidence_run_id' => $run->run_id],
                    $run->run_id,
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
            $this->blameGraph->sync($performance, $agent, $result);
            $this->handoffs->record($agent->generation, $agent, 'full_validation_completed', 'completed', null, [
                'performance_id' => $performance->id, 'result_hash' => hash('sha256', json_encode($result)),
                'evidence_run_id' => $run->run_id,
            ]);
        });
        $generation = $agent->generation()->with('agents')->first();
        if ($generation->agents->whereIn('lifecycle_status', ['draft', 'queued', 'training', 'full_queued'])->isEmpty()) {
            $bridgeCovered = $generation->agents->contains(fn ($candidate): bool => data_get($candidate->modelVersion?->metadata, 'learning_evolution_directive.protocol') === EvolutionDirectorService::PROTOCOL);
            $closedLoop = app(ClosedLoopGenerationAuditService::class)->assess($generation);
            if ($bridgeCovered && ! $closedLoop['generation_may_close']) {
                $generation->update(['status' => 'learning_settlement_pending']);
                $this->handoffs->record($generation, $agent, 'closed_loop_audit', 'pending', 'CLOSED_LOOP_COVERAGE_INCOMPLETE', $closedLoop);

                return;
            }
            $generation->update(['status' => 'completed', 'completed_at' => now()]);
            $generation = $generation->fresh(['agents']);
            app(LabGenerationReportService::class)->record($generation, 'full_completed');
            if ($generation->agents->whereIn('lifecycle_status', ['forward_validated', 'paper', 'champion'])->isEmpty()) {
                $this->handoffs->noForwardCandidate($generation);
            }
        }
    }

    /**
     * Build one immutable causal fold request containing all three arms.
     * Laravel owns fold durability; Python owns candle replay and metrics.
     *
     * @return array{request:array<string,mixed>,manifest:array<string,mixed>,dataset_hash:string,execution_hash:string,fold_count:int}
     */
    public function causalFoldEnvelope(AgentLearningCausalExperiment $experiment, int $foldIndex): array
    {
        $foldCount = max(1, min(12, (int) config('services.learning_lane.causal_fold_count', 9)));
        if ($foldIndex < 1 || $foldIndex > $foldCount) {
            throw new RuntimeException('CAUSAL_FOLD_INDEX_OUT_OF_RANGE');
        }
        $experiment->loadMissing('generation.agents.modelVersion');
        $generation = $experiment->generation;
        if (! $generation || (int) $generation->id !== (int) $experiment->lab_generation_id) {
            throw new RuntimeException('CAUSAL_FOLD_GENERATION_MISSING');
        }
        $armIds = collect([
            $experiment->guided_agent_id,
            $experiment->blinded_agent_id,
            $experiment->control_agent_id,
        ])->map(fn (mixed $id): int => (int) $id)->filter()->unique()->values();
        $arms = $generation->agents->whereIn('id', $armIds)->sortBy('id')->values();
        if ($armIds->count() !== 3 || $arms->count() !== 3) {
            throw new RuntimeException('CAUSAL_FOLD_THREE_ARM_CONTRACT_INCOMPLETE');
        }
        $representative = $arms->first();
        $runtimeTimeframe = $this->replayTimeframe($representative);
        $mtfBundle = $this->replayMtfBundle($representative);
        $volumeEnabled = $arms->contains(fn (LabAgent $arm): bool => $this->volumeEnabled($arm->modelVersion));
        $foundationSnapshot = $this->datasets->ensureGenerationFoundationSnapshot(
            $generation,
            $volumeEnabled && $mtfBundle === null,
        );
        $paperSnapshot = $this->datasets->ensureGenerationSnapshot($generation, $volumeEnabled);
        $dataset = $mtfBundle !== null
            ? (string) $mtfBundle['entry_dataset_path']
            : (string) $foundationSnapshot['path'];
        $datasetHash = $mtfBundle !== null
            ? (string) $mtfBundle['bundle_hash']
            : (string) $foundationSnapshot['sha256'];
        $manifest = (array) ($foundationSnapshot['manifest'] ?? []);
        $manifest['paper'] = (array) ($paperSnapshot['manifest'] ?? []);
        if ($mtfBundle !== null) {
            $manifest['mtf_foundation_bundle'] = (array) $mtfBundle['manifest'];
            $manifest['mtf_bundle_hash'] = (string) $mtfBundle['bundle_hash'];
            $manifest['mtf_execution_timeframe'] = $runtimeTimeframe;
            $manifest['snapshot_sha256'] = (string) $mtfBundle['bundle_hash'];
        }

        $contracts = $arms->mapWithKeys(function (LabAgent $arm) use ($foldIndex, $foldCount): array {
            $receipt = (array) data_get($arm->modelVersion?->metadata, 'learning_receipt', []);
            $role = (string) data_get($receipt, 'causal_influence', '');
            $cohortRole = (string) data_get($arm->modelVersion?->metadata, 'causal_learning_cohort.role', '');
            if (! in_array($role, ['memory_guided', 'hypothesis_guided', 'causal_repair_guided', 'blinded_counterfactual', 'frozen_control'], true)
                || ! in_array($cohortRole, ['memory_guided', 'hypothesis_guided', 'repair_guided', 'blinded', 'frozen_control'], true)
                || data_get($receipt, 'integrity.valid') !== true) {
                throw new RuntimeException('CAUSAL_FOLD_ARM_RECEIPT_INVALID:'.$arm->id);
            }
            $holding = max(1, (int) config('services.learning_lane.confirmation_maximum_holding_bars', 240));

            return [(string) $arm->id => [
                'protocol' => 'bounded_cold_start_learning_confirmation_v1',
                'execution_mode' => 'durable_single_fold_job',
                'role' => $role,
                'cohort_role' => $cohortRole,
                'causal_intent_id' => data_get($receipt, 'causal_intent_id'),
                'maximum_holding_bars' => $holding,
                'purge_bars' => $holding,
                'embargo_bars' => 1,
                'fold_count' => 1,
                'fold_offset' => $foldIndex - 1,
                'fold_universe_count' => $foldCount,
                'max_rows_per_fold' => (int) config('services.learning_lane.causal_max_rows_per_fold', 4096),
                'per_fold_budget_seconds' => $this->causalPerFoldBudgetSeconds(),
                'audit_trace_rows' => (int) config('services.learning_lane.causal_audit_trace_rows', 512),
                'minimum_trades_per_window' => (int) config('services.learning_lane.causal_minimum_trades_per_window', 8),
                'minimum_powered_windows' => (int) config('services.learning_lane.causal_minimum_powered_windows', 6),
                'minimum_positive_windows' => (int) config('services.learning_lane.causal_minimum_positive_windows', 4),
                'declared_time_stop_candles' => max(0, (int) data_get($arm->modelVersion?->parameters, 'time_stop_candles', 0)),
                'execution_overlay' => 'shared_confirmation_maximum_holding_horizon',
                'admitted' => true,
                'blocker' => null,
                'promotion_evidence' => false,
            ]];
        })->all();
        $executionContract = app(ExecutionContractService::class)->for(
            (string) $experiment->symbol,
            $runtimeTimeframe,
        );
        $request = [
            'symbol' => (string) $experiment->symbol,
            'timeframe' => $runtimeTimeframe,
            'strategy' => 'all',
            'evaluation_mode' => 'replay',
            'strategies' => $arms->map(fn (LabAgent $arm): array => $this->screeningStrategyPayload(
                $arm,
                $runtimeTimeframe,
                $mtfBundle,
                $datasetHash,
            ))->all(),
            'initial_balance' => 10000,
            'risk_per_trade' => 1,
            'dataset_path' => $dataset,
            'replay_dataset_hash' => $datasetHash,
            'volume_context' => $volumeEnabled
                ? $this->volumeContextOrFail((string) $experiment->symbol, $runtimeTimeframe, $mtfBundle ?? $foundationSnapshot)
                : $this->disabledVolumeContext(),
            'policy_context' => [
                'learning_confirmation_contracts' => $contracts,
                'causal_fold_job' => [
                    'protocol' => 'durable_causal_fold_job_v1',
                    'experiment_id' => (int) $experiment->id,
                    'fold_index' => $foldIndex,
                    'fold_count' => $foldCount,
                    'all_three_arms_required' => true,
                    'dataset_manifest' => $manifest,
                    'promotion_evidence' => false,
                ],
                'data_boundary' => [
                    'protocol' => 'pre_2026_training_paper_only_v1',
                    'training_end_exclusive' => '2026-01-01T00:00:00Z',
                    'paper_allowed_for_replay' => false,
                    'paper_allowed_for_mutation' => false,
                    'promotion_evidence' => false,
                ],
            ],
            'execution' => $this->executionAssumptions((string) $experiment->symbol),
            'execution_contract' => $executionContract,
            'mtf_pilot' => app(MultiTimeframePilotService::class)->requestPayload(
                (string) $experiment->symbol,
                $runtimeTimeframe,
                (string) $representative->modelVersion->strategy,
                $datasetHash,
            ),
            'emit_decision_trace' => true,
        ];
        if ($mtfBundle === null) {
            $request['foundation_dataset_path'] = (string) $foundationSnapshot['path'];
        }
        $request = $this->applyMtfReplayBundle($request, $mtfBundle);

        $request = $this->bindCouncilEvaluationRequests($request, $arms->pluck('modelVersion')->all());
        return [
            'request' => $request,
            'manifest' => $manifest,
            'dataset_hash' => $datasetHash,
            'execution_hash' => (string) data_get($executionContract, 'execution_hash', ''),
            'fold_count' => $foldCount,
        ];
    }

    /**
     * Project a Python-aggregated causal arm through the ordinary immutable
     * full-replay settlement boundary. No market data is replayed here.
     *
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>  $aggregateResponse
     * @param  array<string,mixed>  $request
     * @param  array<string,mixed>  $manifest
     */
    public function projectCausalFoldAggregate(
        LabAgent $agent,
        array $item,
        array $aggregateResponse,
        array $request,
        array $manifest,
    ): LabEvaluationRun {
        $agent->loadMissing('modelVersion', 'generation');
        $model = $agent->modelVersion;
        if (! $model || (int) data_get($item, 'lab_agent_id', 0) !== (int) $agent->id) {
            throw new RuntimeException('CAUSAL_FOLD_AGGREGATE_AGENT_IDENTITY_MISMATCH');
        }
        $run = $this->evidence->beginRun($agent, 'full_validation', 'full', [
            'source' => 'causal_fold_aggregate',
            'fold_receipts' => (int) data_get($aggregateResponse, 'received_fold_count', 0),
            'promotion_evidence' => false,
        ]);
        $request = app(ResearchReleaseSealService::class)->bindRequest($run, $request);
        $this->evidence->attachRequest($run, $request, [
            'request_id' => 'causal-fold-aggregate-'.$agent->id.'-'.$run->run_id,
            'dataset_manifest' => $manifest,
            'data_hash' => (string) data_get($request, 'replay_dataset_hash', ''),
            'aggregate_only' => true,
        ]);
        $item['result'] = array_merge((array) data_get($item, 'result', []), [
            'data_manifest' => $manifest,
            'full_replay_runtime_policy' => [
                'protocol' => 'durable_causal_fold_jobs_v1',
                'fold_count' => (int) data_get($aggregateResponse, 'expected_fold_count', 0),
                'atomic_settlement' => true,
                'promotion_evidence' => false,
            ],
        ]);
        $returnedExecutionContract = data_get($item, 'result.execution_contract');
        if (! is_array($returnedExecutionContract)
            || ! app(ExecutionContractService::class)->matches(
                $returnedExecutionContract,
                (string) $agent->symbol,
                $this->replayTimeframe($agent),
            )) {
            throw new RuntimeException('CAUSAL_FOLD_AGGREGATE_EXECUTION_CONTRACT_MISMATCH');
        }
        $fullEvidence = $this->evidence->replayEvidenceCompleteness($run, (array) $item['result']);
        if (! $fullEvidence['complete']) {
            $this->evidence->finishRun($run, 'technical_error', (array) $item['result'], [], [
                'reason_code' => 'INCOMPLETE_CAUSAL_FOLD_AGGREGATE_EVIDENCE',
                'evidence_quality' => $fullEvidence,
                'promotion_evidence' => false,
            ]);
            throw new RuntimeException('CAUSAL_FOLD_AGGREGATE_EVIDENCE_INCOMPLETE:'.implode(',', $fullEvidence['reason_codes']));
        }
        $this->evidence->recordArtifact($run, 'cohort_response', $aggregateResponse, [
            'cohort_result_count' => 3,
            'source' => 'causal_fold_aggregate',
            'promotion_evidence' => false,
        ]);
        $this->evidence->finishRun($run, 'completed', (array) $item['result'], [
            'agent_result' => (array) $item['result'],
            'cache_hit' => false,
            'cohort_result_count' => 3,
            'causal_fold_aggregate' => true,
        ], ['cohort_generation_id' => $agent->lab_generation_id, 'promotion_evidence' => false]);

        DB::transaction(function () use ($agent, $model, $item, $run): void {
            $model->refresh();
            $fullResult = (array) $item['result'];
            $fullResult['evidence_run_id'] = $run->run_id;
            $fullResult['forward_score'] = $item['forward_score'] ?? 0;
            $fullResult['forward_window_scores'] = $item['forward_window_scores'] ?? [];
            $fullResult['rolling_windows_count'] = $item['rolling_windows_count'] ?? 0;
            $fullResult['train_score'] = $item['train_score'] ?? 0;
            $fullResult['validation_score'] = $item['validation_score'] ?? 0;
            $fullResult['is_overfit'] = $item['is_overfit'] ?? false;
            $result = $this->evidence->projectionPayload($fullResult);
            $model->update([
                'best_score' => max((float) $model->best_score, (float) ($item['score'] ?? 0)),
                'best_winrate' => $result['winrate'] ?? 0,
                'best_profit' => $result['net_profit_percent'] ?? 0,
                'best_drawdown' => $result['max_drawdown_percent'] ?? 0,
                'metadata' => $this->mergeRefreshedModelMetadata($model, ['last_result' => $result]),
            ]);
            $this->shadowVetoLedger->record($agent, $result, 'full_replay');
            $performance = $this->champions->evaluate(
                (string) $model->strategy,
                (string) $agent->symbol,
                (string) $agent->timeframe,
                (int) ($item['score'] ?? 0),
                $result,
                $model,
            );
            if ((int) $performance->model_version_id !== (int) $model->getKey()) {
                throw new RuntimeException('Causal fold aggregate attribution mismatch.');
            }
            $this->handoffs->record($agent->generation, $agent, 'full_validation_completed', 'completed', null, [
                'performance_id' => $performance->id,
                'result_hash' => hash('sha256', (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
                'evidence_run_id' => $run->run_id,
                'causal_fold_aggregate' => true,
            ]);
        });

        return $run->fresh();
    }

    /** Fast, pair-local filter. Promotion never happens from this result. */
    public function screen(LabAgent $agent, ?LabEvaluationRun $run = null): void
    {
        $run ??= $this->evidence->beginRun($agent, 'screening', 'incremental', ['source' => 'direct_screen']);
        $agent->load('modelVersion', 'generation');
        $model = $agent->modelVersion;
        if ($this->isUncertaintyAbstain($agent)) {
            $this->completeUncertaintyAbstain($agent, $run);

            return;
        }
        $runtimeTimeframe = $this->replayTimeframe($agent);
        $mtfBundle = $this->replayMtfBundle($agent, false, true);
        $volumeEnabled = $this->volumeEnabled($model);
        // The inexpensive genetic screen is an evolution operation, not a
        // forward/paper observation.  Keep it entirely on the frozen
        // pre-2026 foundation archive.  The generation snapshot is still
        // frozen and recorded here so the later replay can use 2026 only as
        // its independent paper/forward lane.
        $paperSnapshot = $this->datasets->ensureGenerationSnapshot($agent->generation, $volumeEnabled);
        $datasetSnapshot = $this->datasets->ensureGenerationFoundationSnapshot(
            $agent->generation,
            $volumeEnabled && $mtfBundle === null,
        );
        $microProbe = data_get($agent->generation->trigger_context, 'shadow_micro_probe.protocol') === ReplayResourceAdmissionService::PROTOCOL;
        $prospectiveProbe = data_get($agent->modelVersion?->metadata, 'causal_learning_cohort.experiment_kind') === ProspectiveRepairExperimentService::KIND;
        $cleanDiscovery = data_get($mtfBundle, 'manifest.validation_bundle_protocol') === MultiTimeframeSnapshotService::DISCOVERY_BUNDLE_PROTOCOL;
        $screenRows = $cleanDiscovery ? MultiTimeframeSnapshotService::DISCOVERY_EVALUATION_ROWS + MultiTimeframeSnapshotService::DISCOVERY_WARMUP_ROWS : ($microProbe
            ? (int) config('services.ai_service.shadow_micro_probe_max_rows', 512)
            : ($prospectiveProbe
                ? ProspectiveRepairExperimentService::PROBE_POLICY['training_tail_rows']
                    + ProspectiveRepairExperimentService::PROBE_POLICY['warmup_rows'] : 5000));
        $stratifiedHistorical = ! $cleanDiscovery && ! $microProbe
            && data_get($agent->modelVersion?->metadata, 'causal_learning_cohort.experiment_kind') !== ProspectiveRepairExperimentService::KIND;
        $primaryDatasetPath = $mtfBundle !== null
            ? (string) $mtfBundle['entry_dataset_path']
            : (string) $datasetSnapshot['path'];
        $replayDatasetHash = $mtfBundle !== null
            ? (string) $mtfBundle['bundle_hash']
            : (string) $datasetSnapshot['sha256'];
        $rows = $this->datasets->rowsFromSnapshot($primaryDatasetPath, $screenRows);
        if (count($rows) < 500) {
            throw new RuntimeException('Screening uchun yetarli recent candle topilmadi.');
        }
        $regimeSnapshot = $mtfBundle === null && $runtimeTimeframe === 'M15'
            ? $this->datasets->ensureGenerationRegimeSnapshot($agent->generation)
            : null;
        $request = [
            'symbol' => $agent->symbol, 'timeframe' => $runtimeTimeframe,
            'strategy' => $model->strategy, 'evaluation_mode' => 'incremental',
            'strategies' => [$this->screeningStrategyPayload(
                $agent,
                $runtimeTimeframe,
                $mtfBundle,
                $replayDatasetHash,
            )],
            'initial_balance' => 10000,
            // Immutable snapshot-path transport keeps the request/evidence
            // contract intact while removing thousands of candle objects from
            // HTTP JSON. This path is deliberately pre-2026 training data;
            // the 2026 generation snapshot is never a screening input.
            'dataset_path' => $primaryDatasetPath,
            'replay_dataset_hash' => $replayDatasetHash,
            // Normal evolution samples immutable windows across 2005–2025.
            // Micro probes remain deliberately tail-bounded for their strict
            // operational budget.
            'dataset_tail_rows' => $stratifiedHistorical ? null : $screenRows,
            'volume_context' => $volumeEnabled
                ? $this->volumeContextOrFail(
                    $agent->symbol,
                    $runtimeTimeframe,
                    $mtfBundle ?? $datasetSnapshot,
                )
                : $this->disabledVolumeContext(),
            // Screening must rank candidates after the same normal execution
            // costs as full replay; otherwise cheap-turnover strategies are
            // incorrectly promoted into the scarce full-validation cohort.
            'execution' => $this->executionAssumptions($agent->symbol),
            'execution_contract' => app(ExecutionContractService::class)->for($agent->symbol, $runtimeTimeframe),
            'mtf_pilot' => app(MultiTimeframePilotService::class)->requestPayload(
                $agent->symbol,
                $runtimeTimeframe,
                $model->strategy,
                $mtfBundle['bundle_hash'] ?? ($regimeSnapshot['sha256'] ?? null),
            ),
            'policy_context' => [
                'shadow_micro_probe' => $microProbe,
                'trial_ledger' => app(LabTrialLedgerService::class)->selectionContext($agent->symbol, $agent->timeframe),
                'repair_contract' => [
                    'changed_gene' => count((array) $agent->parameter_diff) === 1
                        ? array_key_first((array) $agent->parameter_diff) : null,
                    'repair_attempt' => (int) data_get($model->metadata, 'repair_lineage.attempt', 0),
                    'parent_model_version_id' => $agent->parent_a_model_version_id ?: $agent->parent_b_model_version_id,
                    'parent_model_version_ids' => $this->parentGraphService->ids($agent),
                    'single_gene' => count((array) $agent->parameter_diff) === 1,
                ],
                'historical_stratified_windows' => $stratifiedHistorical ? [
                    'protocol' => 'historical_stratified_windows_v1',
                    'window_count' => 8,
                    'window_rows' => 1500,
                    'source' => 'immutable_pre_2026_foundation',
                ] : [],
                'snapshot_transport' => [
                    'protocol' => 'historical_evolution_paper_forward_split_v1',
                    'training_dataset_path' => $datasetSnapshot['path'],
                    'training_dataset_manifest_path' => $datasetSnapshot['path'].'.manifest.json',
                    'training_dataset_sha256' => $datasetSnapshot['sha256'],
                    'training_tail_rows' => $screenRows,
                    'training_end_exclusive' => '2026-01-01T00:00:00Z',
                    'paper_dataset_path' => $paperSnapshot['path'],
                    'paper_dataset_manifest_path' => $paperSnapshot['path'].'.manifest.json',
                    'paper_dataset_sha256' => $paperSnapshot['sha256'],
                    'paper_start_inclusive' => '2026-01-01T00:00:00Z',
                    'paper_allowed_for_screening' => false,
                    'paper_allowed_for_mutation' => false,
                    'features_shared_per_generation_request' => true,
                ],
            ],
            // Screening facts can influence the next mutation direction, so
            // the same complete trace/ledger contract is required here as in
            // full replay. The bounded Laravel projection still removes the
            // large arrays from mutable model metadata after this response is
            // sealed in the immutable evidence plane.
            'emit_decision_trace' => ! $microProbe,
        ];
        $request = $this->applyMtfReplayBundle($request, $mtfBundle);
        if ($cleanDiscovery) $request = $this->sealCleanDiscoveryWindow($request, $rows, $mtfBundle);
        if ($regimeSnapshot !== null) {
            // Screening and full replay consume the same generation-frozen
            // H1 context. Only the latest bounded tail is sent to screening.
            $request['regime_dataset_path'] = $regimeSnapshot['path'];
            $request['regime_dataset_tail_rows'] = 2000;
            $request['policy_context']['snapshot_transport']['regime_dataset_path'] = $regimeSnapshot['path'];
            $request['policy_context']['snapshot_transport']['regime_dataset_manifest_path'] = $regimeSnapshot['path'].'.manifest.json';
            $request['policy_context']['snapshot_transport']['regime_dataset_sha256'] = $regimeSnapshot['sha256'];
            $request['policy_context']['snapshot_transport']['regime_tail_rows'] = 2000;
        }
        // The HTTP budget must end before the job/worker budget. A timeout
        // becomes evaluation_error, never a retry-derived strategy verdict.
        $isDifferential = $agent->strategy_family === 'differential_router'
            || data_get($model->metadata, 'differential_router_contract') !== null
            || str_contains((string) data_get($model->metadata, 'base_strategy', ''), 'differential_router');
        // Differential screening contains four paired ledgers. Keep its
        // longer transport budget explicit; ordinary screening remains
        // hard-bounded at 930 seconds so the Python worker's 900-second
        // operational budget has a 30-second response margin.
        $screenTimeout = $this->screenTransportTimeout($isDifferential, $cleanDiscovery || $prospectiveProbe);
        $requestId = 'screen-'.$agent->id.'-'.bin2hex(random_bytes(6));
        $manifest = [
            'candle_count' => count($rows),
            'data_hash' => $mtfBundle !== null ? (string) $mtfBundle['bundle_hash'] : $this->evidence->hash($rows),
            'snapshot_sha256' => $mtfBundle !== null ? (string) $mtfBundle['bundle_hash'] : $datasetSnapshot['sha256'],
            'snapshot_protocol' => $mtfBundle !== null ? MultiTimeframeSnapshotService::PROTOCOL : $datasetSnapshot['protocol'],
            'snapshot_generation_id' => $agent->lab_generation_id,
            'data_partition' => [
                'protocol' => 'historical_evolution_paper_forward_split_v1',
                'screening_source' => 'pre_2026_foundation_training',
                'training_end_exclusive' => '2026-01-01T00:00:00Z',
                'paper_source' => 'generation_canonical_snapshot',
                'paper_start_inclusive' => '2026-01-01T00:00:00Z',
                'paper_used_for_screening' => false,
                'paper_used_for_mutation' => false,
                'paper_snapshot_sha256' => $paperSnapshot['sha256'],
            ],
        ];
        if ($mtfBundle !== null) {
            $manifest['mtf_bundle_hash'] = (string) $mtfBundle['bundle_hash'];
            $manifest['mtf_bundle_manifest'] = (array) $mtfBundle['manifest'];
            $manifest['execution_timeframe'] = $runtimeTimeframe;
        }
        if ($cleanDiscovery) $manifest['prospective_probe_window'] = $request['policy_context']['prospective_probe_window'];
        if ($regimeSnapshot !== null) {
            $manifest['regime_snapshot_sha256'] = $regimeSnapshot['sha256'];
            $manifest['regime_snapshot_manifest'] = $regimeSnapshot['manifest'];
        }
        if ($prospectiveProbe) {
            $manifest['prospective_probe_window'] = app(ProspectiveRepairProbeWindowService::class)->seal(
                $rows, $replayDatasetHash, (string) data_get($request, 'execution_contract.execution_hash'),
                (string) data_get($model->metadata, 'causal_learning_cohort.experiment_key', ''),
                ProspectiveRepairExperimentService::PROBE_POLICY['training_tail_rows'],
                ProspectiveRepairExperimentService::PROBE_POLICY['warmup_rows'],
            );
            $request['policy_context']['prospective_probe_window'] = $manifest['prospective_probe_window'];
        }
        $request = $this->bindCouncilEvaluationRequests($request, [$model]);
        $request = app(ResearchReleaseSealService::class)->bindRequest($run, $request);
        $this->evidence->attachRequest($run, $request, ['request_id' => $requestId, 'data_hash' => $manifest['data_hash'], 'dataset_manifest' => $manifest]);
        $this->assertAiReplayHealthy($requestId, $run, true);
        $response = Http::connectTimeout(15)->timeout($screenTimeout)->withOptions([
            // Explicitly bound the cURL transfer on Windows as well as in
            // Laravel's PendingRequest abstraction. A provider/replay hang
            // must become an operational error, never an unbounded queue
            // lease or a strategy verdict.
            'connect_timeout' => 15,
            'timeout' => $screenTimeout,
            'curl' => [
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT_MS => 15000,
                CURLOPT_TIMEOUT => $screenTimeout,
                CURLOPT_TIMEOUT_MS => $screenTimeout * 1000,
            ],
        ])->acceptJson()->withHeaders([
            'X-Internal-Token' => (string) config('services.internal_api.token'),
            'X-Lab-Request-Id' => $requestId,
        ])->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/run-all', $request);
        if ($response->failed()) {
            throw new RuntimeException($response->body());
        }
        $item = data_get($response->json(), 'leaderboard.0');
        if (! $item) {
            throw new RuntimeException('Empty screening result.');
        }
        $result = $item['result'] ?? [];
        $this->attestSpecialistCouncilReplay($model, $run, (array) $result);
        $screenResult = array_merge($result, [
            'forward_score' => $item['forward_score'] ?? $item['score'] ?? 0,
            'train_score' => $item['train_score'] ?? $item['score'] ?? 0,
            'validation_score' => $item['validation_score'] ?? $item['score'] ?? 0,
            'evidence_run_id' => $run->run_id,
            'data_manifest' => $manifest,
        ]);
        if (isset($manifest['prospective_probe_window'])
            && ! app(ProspectiveRepairProbeWindowService::class)->attests(
                (array) $manifest['prospective_probe_window'],
                (array) data_get($result, 'prospective_probe_window_receipt', []),
            )) {
            throw new RuntimeException('PROSPECTIVE_PROBE_WINDOW_RECEIPT_MISMATCH');
        }
        if (filled($manifest['mtf_bundle_hash'] ?? null)) {
            $screenResult['mtf_bundle_hash'] = (string) $manifest['mtf_bundle_hash'];
            $screenResult['mtf_snapshot_manifest'] = (array) ($manifest['mtf_bundle_manifest'] ?? []);
        }
        if ($regimeSnapshot !== null) {
            // Keep the frozen H1 dependency in the bounded screen projection
            // as well as in immutable request metadata. Full selection can
            // therefore reject legacy M15 screens that ran before the closed
            // regime contract was deployed and request a clean rescreen.
            $screenResult['regime_snapshot_sha256'] = $regimeSnapshot['sha256'];
            $screenResult['regime_snapshot_protocol'] = $regimeSnapshot['protocol'];
        }
        $screenResult['execution_contract'] = is_array(data_get($result, 'execution_contract'))
            ? (array) data_get($result, 'execution_contract')
            : app(ExecutionContractService::class)->for($agent->symbol, $runtimeTimeframe);
        $screenEvidence = $this->evidence->replayEvidenceCompleteness($run, $screenResult);
        if (! $screenEvidence['complete']) {
            $this->evidence->finishRun($run, 'technical_error', $screenResult, [], [
                'reason_code' => 'INCOMPLETE_LAB_EVIDENCE',
                'evidence_quality' => $screenEvidence,
                'quality_verdict' => 'withheld',
                'promotion_evidence' => false,
            ]);
            throw new RuntimeException('SCREENING_EVIDENCE_INCOMPLETE: '.implode(',', $screenEvidence['reason_codes']));
        }
        $screenResult = $this->appendDifferentialNoRegressionEvidence(
            $model,
            $screenResult,
            $agent->strategy_family,
            $agent->symbol,
            $agent->timeframe,
        );
        $screenResult['trial_ledger'] = app(LabTrialLedgerService::class)->record(
            $agent, $model, $agent->symbol, $agent->timeframe, 'screening', $screenResult, $run->run_id
        );
        app(CouncilDisagreementService::class)->recordResult($screenResult, [
            'symbol' => $agent->symbol,
            'timeframe' => $agent->timeframe,
            'family' => $agent->strategy_family,
            'evidence_run_id' => $run->run_id,
        ]);
        // An operator may quarantine an invalid cohort while this worker is
        // finishing an already-started replay.  Re-read the mutable status
        // before any gate/handoff projection: the response remains immutable
        // diagnostic evidence, but it must never resurrect the agent.
        $latestLifecycle = (string) LabAgent::query()->whereKey($agent->id)->value('lifecycle_status');
        if (in_array($latestLifecycle, ['quarantined', 'technical_quarantine', 'legacy_quarantine'], true)) {
            $this->evidence->finishRun($run, 'completed', $screenResult, [], [
                'reason_code' => 'TECHNICAL_QUARANTINE_RACE_GUARD',
                'quality_verdict' => 'withheld',
                'promotion_evidence' => false,
            ]);

            return;
        }
        // Keep the complete result for the immutable response artifact, but
        // expose only a bounded projection to gate/learning selectors.
        $screenProjection = $this->evidence->projectionPayload($screenResult);
        // Coverage rescue is a preservation experiment. A non-target identity
        // mismatch invalidates the experiment itself, not the parent edge;
        // quarantine it before any quality gate or mutation learner sees it.
        if (data_get($model->metadata, 'coverage_rescue_contract.protocol') === CoverageRescueAuditService::PROTOCOL
            && data_get($screenResult, 'differential_no_regression.status') !== 'passed') {
            $model->update(['metadata' => array_merge($model->metadata ?? [], ['last_screen_result' => $screenProjection])]);
            $agent->update(['lifecycle_status' => 'technical_quarantine', 'decision_reason' => 'Coverage-rescue differential invariant breached; child quarantined without strategy-quality verdict.']);
            try {
                app(AgentProgressCardService::class)->sync(
                    $agent->fresh(['modelVersion', 'generation']),
                    null,
                    [...$screenProjection, 'evidence_run_id' => $run->run_id],
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
            $this->evidence->recordLifecycle($agent, 'coverage_rescue_invariant_quarantine', [
                'reason_code' => 'COVERAGE_RESCUE_NON_TARGET_IDENTITY_BREACH', 'quality_verdict' => 'withheld',
                'differential_no_regression' => data_get($screenResult, 'differential_no_regression'),
            ], 'screening', $run->run_id, $run->attempt, self::class);
            $this->evidence->finishRun($run, 'completed', $screenResult, [], ['technical_quarantine' => true]);

            return;
        }
        // The fact/gate path is primary. Mutation learning runs through an
        // outbox after it, so a schema or learning-write fault cannot strand
        // the candidate in `screening` or erase its evidence.
        $this->shadowVetoLedger->record($agent, $screenProjection, 'screening');
        $screenDecision = $this->gateDecisions->recordScreening($agent, $screenProjection);
        $model->update(['metadata' => array_merge($model->metadata ?? [], [
            'last_screen_result' => $screenProjection,
            'execution_contract' => $screenResult['execution_contract'],
        ])]);
        $screenedAttributes = [
            'train_score' => $item['train_score'] ?? $item['score'] ?? 0,
            'validation_score' => $item['validation_score'] ?? $item['score'] ?? 0,
            'forward_score' => $item['forward_score'] ?? $item['score'] ?? 0,
            'sample_count' => $result['total_trades'] ?? 0,
            'profit_factor' => $result['profit_factor'] ?? 0,
            'max_drawdown' => $result['max_drawdown_percent'] ?? $result['max_drawdown'] ?? 0,
            'risk_of_ruin' => data_get($result, 'monte_carlo.risk_of_ruin_percent'),
            'decision_reason' => data_get($model->metadata, 'causal_rescue_contract.kind') === 'loss_cooldown_single_gene'
                ? ($screenDecision->decision === 'passed'
                    ? 'Cooldown causal rescue passed its strict screen contract; awaiting global full-validation selection.'
                    : 'Cooldown causal rescue rejected by its strict screen contract; no promotion path opened.')
            : 'Incremental screening completed; awaiting global full-validation selection.',
        ];
        // Compare-and-set closes the final quarantine race between the read
        // above and this projection.  A zero-row update means the mutable
        // lifecycle moved to technical quarantine meanwhile; do not write a
        // screened handoff or enqueue learning from that response.
        $screened = LabAgent::query()
            ->whereKey($agent->id)
            ->whereNotIn('lifecycle_status', ['quarantined', 'technical_quarantine', 'legacy_quarantine'])
            ->update(['lifecycle_status' => 'screened', ...$screenedAttributes]);
        if ($screened !== 1) {
            $this->evidence->finishRun($run, 'completed', $screenResult, [], [
                'reason_code' => 'TECHNICAL_QUARANTINE_RACE_GUARD',
                'quality_verdict' => 'withheld',
                'promotion_evidence' => false,
            ]);

            return;
        }
        $agent->refresh();

        // Close the immutable run before any knowledge-card or screening
        // outbox write. Those consumers may only read a terminal, complete
        // evidence chain; an incomplete response is stopped above and never
        // reaches this point.
        $this->evidence->finishRun($run, 'completed', $screenResult, [
            'screen_decision' => $screenDecision->decision,
            'total_trades' => $result['total_trades'] ?? 0,
            'profit_factor' => $result['profit_factor'] ?? 0,
            'stress_profit_factor' => data_get($result, 'screening_survival.stress_cost_pf'),
        ], ['screen_decision_id' => $screenDecision->id]);

        // Gate/evidence are now closed. Everything below is a retryable
        // learning projection and runs on its own queue, keeping the replay
        // worker's critical path short without allowing a projection to
        // promote or alter immutable evidence.
        try {
            ProcessLabScreeningLearningProjection::dispatch(
                (int) $agent->id,
                (string) $run->run_id,
                (int) $screenDecision->id,
                [...$screenProjection, 'evidence_run_id' => $run->run_id],
            );
        } catch (\Throwable $exception) {
            // A queue/serialization outage must not reopen a completed
            // screening run. Immutable evidence remains complete; the
            // learning projection job can be recovered by its unique queue
            // identity or the legacy outbox command.
            report($exception);
        }

        $this->handoffs->record($agent->generation, $agent, 'screened', 'completed', null, [
            'screen_result_hash' => hash('sha256', json_encode($screenProjection)), 'sample_count' => $agent->sample_count,
            'evidence_run_id' => $run->run_id,
        ]);

        $this->closeScreeningGenerationIfTerminal($agent);
    }

    /**
     * Evaluate a bounded cohort in one HTTP replay.
     *
     * Python receives one immutable snapshot path and builds H1/M15/volume/
     * ATR features once for the request. The response is still split back
     * into one evidence run and one gate decision per agent.
     *
     * @param  array<int, int>  $agentIds
     */
    public function screenBatch(array $agentIds, string $symbol): void
    {
        $ids = array_values(array_unique(array_map('intval', $agentIds)));
        if ($ids === [] || count($ids) > 6) {
            throw new RuntimeException('Screening batch 1–6 agent oralig‘ida bo‘lishi kerak.');
        }
        sort($ids);

        // A stale Redis reservation may contain a bounded batch whose first
        // members already finished before the worker died. Replaying the raw
        // payload must be idempotent: only queued/screening members are
        // eligible for a fresh evidence boundary. Terminal members keep
        // their original run and gate decision.
        $allAgents = LabAgent::query()
            ->with('modelVersion', 'generation')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
        if ($allAgents->count() !== count($ids)) {
            throw new RuntimeException('Screening batch agentlaridan biri topilmadi.');
        }
        $agents = $allAgents
            ->filter(fn (LabAgent $agent): bool => in_array((string) $agent->lifecycle_status, ['queued', 'screening'], true))
            ->values();
        if ($agents->isEmpty()) {
            return;
        }
        $ids = $agents->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $first = $agents->first();
        if (! $first || strtoupper((string) $first->symbol) !== strtoupper($symbol)) {
            throw new RuntimeException('Screening batch symbol contract mos emas.');
        }
        foreach ($agents as $agent) {
            if ((int) $agent->lab_generation_id !== (int) $first->lab_generation_id
                || strtoupper((string) $agent->symbol) !== strtoupper((string) $first->symbol)
                || strtoupper((string) $agent->timeframe) !== strtoupper((string) $first->timeframe)) {
                throw new RuntimeException('Screening batch faqat bitta generation/symbol/timeframe uchun ruxsat etiladi.');
            }
            if (! $agent->modelVersion) {
                throw new RuntimeException("Screening batch model version topilmadi: agent {$agent->id}.");
            }
        }
        // The 15k deadline belongs to one sequential candidate, not a cohort.
        // Refuse stale/direct oversized payloads before local guard runs,
        // snapshots, HTTP or recursive contract splitting. Completed members
        // were already filtered above and retain their original evidence.
        if ($agents->count() > 1 && (($first->generation
            && app(SpecialistCouncilPreparationService::class)->isResearchGeneration($first->generation))
            || $agents->contains(fn (LabAgent $agent): bool => app(ProspectiveRepairProbeWindowService::class)->requiresSingleCandidateScreening(
                (array) ($agent->modelVersion?->metadata ?? []), (string) $agent->generation?->trigger_type,
                (array) ($agent->generation?->trigger_context ?? []))))) {
            throw new RuntimeException('PROSPECTIVE_SCREEN_REQUIRES_SINGLE_CANDIDATE_JOB');
        }
        // A guard seat is a pre-registered WAIT policy, not a strategy replay.
        // Resolve it locally before snapshots, health admission and HTTP so it
        // cannot occupy the scarce replay lane or become a transport failure.
        $abstainAgents = $agents->filter(fn (LabAgent $agent): bool => $this->isUncertaintyAbstain($agent));
        foreach ($abstainAgents as $abstainAgent) {
            $abstainRun = $this->evidence->beginRun($abstainAgent, 'screening', 'policy_guard', [
                'source' => 'bounded_screening_batch_local_abstention',
                'replay_required' => false,
            ]);
            $this->completeUncertaintyAbstain($abstainAgent, $abstainRun);
        }
        $agents = $agents->reject(fn (LabAgent $agent): bool => $this->isUncertaintyAbstain($agent))->values();
        if ($agents->isEmpty()) {
            return;
        }
        $ids = $agents->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $first = $agents->first();
        $generation = $first->generation;
        $probeGroups = $agents->groupBy(fn (LabAgent $arm): string =>
            data_get($arm->modelVersion?->metadata, 'causal_learning_cohort.experiment_kind') === ProspectiveRepairExperimentService::KIND
                ? 'prospective_repair' : 'ordinary');
        if ($probeGroups->count() > 1) {
            foreach ($probeGroups as $probeAgents) $this->screenBatch($probeAgents->pluck('id')->all(), $symbol);
            return;
        }
        $runtimeTimeframe = $this->replayTimeframe($first);
        $mtfBundle = $this->replayMtfBundle($first, false, true);
        $datasetContracts = $agents
            ->map(fn (LabAgent $agent): string => $this->volumeEnabled($agent->modelVersion) ? 'volume' : 'price')
            ->unique()
            ->values();
        if ($datasetContracts->count() > 1) {
            // A stale queue payload may have been assembled before the
            // per-contract batching fix. Split it before any request/run is
            // created; this preserves each agent's immutable snapshot and
            // avoids turning a scheduler race into a strategy error.
            foreach ($agents->groupBy(fn (LabAgent $agent): string => $this->volumeEnabled($agent->modelVersion) ? 'volume' : 'price') as $contractAgents) {
                $this->screenBatch(
                    $contractAgents->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    $symbol,
                );
            }

            return;
        }
        $volumeEnabled = $datasetContracts->first() === 'volume';
        // Batch screening is the same genetic/evolutionary stage as the
        // single-agent route: only pre-2026 history may influence it.  Keep
        // the canonical generation snapshot as a separately sealed paper
        // reference for the later full replay.
        $paperSnapshot = $this->datasets->ensureGenerationSnapshot($generation, $volumeEnabled);
        $datasetSnapshot = $this->datasets->ensureGenerationFoundationSnapshot(
            $generation,
            $volumeEnabled && $mtfBundle === null,
        );
        $microProbe = data_get($generation->trigger_context, 'shadow_micro_probe.protocol') === ReplayResourceAdmissionService::PROTOCOL;
        $prospectiveProbe = data_get($first->modelVersion?->metadata, 'causal_learning_cohort.experiment_kind') === ProspectiveRepairExperimentService::KIND;
        $cleanDiscovery = data_get($mtfBundle, 'manifest.validation_bundle_protocol') === MultiTimeframeSnapshotService::DISCOVERY_BUNDLE_PROTOCOL;
        $screenRows = $cleanDiscovery ? MultiTimeframeSnapshotService::DISCOVERY_EVALUATION_ROWS + MultiTimeframeSnapshotService::DISCOVERY_WARMUP_ROWS : ($microProbe
            ? (int) config('services.ai_service.shadow_micro_probe_max_rows', 512)
            : ($prospectiveProbe
                ? ProspectiveRepairExperimentService::PROBE_POLICY['training_tail_rows']
                    + ProspectiveRepairExperimentService::PROBE_POLICY['warmup_rows'] : 5000));
        $stratifiedHistorical = ! $cleanDiscovery && ! $microProbe
            && ! $agents->contains(fn ($arm) => data_get($arm->modelVersion?->metadata, 'causal_learning_cohort.experiment_kind') === ProspectiveRepairExperimentService::KIND);
        $primaryDatasetPath = $mtfBundle !== null
            ? (string) $mtfBundle['entry_dataset_path']
            : (string) $datasetSnapshot['path'];
        $replayDatasetHash = $mtfBundle !== null
            ? (string) $mtfBundle['bundle_hash']
            : (string) $datasetSnapshot['sha256'];
        $rows = $this->datasets->rowsFromSnapshot($primaryDatasetPath, $screenRows);
        if (count($rows) < 500) {
            throw new RuntimeException('Screening batch uchun yetarli recent candle topilmadi.');
        }
        $regimeSnapshot = $mtfBundle === null && $runtimeTimeframe === 'M15'
            ? $this->datasets->ensureGenerationRegimeSnapshot($generation)
            : null;
        $baseStrategy = $this->schemas->runtimeBaseStrategy(
            $first->modelVersion->strategy,
            data_get($first->modelVersion->metadata, 'base_strategy'),
            $first->strategy_family,
        );
        $strategies = [];
        $repairContracts = [];
        foreach ($agents as $agent) {
            $model = $agent->modelVersion;
            $strategies[] = $this->screeningStrategyPayload(
                $agent,
                $runtimeTimeframe,
                $mtfBundle,
                $replayDatasetHash,
            );
            $repairContracts[(string) $agent->id] = [
                'changed_gene' => count((array) $agent->parameter_diff) === 1
                    ? array_key_first((array) $agent->parameter_diff) : null,
                'repair_attempt' => (int) data_get($model->metadata, 'repair_lineage.attempt', 0),
                'parent_model_version_id' => $agent->parent_a_model_version_id ?: $agent->parent_b_model_version_id,
                'parent_model_version_ids' => $this->parentGraphService->ids($agent),
                'single_gene' => count((array) $agent->parameter_diff) === 1,
            ];
        }
        $request = [
            'symbol' => $first->symbol,
            'timeframe' => $runtimeTimeframe,
            'strategy' => $first->modelVersion->strategy,
            'evaluation_mode' => 'incremental',
            'strategies' => $strategies,
            'initial_balance' => 10000,
            'risk_per_trade' => 1,
            'dataset_path' => $primaryDatasetPath,
            'replay_dataset_hash' => $replayDatasetHash,
            'dataset_tail_rows' => $stratifiedHistorical ? null : $screenRows,
            'volume_context' => $volumeEnabled
                ? $this->volumeContextOrFail(
                    $first->symbol,
                    $runtimeTimeframe,
                    $mtfBundle ?? $datasetSnapshot,
                )
                : $this->disabledVolumeContext(),
            'execution' => $this->executionAssumptions($first->symbol),
            'execution_contract' => app(ExecutionContractService::class)->for($first->symbol, $runtimeTimeframe),
            'mtf_pilot' => app(MultiTimeframePilotService::class)->requestPayload(
                $first->symbol,
                $runtimeTimeframe,
                $first->modelVersion->strategy,
                $mtfBundle['bundle_hash'] ?? ($regimeSnapshot['sha256'] ?? null),
            ),
            'policy_context' => [
                'shadow_micro_probe' => $microProbe,
                'trial_ledger' => app(LabTrialLedgerService::class)->selectionContext($first->symbol, $first->timeframe),
                'repair_contracts' => $repairContracts,
                'historical_stratified_windows' => $stratifiedHistorical ? [
                    'protocol' => 'historical_stratified_windows_v1',
                    'window_count' => 8,
                    'window_rows' => 1500,
                    'source' => 'immutable_pre_2026_foundation',
                ] : [],
                'snapshot_transport' => [
                    'protocol' => 'historical_evolution_paper_forward_split_v1',
                    'training_dataset_path' => $datasetSnapshot['path'],
                    'training_dataset_manifest_path' => $datasetSnapshot['path'].'.manifest.json',
                    'training_dataset_sha256' => $datasetSnapshot['sha256'],
                    'training_tail_rows' => $screenRows,
                    'training_end_exclusive' => '2026-01-01T00:00:00Z',
                    'paper_dataset_path' => $paperSnapshot['path'],
                    'paper_dataset_manifest_path' => $paperSnapshot['path'].'.manifest.json',
                    'paper_dataset_sha256' => $paperSnapshot['sha256'],
                    'paper_start_inclusive' => '2026-01-01T00:00:00Z',
                    'paper_allowed_for_screening' => false,
                    'paper_allowed_for_mutation' => false,
                    'features_shared_per_generation_request' => true,
                    'bounded_batch_size' => count($agents),
                ],
            ],
            // The canonical per-candidate result keeps its full decision
            // trace. Cost/mutation projections turn this off in Python.
            'emit_decision_trace' => ! $microProbe,
        ];
        $request = $this->applyMtfReplayBundle($request, $mtfBundle);
        if ($cleanDiscovery) $request = $this->sealCleanDiscoveryWindow($request, $rows, $mtfBundle);
        if ($regimeSnapshot !== null) {
            $request['regime_dataset_path'] = $regimeSnapshot['path'];
            $request['regime_dataset_tail_rows'] = 2000;
            $request['policy_context']['snapshot_transport']['regime_dataset_path'] = $regimeSnapshot['path'];
            $request['policy_context']['snapshot_transport']['regime_dataset_manifest_path'] = $regimeSnapshot['path'].'.manifest.json';
            $request['policy_context']['snapshot_transport']['regime_dataset_sha256'] = $regimeSnapshot['sha256'];
            $request['policy_context']['snapshot_transport']['regime_tail_rows'] = 2000;
        }

        $requestId = 'screen-batch-'.implode('-', $ids).'-'.bin2hex(random_bytes(6));
        // Admission happens before agent status or immutable attempt runs are
        // opened. An occupied but healthy lane is a queue delay, not missing
        // evidence and not a technical strategy outcome.
        $this->assertAiReplayHealthy($requestId, null, true);

        LabAgent::query()->whereIn('id', $ids)->where('lifecycle_status', 'queued')
            ->update(['lifecycle_status' => 'screening']);
        foreach ($agents as $agent) {
            $agent->lifecycle_status = 'screening';
        }

        $manifest = [
            'candle_count' => count($rows),
            'data_hash' => $mtfBundle !== null ? (string) $mtfBundle['bundle_hash'] : $this->evidence->hash($rows),
            'dataset_contract' => $volumeEnabled ? 'volume' : 'price',
            'snapshot_sha256' => $mtfBundle !== null ? (string) $mtfBundle['bundle_hash'] : $datasetSnapshot['sha256'],
            'snapshot_protocol' => $mtfBundle !== null ? MultiTimeframeSnapshotService::PROTOCOL : $datasetSnapshot['protocol'],
            'snapshot_generation_id' => $first->lab_generation_id,
            'data_partition' => [
                'protocol' => 'historical_evolution_paper_forward_split_v1',
                'screening_source' => 'pre_2026_foundation_training',
                'training_end_exclusive' => '2026-01-01T00:00:00Z',
                'paper_source' => 'generation_canonical_snapshot',
                'paper_start_inclusive' => '2026-01-01T00:00:00Z',
                'paper_used_for_screening' => false,
                'paper_used_for_mutation' => false,
                'paper_snapshot_sha256' => $paperSnapshot['sha256'],
            ],
            'batch_protocol' => 'bounded_screening_batch_v1',
            'batch_size' => count($agents),
        ];
        if ($mtfBundle !== null) {
            $manifest['mtf_bundle_hash'] = (string) $mtfBundle['bundle_hash'];
            $manifest['mtf_bundle_manifest'] = (array) $mtfBundle['manifest'];
            $manifest['execution_timeframe'] = $runtimeTimeframe;
        }
        if ($cleanDiscovery) $manifest['prospective_probe_window'] = $request['policy_context']['prospective_probe_window'];
        if ($regimeSnapshot !== null) {
            $manifest['regime_snapshot_sha256'] = $regimeSnapshot['sha256'];
            $manifest['regime_snapshot_manifest'] = $regimeSnapshot['manifest'];
        }
        if ($prospectiveProbe) {
            $experimentKeys = $agents->map(fn (LabAgent $arm): string => (string) data_get(
                $arm->modelVersion?->metadata, 'causal_learning_cohort.experiment_key', ''
            ))->unique();
            if ($experimentKeys->count() !== 1) {
                throw new RuntimeException('PROSPECTIVE_PROBE_MIXED_EXPERIMENT_KEYS');
            }
            $manifest['prospective_probe_window'] = app(ProspectiveRepairProbeWindowService::class)->seal(
                $rows, $replayDatasetHash, (string) data_get($request, 'execution_contract.execution_hash'),
                $experimentKeys->first(),
                ProspectiveRepairExperimentService::PROBE_POLICY['training_tail_rows'],
                ProspectiveRepairExperimentService::PROBE_POLICY['warmup_rows'],
            );
            $request['policy_context']['prospective_probe_window'] = $manifest['prospective_probe_window'];
        }
        $request = $this->bindCouncilEvaluationRequests($request, $agents->pluck('modelVersion')->all());
        $runs = [];
        foreach ($agents as $agent) {
            $run = $this->evidence->beginRun($agent, 'screening', 'incremental', [
                'source' => 'bounded_screening_batch',
                'batch_size' => count($agents),
                'batch_agent_ids' => $ids,
            ]);
            $runs[(int) $agent->id] = $run;
            $request = app(ResearchReleaseSealService::class)->bindRequest($run, $request);
            $requestId = 'screen-batch-'.$agent->id.'-'.bin2hex(random_bytes(5));
            $this->evidence->attachRequest($run, $request, [
                'request_id' => $requestId,
                'data_hash' => $manifest['data_hash'],
                'dataset_manifest' => $manifest,
            ]);
        }
        $isDifferential = $agents->contains(fn (LabAgent $agent): bool => $agent->strategy_family === 'differential_router'
            || data_get($agent->modelVersion->metadata, 'differential_router_contract') !== null);
        $configuredTimeout = (int) config('services.lab_queue.screening_batch_timeout_seconds', 1800);
        $screenTimeout = min($isDifferential ? 2400 : 1800, max(60, $configuredTimeout));
        try {
            $response = Http::connectTimeout(15)->timeout($screenTimeout)->withOptions([
                'connect_timeout' => 15,
                'timeout' => $screenTimeout,
                'curl' => [
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_CONNECTTIMEOUT_MS => 15000,
                    CURLOPT_TIMEOUT => $screenTimeout,
                    CURLOPT_TIMEOUT_MS => $screenTimeout * 1000,
                ],
            ])->acceptJson()->withHeaders([
                'X-Internal-Token' => (string) config('services.internal_api.token'),
                'X-Lab-Request-Id' => $requestId,
            ])->post(rtrim(config('services.ai_service.url'), '/').'/api/backtest/run-all', $request);
            if ($response->status() === 429) {
                throw new ReplayLaneBusyException('AI replay lane is busy; bounded screening batch deferred.');
            }
            if ($response->failed()) {
                throw new RuntimeException($response->body());
            }
            $leaderboard = data_get($response->json(), 'leaderboard', []);
            if (! is_array($leaderboard)) {
                throw new RuntimeException('Empty screening batch result.');
            }
        } catch (ReplayLaneBusyException $exception) {
            foreach ($runs as $agentId => $run) {
                $this->evidence->finishRun($run, 'retry_released', null, [], [
                    'reason_code' => 'REPLAY_LANE_CONTENTION',
                    'batch_protocol' => 'bounded_screening_batch_v1',
                    'quality_verdict' => 'withheld',
                    'promotion_evidence' => false,
                ]);
                LabAgent::query()->whereKey($agentId)->where('lifecycle_status', 'screening')
                    ->update([
                        'lifecycle_status' => 'queued',
                        'decision_reason' => 'AI replay lane contention; bounded screening batch released for retry. Strategy verdict remains withheld.',
                    ]);
            }

            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->batchInterruptedByHostSuspend($exception, collect($runs), $screenTimeout)) {
                foreach ($runs as $agentId => $run) {
                    $this->evidence->finishRun($run, 'retry_released', null, [], [
                        'reason_code' => 'HOST_SUSPEND_OR_LONG_STALL',
                        'elapsed_seconds' => $run->started_at?->diffInSeconds(now()),
                        'transport_timeout_seconds' => $screenTimeout,
                        'batch_protocol' => 'bounded_screening_batch_v1',
                        'strategy_verdict' => 'withheld',
                        'promotion_evidence' => false,
                    ]);
                    LabAgent::query()->whereKey($agentId)->where('lifecycle_status', 'screening')
                        ->update([
                            'lifecycle_status' => 'queued',
                            'decision_reason' => 'Host suspend or abnormal wall-clock stall detected; sealed batch replay released for retry.',
                        ]);
                }

                throw new ReplayLaneBusyException('Host suspend interrupted the bounded screening batch; released for retry.', 0, $exception);
            }
            foreach ($runs as $agentId => $run) {
                $this->evidence->finishRun($run, 'technical_error', null, [], [
                    'reason_code' => 'BATCH_REPLAY_TRANSPORT_FAILURE',
                    'batch_protocol' => 'bounded_screening_batch_v1',
                    'quality_verdict' => 'withheld',
                    'promotion_evidence' => false,
                ], $exception);
                LabAgent::query()->whereKey($agentId)->whereIn('lifecycle_status', ['queued', 'screening'])
                    ->update(['lifecycle_status' => 'evaluation_error', 'decision_reason' => 'Bounded screening batch transport failed; strategy verdict withheld.']);
            }
            // All attempts above are terminal technical evidence. Do not let
            // a queue retry create a second batch of runs for the same
            // immutable cohort; the recovery command can explicitly reopen
            // these agent IDs after the transport is healthy.
            report($exception);

            return;
        }

        $unused = array_values($leaderboard);
        foreach ($agents as $agent) {
            $candidate = null;
            foreach ($unused as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $itemAgentId = data_get($item, 'lab_agent_id');
                if ($itemAgentId !== null && (int) $itemAgentId !== (int) $agent->id) {
                    continue;
                }
                if ((string) data_get($item, 'strategy', '') !== (string) $agent->modelVersion->strategy) {
                    continue;
                }
                // New batch responses carry the stable agent identity above.
                // For legacy responses/caches, strategy is the sealed cohort
                // identity; Python may normalize parameter defaults, so an
                // exact PHP-array comparison would falsely quarantine a valid
                // result after a successful replay.
                $itemVersion = (string) data_get($item, 'version', '');
                if ($itemVersion !== '' && $itemVersion !== (string) $agent->modelVersion->version) {
                    continue;
                }
                $candidate = $item;
                unset($unused[$index]);
                break;
            }
            $run = $runs[(int) $agent->id];
            if (! is_array($candidate)) {
                $error = new RuntimeException("Batch result agent {$agent->id} uchun topilmadi.");
                $this->evidence->finishRun($run, 'technical_error', null, [], [
                    'reason_code' => 'BATCH_RESULT_IDENTITY_MISMATCH',
                    'quality_verdict' => 'withheld',
                    'promotion_evidence' => false,
                ], $error);
                LabAgent::query()->whereKey($agent->id)->whereIn('lifecycle_status', ['queued', 'screening'])
                    ->update(['lifecycle_status' => 'evaluation_error', 'decision_reason' => 'Batch result identity mismatch; strategy verdict withheld.']);

                continue;
            }
            try {
                $this->persistScreenBatchItem($agent, $run, $candidate, $manifest, $regimeSnapshot);
            } catch (\Throwable $exception) {
                if ((string) $run->fresh()->status !== 'technical_error' && (string) $run->fresh()->status !== 'completed') {
                    $this->evidence->finishRun($run, 'technical_error', null, [], [
                        'reason_code' => 'BATCH_ITEM_PROJECTION_FAILURE',
                        'quality_verdict' => 'withheld',
                        'promotion_evidence' => false,
                    ], $exception);
                }
                LabAgent::query()->whereKey($agent->id)->whereIn('lifecycle_status', ['queued', 'screening'])
                    ->update(['lifecycle_status' => 'evaluation_error', 'decision_reason' => 'Batch item evidence projection failed; strategy verdict withheld.']);
                report($exception);
            }
        }
    }

    private function batchInterruptedByHostSuspend(
        \Throwable $exception,
        Collection $runs,
        int $transportTimeoutSeconds,
    ): bool {
        if (! $exception instanceof ConnectionException
            || ! str_contains(strtolower($exception->getMessage()), 'curl error 28')) {
            return false;
        }

        $startedAt = $runs->pluck('started_at')->filter()->min();

        return $startedAt !== null
            && $startedAt->diffInSeconds(now()) > (max(60, $transportTimeoutSeconds) + 300);
    }

    /**
     * Keep proof arms closed while allowing the unused seats of the same
     * twenty-agent generation to run independent paired discovery. Cached
     * replay evidence is valid only inside the active proof protocol.
     *
     * @param  Collection<int,LabAgent>  $cohort
     * @return Collection<int,LabAgent>
     */
    private function closedResearchCohort(Collection $cohort, ModelVersion $model, LabAgent $fallback): Collection
    {
        $protocols = [
            ['edge_genesis.protocol', DependencyAwareEdgeGenesisFoundryService::PROTOCOL],
            ['skill_cartridge_transplant.protocol', CanonicalSkillCartridgeService::PROTOCOL],
            ['skill_cartridge_interaction.protocol', CanonicalSkillCartridgeService::PROTOCOL],
            ['academy_experiment.protocol', AcademyExperimentMaterializerService::PROTOCOL],
            ['authority_incubator.protocol', EvolutionaryAuthorityFoundryService::PROTOCOL],
            ['authority_descendant.protocol', EvolutionaryAuthorityFoundryService::PROTOCOL],
        ];
        foreach ($protocols as [$path, $protocol]) {
            if (data_get($model->metadata, $path) !== $protocol) {
                continue;
            }
            $closed = $cohort->filter(fn (LabAgent $peer): bool => data_get($peer->modelVersion?->metadata, $path) === $protocol
            )->values();

            return $closed->isEmpty() ? collect([$fallback]) : $closed;
        }

        return $cohort;
    }

    private function volumeEnabled($model): bool
    {
        // The no-volume control in a volume council still replays the
        // canonical volume snapshot with volume_lane=none.  This keeps the
        // control and every child on one immutable dataset hash; the lane
        // remains disabled, so volume cannot alter the control signal.
        $metadata = (array) ($model?->metadata ?? []);

        return data_get($metadata, 'volume_research_contract.protocol') === 'volume_council_v1'
            || (bool) data_get($metadata, 'volume_research_contract.enabled', false)
            || (bool) data_get($metadata, 'risk_bounded_evolution.volume_shadow', false)
            || (bool) data_get($metadata, 'portfolio_council_lane.volume_shadow', false)
            || data_get($metadata, 'portfolio_council_lane.role') === 'volume_m15_specialist'
            || data_get($metadata, 'portfolio_council_lane.specialist_role') === 'volume_m15_specialist'
            || data_get($model?->parameters, 'volume_lane', 'none') !== 'none';
    }

    private function isUncertaintyAbstain(LabAgent $agent): bool
    {
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);

        return data_get($metadata, 'uncertainty_abstain_contract.protocol')
                === CooperativeContextualEvolutionCouncilService::UNCERTAINTY_ABSTAIN_PROTOCOL
            || ((string) data_get($metadata, 'generation_target') === 'uncertainty_abstain'
                && data_get($metadata, 'mutation_constructor_invariant.control_only') === true
                && count((array) $agent->parameter_diff) === 0);
    }

    private function completeUncertaintyAbstain(LabAgent $agent, LabEvaluationRun $run): void
    {
        $agent->loadMissing('modelVersion', 'generation');
        $model = $agent->modelVersion;
        if (! $model || ! $this->isUncertaintyAbstain($agent)) {
            throw new RuntimeException('UNCERTAINTY_ABSTAIN_CONTRACT_REQUIRED');
        }

        $contract = (array) data_get($model->metadata, 'uncertainty_abstain_contract', []);
        if ($contract === []) {
            // Backward-compatible closure for a seat constructed before the
            // explicit contract was introduced. Its immutable target and
            // zero-diff constructor invariant are checked above.
            $contract = [
                'protocol' => CooperativeContextualEvolutionCouncilService::UNCERTAINTY_ABSTAIN_PROTOCOL,
                'status' => 'sealed_legacy_projection',
                'action' => 'WAIT',
                'replay_required' => false,
                'causal_credit_allowed' => false,
                'economic_credit_allowed' => false,
                'promotion_evidence' => false,
            ];
        }
        $result = [
            'protocol' => CooperativeContextualEvolutionCouncilService::UNCERTAINTY_ABSTAIN_PROTOCOL,
            'status' => 'correctly_abstained',
            'decision' => 'WAIT',
            'reason_code' => 'UNCERTAINTY_GUARD_WAIT',
            'replay_required' => false,
            'replay_performed' => false,
            'total_trades' => 0,
            'instrument_activation_count' => 0,
            'causal_credit_allowed' => false,
            'economic_credit_allowed' => false,
            'performance_credit' => 0,
            'promotion_evidence' => false,
            'evidence_run_id' => $run->run_id,
            'execution_contract' => app(ExecutionContractService::class)->for($agent->symbol, $this->replayTimeframe($agent)),
            'contract' => $contract,
        ];
        $request = [
            'protocol' => CooperativeContextualEvolutionCouncilService::UNCERTAINTY_ABSTAIN_PROTOCOL,
            'action' => 'WAIT',
            'replay_required' => false,
            'agent_id' => (int) $agent->id,
            'generation_id' => (int) $agent->lab_generation_id,
        ];
        $this->evidence->attachRequest($run, $request, [
            'request_id' => 'local-abstain-'.$agent->id.'-'.$run->attempt,
            'data_hash' => hash('sha256', json_encode($request, JSON_UNESCAPED_SLASHES)),
            'dataset_manifest' => ['protocol' => 'no_dataset_policy_guard_v1', 'replay_required' => false],
        ]);
        $projection = $this->evidence->projectionPayload($result);
        $metadata = (array) $model->metadata;
        $metadata['uncertainty_abstain_contract'] = [...$contract,
            'terminal_status' => 'correctly_abstained',
            'terminal_evidence_run_id' => $run->run_id,
        ];
        $metadata['last_screen_result'] = $projection;
        $model->update(['metadata' => $metadata]);
        $agent->update([
            'lifecycle_status' => 'screened',
            'train_score' => 0,
            'validation_score' => 0,
            'forward_score' => 0,
            'sample_count' => 0,
            'profit_factor' => 0,
            'max_drawdown' => 0,
            'risk_of_ruin' => 0,
            'decision_reason' => 'Uncertainty guard correctly abstained locally; WAIT required and no replay or learning credit was created.',
        ]);
        $this->evidence->recordLifecycle($agent, 'uncertainty_guard_abstained', [
            'reason_code' => 'UNCERTAINTY_GUARD_WAIT',
            'replay_performed' => false,
            'promotion_evidence' => false,
        ], 'screening', $run->run_id, $run->attempt, self::class);
        $this->evidence->finishRun($run, 'completed', $result, [
            'decision' => 'WAIT',
            'total_trades' => 0,
            'instrument_activation_count' => 0,
        ], [
            'reason_code' => 'UNCERTAINTY_GUARD_WAIT',
            'correctly_abstained' => true,
            'replay_performed' => false,
            'promotion_evidence' => false,
        ]);
        // WAIT is terminal for this guard. Settle its canonical learning
        // episode with an explicit zero-credit receipt so the generation is
        // not held open by a decision that intentionally has no replay.
        app(UncertaintyAbstentionSettlementService::class)->settle(
            $agent->fresh(['modelVersion']),
        );
        $this->handoffs->record($agent->generation, $agent->fresh(), 'screened', 'completed', 'UNCERTAINTY_GUARD_WAIT', [
            'correctly_abstained' => true,
            'decision' => 'WAIT',
            'replay_performed' => false,
            'evidence_run_id' => $run->run_id,
            'promotion_evidence' => false,
        ]);

        $this->closeScreeningGenerationIfTerminal($agent);
    }

    private function closeScreeningGenerationIfTerminal(LabAgent $agent): void
    {
        $generation = $agent->generation()->with('agents')->first();
        if (! $generation || $generation->agents->whereIn('lifecycle_status', [
            'draft', 'queued', 'screening', 'evaluation_error',
            'full_queued', 'full_validation', 'training',
        ])->isNotEmpty()) {
            return;
        }

        // Agent terminality only opens the close attempt. The generation is
        // terminal after its current queue reservation, post-screen learning
        // projection and settlement watermark are all terminal. Closing here
        // directly used to make `completed_at` precede the evidence that the
        // generation was supposed to have learned from.
        app(LabGenerationTerminalBoundaryService::class)->closeIfTerminal($generation);
    }

    /**
     * Persist one item returned by a bounded cohort replay.
     *
     * The method intentionally mirrors the single-agent screen gate path:
     * every child owns a separate run, trace, gate decision and learning job;
     * only the Python feature preparation was shared by the batch request.
     */
    private function persistScreenBatchItem(
        LabAgent $agent,
        LabEvaluationRun $run,
        array $item,
        array $manifest,
        ?array $regimeSnapshot = null,
    ): void {
        $agent->loadMissing('modelVersion', 'generation');
        $model = $agent->modelVersion;
        if (! $model) {
            $this->evidence->finishRun($run, 'technical_error', null, [], [
                'reason_code' => 'MODEL_VERSION_MISSING',
                'quality_verdict' => 'withheld',
                'promotion_evidence' => false,
            ]);
            throw new RuntimeException('Batch screening model version topilmadi.');
        }

        $result = (array) ($item['result'] ?? []);
        $this->attestSpecialistCouncilReplay($model, $run, $result);
        $screenResult = array_merge($result, [
            'forward_score' => $item['forward_score'] ?? $item['score'] ?? 0,
            'train_score' => $item['train_score'] ?? $item['score'] ?? 0,
            'validation_score' => $item['validation_score'] ?? $item['score'] ?? 0,
            'evidence_run_id' => $run->run_id,
            'data_manifest' => $manifest,
        ]);
        if (isset($manifest['prospective_probe_window'])
            && ! app(ProspectiveRepairProbeWindowService::class)->attests(
                (array) $manifest['prospective_probe_window'],
                (array) data_get($result, 'prospective_probe_window_receipt', []),
            )) {
            throw new RuntimeException('PROSPECTIVE_PROBE_WINDOW_RECEIPT_MISMATCH');
        }
        if (filled($manifest['mtf_bundle_hash'] ?? null)) {
            $screenResult['mtf_bundle_hash'] = (string) $manifest['mtf_bundle_hash'];
            $screenResult['mtf_snapshot_manifest'] = (array) ($manifest['mtf_bundle_manifest'] ?? []);
        }
        if ($regimeSnapshot !== null) {
            $screenResult['regime_snapshot_sha256'] = $regimeSnapshot['sha256'];
            $screenResult['regime_snapshot_protocol'] = $regimeSnapshot['protocol'];
        }
        $screenResult['execution_contract'] = is_array(data_get($result, 'execution_contract'))
            ? (array) data_get($result, 'execution_contract')
            : app(ExecutionContractService::class)->for($agent->symbol, $this->replayTimeframe($agent));
        $screenEvidence = $this->evidence->replayEvidenceCompleteness($run, $screenResult);
        if (! $screenEvidence['complete']) {
            $this->evidence->finishRun($run, 'technical_error', $screenResult, [], [
                'reason_code' => 'INCOMPLETE_LAB_EVIDENCE',
                'evidence_quality' => $screenEvidence,
                'quality_verdict' => 'withheld',
                'promotion_evidence' => false,
            ]);
            throw new RuntimeException('SCREENING_EVIDENCE_INCOMPLETE: '.implode(',', $screenEvidence['reason_codes']));
        }
        $screenResult = $this->appendDifferentialNoRegressionEvidence(
            $model,
            $screenResult,
            $agent->strategy_family,
            $agent->symbol,
            $agent->timeframe,
        );
        $screenResult['trial_ledger'] = app(LabTrialLedgerService::class)->record(
            $agent,
            $model,
            $agent->symbol,
            $agent->timeframe,
            'screening',
            $screenResult,
            $run->run_id,
        );
        app(CouncilDisagreementService::class)->recordResult($screenResult, [
            'symbol' => $agent->symbol,
            'timeframe' => $agent->timeframe,
            'family' => $agent->strategy_family,
            'evidence_run_id' => $run->run_id,
        ]);

        $latestLifecycle = (string) LabAgent::query()->whereKey($agent->id)->value('lifecycle_status');
        if (in_array($latestLifecycle, ['quarantined', 'technical_quarantine', 'legacy_quarantine'], true)) {
            $this->evidence->finishRun($run, 'completed', $screenResult, [], [
                'reason_code' => 'TECHNICAL_QUARANTINE_RACE_GUARD',
                'quality_verdict' => 'withheld',
                'promotion_evidence' => false,
            ]);

            return;
        }

        $screenProjection = $this->evidence->projectionPayload($screenResult);
        if (data_get($model->metadata, 'coverage_rescue_contract.protocol') === CoverageRescueAuditService::PROTOCOL
            && data_get($screenResult, 'differential_no_regression.status') !== 'passed') {
            $model->update(['metadata' => array_merge($model->metadata ?? [], ['last_screen_result' => $screenProjection])]);
            $agent->update([
                'lifecycle_status' => 'technical_quarantine',
                'decision_reason' => 'Coverage-rescue differential invariant breached; child quarantined without strategy-quality verdict.',
            ]);
            try {
                app(AgentProgressCardService::class)->sync(
                    $agent->fresh(['modelVersion', 'generation']),
                    null,
                    [...$screenProjection, 'evidence_run_id' => $run->run_id],
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
            $this->evidence->recordLifecycle($agent, 'coverage_rescue_invariant_quarantine', [
                'reason_code' => 'COVERAGE_RESCUE_NON_TARGET_IDENTITY_BREACH',
                'quality_verdict' => 'withheld',
                'differential_no_regression' => data_get($screenResult, 'differential_no_regression'),
            ], 'screening', $run->run_id, $run->attempt, self::class);
            $this->evidence->finishRun($run, 'completed', $screenResult, [], ['technical_quarantine' => true]);

            return;
        }

        $this->shadowVetoLedger->record($agent, $screenProjection, 'screening');
        $screenDecision = $this->gateDecisions->recordScreening($agent, $screenProjection);
        $model->update(['metadata' => array_merge($model->metadata ?? [], [
            'last_screen_result' => $screenProjection,
            'execution_contract' => $screenResult['execution_contract'],
        ])]);
        $screenedAttributes = [
            'train_score' => $item['train_score'] ?? $item['score'] ?? 0,
            'validation_score' => $item['validation_score'] ?? $item['score'] ?? 0,
            'forward_score' => $item['forward_score'] ?? $item['score'] ?? 0,
            'sample_count' => $result['total_trades'] ?? 0,
            'profit_factor' => $result['profit_factor'] ?? 0,
            'max_drawdown' => $result['max_drawdown_percent'] ?? $result['max_drawdown'] ?? 0,
            'risk_of_ruin' => data_get($result, 'monte_carlo.risk_of_ruin_percent'),
            'decision_reason' => data_get($model->metadata, 'causal_rescue_contract.kind') === 'loss_cooldown_single_gene'
                ? ($screenDecision->decision === 'passed'
                    ? 'Cooldown causal rescue passed its strict screen contract; awaiting global full-validation selection.'
                    : 'Cooldown causal rescue rejected by its strict screen contract; no promotion path opened.')
                : 'Incremental screening batch completed; awaiting global full-validation selection.',
        ];
        $screened = LabAgent::query()
            ->whereKey($agent->id)
            ->whereNotIn('lifecycle_status', ['quarantined', 'technical_quarantine', 'legacy_quarantine'])
            ->update(['lifecycle_status' => 'screened', ...$screenedAttributes]);
        if ($screened !== 1) {
            $this->evidence->finishRun($run, 'completed', $screenResult, [], [
                'reason_code' => 'TECHNICAL_QUARANTINE_RACE_GUARD',
                'quality_verdict' => 'withheld',
                'promotion_evidence' => false,
            ]);

            return;
        }
        $agent->refresh();
        $this->evidence->finishRun($run, 'completed', $screenResult, [
            'screen_decision' => $screenDecision->decision,
            'total_trades' => $result['total_trades'] ?? 0,
            'profit_factor' => $result['profit_factor'] ?? 0,
            'stress_profit_factor' => data_get($result, 'screening_survival.stress_cost_pf'),
        ], ['screen_decision_id' => $screenDecision->id]);

        try {
            ProcessLabScreeningLearningProjection::dispatch(
                (int) $agent->id,
                (string) $run->run_id,
                (int) $screenDecision->id,
                [...$screenProjection, 'evidence_run_id' => $run->run_id],
            );
        } catch (\Throwable $exception) {
            report($exception);
        }

        $this->handoffs->record($agent->generation, $agent, 'screened', 'completed', null, [
            'screen_result_hash' => hash('sha256', json_encode($screenProjection)),
            'sample_count' => $agent->sample_count,
            'evidence_run_id' => $run->run_id,
            'batch_protocol' => 'bounded_screening_batch_v1',
        ]);

        $this->closeScreeningGenerationIfTerminal($agent);
    }

    /**
     * Cohort cache rows are written before the current agent's projection.
     * Refresh before merging projection metadata so the later update cannot
     * erase full_validation_batch and its immutable runtime-policy contract.
     */
    private function mergeRefreshedModelMetadata(ModelVersion $model, array $patch): array
    {
        $model->refresh();

        return array_merge((array) $model->metadata, $patch);
    }

    /**
     * A causal arm is an atomic experiment unit. Combining two expensive
     * arms in one killable child makes both depend on one wall-clock timeout
     * and loses the first result when the second overruns. Candidate-level
     * immutable caches preserve reuse without coupling their terminal fate.
     */
    private function fullReplayMaxCohortSize(LabAgent $agent): int
    {
        if ($this->isCausalLearningConfirmation($agent) || $this->isEdgeGenesisReplay($agent)) {
            return 1;
        }

        return max(2, (int) config('services.lab_selection.full_replay_max_cohort_size', 2));
    }

    private function isCausalLearningConfirmation(LabAgent $agent): bool
    {
        return $agent->generation?->trigger_type === 'learning_confirmation'
            && in_array((string) data_get($agent->modelVersion?->metadata, 'causal_learning_cohort.role'), [
                'memory_guided', 'hypothesis_guided', 'repair_guided', 'blinded', 'frozen_control',
            ], true);
    }

    private function causalPerFoldBudgetSeconds(): int
    {
        return max(45, min(
            240,
            (int) config('services.learning_lane.causal_per_fold_budget_seconds', 180),
        ));
    }

    /** @return array<string,mixed>|null */
    private function skillCartridgeConfirmationContract(LabAgent $agent): ?array
    {
        $contract = (array) data_get($agent->modelVersion?->metadata, 'skill_cartridge_transplant.confirmation_contract', []);
        if (data_get($agent->modelVersion?->metadata, 'skill_cartridge_transplant.protocol') !== CanonicalSkillCartridgeService::PROTOCOL
            || data_get($contract, 'protocol') !== 'bounded_skill_cartridge_confirmation_v1') {
            return null;
        }

        // Pre-repair cartridges did not seal a per-fold budget and inherited
        // Python's old 90-second default. Their 4096-row fold is unchanged;
        // only the technical guard is restored to the existing bounded 240s
        // runtime ceiling so a ~200s valid fold can return its evidence.
        $contract['per_fold_budget_seconds'] = max(45, min(
            CanonicalSkillCartridgeService::PER_FOLD_BUDGET_SECONDS,
            (int) ($contract['per_fold_budget_seconds'] ?? CanonicalSkillCartridgeService::PER_FOLD_BUDGET_SECONDS),
        ));

        return $contract;
    }

    /**
     * Edge Genesis seats are immutable causal arms. Running two seats in one
     * transport couples their terminal fate and causes every queued seat to
     * replay a still-pending peer again because Edge results deliberately do
     * not use the ordinary cohort cache. Keep one job equal to one arm.
     */
    private function isEdgeGenesisReplay(LabAgent $agent): bool
    {
        return data_get($agent->modelVersion?->metadata, 'edge_genesis.protocol')
            === DependencyAwareEdgeGenesisFoundryService::PROTOCOL;
    }

    /**
     * Add only sealed peers whose full-replay cache is valid for this exact
     * generation and runtime contract. Their item is reused by the Python
     * candidate cache; they are never reopened as lifecycle work here.
     */
    private function mergeSealedCohortPeers(
        $cohort,
        int $generationId,
        string $codeHash,
        string $dataHash,
        string $foundationHash,
        int $foundationRowCount,
        int $foundationThreshold,
        int $maxCohortSize,
    ) {
        if ($dataHash === '' || $foundationHash === '') {
            return $cohort;
        }

        $activeIds = $cohort->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $peers = LabAgent::query()
            ->with('modelVersion')
            ->where('lab_generation_id', $generationId)
            ->whereNotIn('id', $activeIds)
            ->whereNotIn('lifecycle_status', [
                'full_queued', 'training', 'evaluation_error', 'technical_quarantine',
                'quarantined', 'legacy_quarantine',
            ])
            ->orderBy('id')->get()
            ->filter(function (LabAgent $peer) use ($generationId, $codeHash, $dataHash, $foundationHash, $foundationRowCount, $foundationThreshold, $maxCohortSize): bool {
                $model = $peer->modelVersion;
                $cached = data_get($model?->metadata, 'full_validation_batch');
                $policy = (array) data_get($cached, 'full_replay_runtime_policy', []);

                return $model?->evidence_status === 'valid'
                    && (int) data_get($cached, 'generation_id', 0) === $generationId
                    && data_get($cached, 'protocol') === 'sealed_replay_cache_v2'
                    && is_array(data_get($cached, 'item'))
                    && hash_equals($codeHash, (string) data_get($cached, 'code_hash', ''))
                    && hash_equals($this->evidence->parameterHash($peer), (string) data_get($cached, 'parameter_hash', ''))
                    && hash_equals($dataHash, (string) data_get($cached, 'data_hash', ''))
                    && hash_equals($foundationHash, (string) data_get($cached, 'foundation_data_hash', ''))
                    && data_get($policy, 'protocol') === 'full_replay_runtime_budget_v1'
                    && (int) data_get($policy, 'foundation_row_count', -1) === $foundationRowCount
                    && (int) data_get($policy, 'foundation_threshold_rows', -1) === $foundationThreshold
                    && (int) data_get($policy, 'max_cohort_size', -1) === $maxCohortSize;
            });

        return $cohort->concat($peers)->unique(fn (LabAgent $peer): int => (int) $peer->getKey())->sortBy('id')->values();
    }

    /**
     * Build the replay volume context from the replay snapshot itself. Live
     * rolling coverage is a different population and may never attest a
     * pre-2026 historical CSV.
     *
     * @param  array<string,mixed>  $replaySnapshot
     */
    private function volumeContextOrFail(string $symbol, string $timeframe, array $replaySnapshot): array
    {
        $manifest = (array) ($replaySnapshot['manifest'] ?? []);
        $provenance = (array) data_get($manifest, 'volume_provenance', []);
        if (data_get($provenance, 'status') !== 'passed') {
            throw new RuntimeException("{$symbol} {$timeframe} historical replay volume provenance gate failed.");
        }

        $streamQuality = (array) data_get($provenance, 'streams.'.strtoupper($timeframe), []);
        if ($streamQuality !== [] && data_get($streamQuality, 'status') !== 'passed') {
            throw new RuntimeException("{$symbol} {$timeframe} historical replay volume quality gate failed.");
        }
        $snapshotHash = (string) ($replaySnapshot['bundle_hash']
            ?? data_get($manifest, 'snapshot_sha256')
            ?? ($replaySnapshot['sha256'] ?? ''));
        if ($snapshotHash === '') {
            throw new RuntimeException("{$symbol} {$timeframe} historical replay volume snapshot hash missing.");
        }

        $contract = $this->volumes->contract();

        return [
            ...$provenance,
            'protocol' => 'relative_volume_session_v2',
            'status' => 'passed',
            'enabled' => true,
            'requested' => true,
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'source_contract' => (string) data_get(
                $provenance,
                'source_contract',
                MarketVolumeService::HISTORICAL_SOURCE_CONTRACT,
            ),
            'provider' => (string) data_get($provenance, 'provider', 'dukascopy'),
            'transport' => (string) data_get($provenance, 'transport', 'frozen_archive'),
            'unit' => MarketVolumeService::UNIT,
            'semantic' => MarketVolumeService::SEMANTIC,
            'session' => 'UTC',
            'snapshot_sha256' => $snapshotHash,
            'snapshot_quality' => $streamQuality !== [] ? $streamQuality : (array) data_get($manifest, 'volume_quality', []),
            'normalization' => (array) data_get($contract, 'normalization', []),
            'coverage_scope' => 'frozen_historical_replay_snapshot',
            'live_coverage_inherited' => false,
            'promotion_evidence' => false,
        ];
    }

    private function replayTimeframe(LabAgent $agent): string
    {
        return app(MultiTimeframePilotService::class)
            ->replayTimeframe((string) $agent->symbol, (string) $agent->timeframe);
    }

    /** @return array<string,mixed>|null */
    private function replayMtfBundle(LabAgent $agent, bool $edgeGenesisReplay = false, bool $allowDiscovery = false): ?array
    {
        $runtimeTimeframe = $this->replayTimeframe($agent);
        if (! $edgeGenesisReplay && $runtimeTimeframe === strtoupper((string) $agent->timeframe)) {
            return null;
        }
        $manifest = $edgeGenesisReplay
            ? (array) data_get($agent->modelVersion?->metadata, 'edge_genesis.mtf_bundle_manifest', [])
            : (array) data_get($agent->generation?->trigger_context, 'mtf_bundle_manifest', []);
        if ($manifest === []) {
            throw new RuntimeException('AUTONOMOUS_MTF_BUNDLE_MISSING');
        }
        $discovery = ($manifest['validation_bundle_protocol'] ?? null) === MultiTimeframeSnapshotService::DISCOVERY_BUNDLE_PROTOCOL;
        $academyDiscoveryOwner = $agent->generation?->trigger_type === 'academy_experiment'
            && data_get($agent->generation?->trigger_context, 'prospective_source_identity.data_role') === 'pre_2026_discovery_only';
        if ($discovery && (! $allowDiscovery || $edgeGenesisReplay || (! $academyDiscoveryOwner
            && (! $agent->generation || ! app(SpecialistCouncilPreparationService::class)->inspectDiscoveryOwner($agent->generation, $manifest)['allowed'])))) {
            throw new RuntimeException('DISCOVERY_ONLY_BUNDLE_REPLAY_SCOPE_FORBIDDEN');
        }
        $owner = app(MultiTimeframeSnapshotService::class);
        $bundle = $discovery ? $owner->restoreAgentOwnedConfirmationValidationBundle($manifest, true)
            : $owner->restoreAgentOwnedConfirmationValidationBundle($manifest);
        $expectedHash = $edgeGenesisReplay
            ? (string) data_get($agent->modelVersion?->metadata, 'edge_genesis.mtf_bundle_hash', '')
            : (string) data_get($agent->generation?->trigger_context, 'mtf_bundle_hash', '');
        if ($expectedHash === '' || ! hash_equals($expectedHash, (string) $bundle['bundle_hash'])) {
            throw new RuntimeException('AUTONOMOUS_MTF_BUNDLE_IDENTITY_MISMATCH');
        }

        return $bundle;
    }

    /** @param array<string,mixed> $request @param array<string,mixed>|null $bundle @return array<string,mixed> */
    private function applyMtfReplayBundle(array $request, ?array $bundle): array
    {
        if ($bundle === null) {
            return $request;
        }
        $request['dataset_path'] = (string) $bundle['entry_dataset_path'];
        $request['mtf_dataset_paths'] = (array) $bundle['context_dataset_paths'];
        $request['related_mtf_dataset_paths'] = (object) [];
        $request['mtf_snapshot_manifest'] = (array) $bundle['manifest'];
        data_set($request, 'policy_context.snapshot_transport.training_dataset_path', (string) $bundle['entry_dataset_path']);
        data_set($request, 'policy_context.snapshot_transport.training_dataset_manifest_path', (string) $bundle['manifest_path']);
        data_set(
            $request,
            'policy_context.snapshot_transport.training_dataset_sha256',
            (string) data_get($bundle, 'manifest.streams.M5.sha256', ''),
        );
        data_set($request, 'policy_context.snapshot_transport.execution_timeframe', (string) config('services.xauusd_organism.execution_timeframe', 'M5'));
        data_set($request, 'policy_context.snapshot_transport.mtf_dataset_paths', (array) $bundle['context_dataset_paths']);
        data_set($request, 'policy_context.snapshot_transport.mtf_bundle_hash', (string) $bundle['bundle_hash']);

        return $request;
    }

    /** Reuse the actual PHP/Python probe contract; never rely on max-candle hints. */
    private function sealCleanDiscoveryWindow(array $request, array $rows, array $bundle): array
    {
        $scope = (array) data_get($bundle, 'manifest.discovery_scope', []);
        $contract = app(ProspectiveRepairProbeWindowService::class)->seal($rows, (string) $bundle['bundle_hash'],
            (string) data_get($request, 'execution_contract.execution_hash'),
            'academy_clean_discovery:'.$bundle['bundle_hash'].':'.(string) ($scope['scope_hash'] ?? ''),
            MultiTimeframeSnapshotService::DISCOVERY_EVALUATION_ROWS, MultiTimeframeSnapshotService::DISCOVERY_WARMUP_ROWS);
        foreach (['loaded_rows', 'warmup_rows', 'evaluated_rows', 'loaded_start', 'loaded_end',
            'evaluated_start', 'evaluated_end', 'evaluated_month_counts'] as $key) {
            if (($contract[$key] ?? null) !== data_get($scope, 'calendar.'.$key)) throw new RuntimeException('ACADEMY_DISCOVERY_WINDOW_BOUNDS_MISMATCH');
        }
        $request['dataset_tail_rows'] = null;
        $request['policy_context']['historical_stratified_windows'] = [];
        $request['policy_context']['prospective_probe_window'] = $contract;
        $request['policy_context']['prospective_clean_discovery_scope'] = $scope;
        return $request;
    }

    private function screenTransportTimeout(bool $differential, bool $prospectiveWindow): int
    {
        // The 15k child has a 1680s absolute ceiling, below transport and
        // the existing 2100s prospective job lease. Generic screens stay cheap.
        if ($prospectiveWindow) return 1800;
        $configured = $differential ? (int) config('services.lab_selection.differential_screen_timeout_seconds', 900)
            : (int) config('services.lab_selection.screen_timeout_seconds', 300);
        return min($differential ? 900 : 930, max(30, $configured));
    }

    /** Keep the optional no-volume control contract JSON-object shaped. */
    private function disabledVolumeContext(): array
    {
        return [
            'status' => 'not_requested',
            'enabled' => false,
            'promotion_evidence' => false,
        ];
    }

    /** Diagnostic replay is a learning-only re-evaluation; it never creates a full-replay or paper candidate. */
    public function diagnosticReplay(LabAgent $agent): void
    {
        $this->screen($agent);
        $agent->refresh()->load('modelVersion');
        $result = (array) data_get($agent->modelVersion?->metadata, 'last_screen_result', []);
        $this->gateDecisions->recordDiagnosticReplay($agent, $result);
        $agent->update(['decision_reason' => 'Diagnostic rescue replay completed; excluded from promotion evidence.']);
    }

    /**
     * Fail fast when the single Python replay lane is unavailable or already
     * owned by another caller. This is operational containment only; it never
     * writes a gate decision and is recovered through the bounded evaluator
     * recovery command after a clean service restart.
     */
    private function assertAiReplayHealthy(string $requestId, ?LabEvaluationRun $run = null, bool $allowScreeningConcurrency = false): void
    {
        try {
            $response = Http::connectTimeout(3)->timeout(5)->withOptions([
                'connect_timeout' => 3,
                'timeout' => 5,
                'curl' => [
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_CONNECTTIMEOUT_MS => 3000,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_TIMEOUT_MS => 5000,
                ],
            ])->acceptJson()->withHeaders([
                'X-Internal-Token' => (string) config('services.internal_api.token'),
                'X-Lab-Request-Id' => $requestId.'-preflight',
            ])->get(rtrim(config('services.ai_service.url'), '/').'/api/replay-status');
        } catch (\Throwable $error) {
            if ($run) {
                $this->evidence->recordLifecycle($run->agent, 'ai_health_preflight_error', [
                    'request_id' => $requestId, 'request_type' => 'GET /api/replay-status',
                ], $run->phase, $run->run_id, $run->attempt, self::class, $error);
            }
            throw new RuntimeException('AI replay health preflight failed: '.$error->getMessage(), 0, $error);
        }

        if ($run) {
            $this->evidence->recordArtifact($run, 'ai_health_preflight', [
                'request_id' => $requestId, 'http_status' => $response->status(), 'body' => $response->json(),
            ], ['request_type' => 'GET /api/replay-status', 'promotion_evidence' => false]);
        }

        if ($response->failed()) {
            if ($run) {
                $this->evidence->recordLifecycle($run->agent, 'ai_health_preflight_failed', [
                    'request_id' => $requestId, 'http_status' => $response->status(),
                ], $run->phase, $run->run_id, $run->attempt, self::class);
            }
            throw new RuntimeException('AI replay health preflight returned HTTP '.$response->status().'.');
        }
        $status = $response->json();
        if (! is_array($status) || data_get($status, 'protocol') !== 'replay_liveness_v2_bounded_worker') {
            if ($run) {
                $this->evidence->recordLifecycle($run->agent, 'ai_health_protocol_error', [
                    'request_id' => $requestId, 'protocol' => data_get($status, 'protocol'),
                ], $run->phase, $run->run_id, $run->attempt, self::class);
            }
            throw new RuntimeException('AI replay health preflight returned an unknown liveness protocol.');
        }
        $activeRequests = (int) data_get($status, 'active_requests', 0);
        $screeningActive = (int) data_get($status, 'screening_active', 0);
        $screeningCapacity = max(1, (int) data_get($status, 'screening_capacity', 1));
        $fullActive = (int) data_get($status, 'full_active', 0);
        $hasLaneTelemetry = array_key_exists('screening_capacity', $status)
            && array_key_exists('full_active', $status);
        $laneBusy = $allowScreeningConcurrency
            ? ($hasLaneTelemetry
                ? ($fullActive > 0 || $screeningActive >= $screeningCapacity)
                : $activeRequests > 0)
            : $activeRequests > 0;
        if ($laneBusy) {
            if ($run) {
                $this->evidence->recordLifecycle($run->agent, 'ai_health_lane_busy', [
                    'request_id' => $requestId,
                    'active_requests' => $activeRequests,
                    'screening_active' => $screeningActive,
                    'screening_capacity' => $screeningCapacity,
                    'full_active' => $fullActive,
                ], $run->phase, $run->run_id, $run->attempt, self::class);
            }
            throw new ReplayLaneBusyException('AI replay lane is busy; strategy verdict withheld for bounded retry.');
        }
    }

    private function executionAssumptions(string $symbol): array
    {
        return app(ExecutionContractService::class)->parameters($symbol);
    }

    /** Convert Laravel's open-ended research marker (`any`) to Python null. */
    private function normalizeCouncilTarget(mixed $value, array $allowed): ?string
    {
        $candidate = trim((string) $value);
        foreach ($allowed as $option) {
            if (strcasecmp($candidate, (string) $option) === 0) {
                return (string) $option;
            }
        }

        return null;
    }

    /**
     * Python's replay schema requires a JSON object for this contract. PHP's
     * empty array otherwise serializes as `[]`, which is a different JSON
     * type and can quarantine an otherwise valid generation before replay.
     */
    private function specialistContextContract(mixed $value): array|\stdClass
    {
        if ($value === null || $value === []) {
            return new \stdClass;
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('SPECIALIST_CONTEXT_CONTRACT_MUST_BE_OBJECT');
        }

        return $value;
    }

    /** Prospectively bind all three repair arms to their sealed source cell. */
    private function replaySpecialistContextContract(mixed $model): array|\stdClass
    {
        $existing = data_get($model?->metadata, 'specialist_council_membership.contextual_cell');
        $cohort = (array) data_get($model?->metadata, 'causal_learning_cohort', []);
        if (data_get($cohort, 'experiment_kind') !== ProspectiveRepairExperimentService::KIND) {
            return $this->specialistContextContract($existing);
        }

        $scope = (array) data_get($cohort, 'source_context_scope', []);
        $scope = app(ContextContractV2Service::class)->canonicalDeclaredAxes($scope);
        $sealedHash = (string) data_get($cohort, 'source_context_hash', '');
        if (empty($scope['venue_phase']) || $sealedHash === ''
            || ! hash_equals($sealedHash, app(ResearchPaperEpochContractService::class)->parameterHash($scope))) {
            throw new RuntimeException('PROSPECTIVE_REPAIR_CONTEXT_SCOPE_DRIFT');
        }
        if (is_array($existing) && $existing !== []
            && app(ContextContractV2Service::class)->canonicalDeclaredAxes($existing) !== $scope) {
            throw new RuntimeException('PROSPECTIVE_REPAIR_CONTEXT_OWNER_MISMATCH');
        }

        return $this->specialistContextContract([
            'protocol' => 'prospective_repair_exact_context_v1',
            ...$scope,
            'execution_policy' => 'context_owned',
            'source_context_hash' => $sealedHash,
        ]);
    }

    /**
     * Carry a frozen composition across the actual replay boundary. The
     * passport itself is only a declaration; Python must return an exact
     * consumption trace before any component/composition can receive credit.
     *
     * @return array<string,mixed>|\stdClass
     */
    public function compositionRuntimeContract(
        LabAgent $agent,
        ?array $instrumentAssignment = null,
        ?string $runtimeTimeframe = null,
        ?array $mtfBundle = null,
        ?string $replayDatasetHash = null,
    ): array|\stdClass {
        $agent->loadMissing('modelVersion');
        $model = $agent->modelVersion;
        $passport = (array) data_get($model?->metadata, 'smart_composition.composition_passport', []);
        if (! $model
            || (string) data_get($passport, 'protocol') !== CompositionAuthorityKernelService::PROTOCOL
            || ! filled(data_get($passport, 'composition_id'))) {
            return new \stdClass;
        }

        $components = (array) data_get($passport, 'components', []);
        $strategyId = (string) ($components['strategy_id'] ?? '');
        $strategyRuntime = $strategyId !== ''
            ? app(StrategyLibraryCompilerService::class)->runtime($strategyId)
            : null;
        $riskGene = (string) data_get($passport, 'risk_contract.profile.gene', '');
        $parameters = (array) $model->parameters;
        $actualArchitecture = (string) data_get($model->metadata, 'strategy_architecture', '');
        $actualTactic = (string) data_get($model->metadata, 'tactic_contract.architecture', '');
        $declaredTactic = (string) ($components['tactic_id'] ?? '');
        $actualBaseStrategy = $this->schemas->runtimeBaseStrategy(
            (string) $model->strategy,
            data_get($model->metadata, 'base_strategy'),
            (string) $agent->strategy_family,
        );
        $frozenStrategyScope = (array) data_get($passport, 'strategy_signal_scope', []);
        $actualStrategyScope = app(StrategyLibraryCompilerService::class)
            ->signalScope($actualBaseStrategy);
        $strategyScopeBound = $frozenStrategyScope !== []
            && $frozenStrategyScope === $actualStrategyScope;
        $managementProfile = (string) ($components['management_id'] ?? '');
        $managementContract = (array) data_get($passport, 'management_contract', []);
        $managementAdapter = $managementProfile !== ''
            ? app(TradeManagementLibraryService::class)->runtimeAdapter($managementProfile)
            : null;
        $managementBound = $managementProfile !== ''
            && (string) data_get($managementContract, 'protocol') === TradeManagementLibraryService::PROTOCOL
            && (string) data_get($managementContract, 'profile') === $managementProfile
            && is_array($managementAdapter)
            && (string) data_get($managementAdapter, 'profile') === $managementProfile;
        $instrumentAssignment ??= $this->instrumentResearch->assignment($agent);
        $runtimeTimeframe = strtoupper((string) ($runtimeTimeframe
            ?: data_get($passport, 'execution_timeframe', $agent->timeframe)));
        $replayDatasetHash = (string) ($replayDatasetHash ?: data_get($mtfBundle, 'bundle_hash', ''));
        $executionContract = app(ExecutionContractService::class)->for(
            (string) $agent->symbol,
            $runtimeTimeframe,
        );
        $compositionId = (string) $passport['composition_id'];
        $instrumentHash = (string) data_get($instrumentAssignment, 'assignment_hash', '');
        $instrumentHashPayload = $instrumentAssignment;
        unset($instrumentHashPayload['assignment_hash']);
        $instrumentBound = (string) data_get($instrumentAssignment, 'protocol') === LabInstrumentResearchService::PROTOCOL
            && ! str_starts_with((string) data_get($instrumentAssignment, 'status', ''), 'blocked_')
            && (! array_key_exists('required', (array) data_get($instrumentAssignment, 'pair_reservation', []))
                || is_bool(data_get($instrumentAssignment, 'pair_reservation.required')))
            && (data_get($instrumentAssignment, 'pair_reservation.protocol') !== LabInstrumentResearchService::ACADEMY_RESERVATION_PROTOCOL
                || data_get($instrumentAssignment, 'pair_reservation.required') === true)
            && (data_get($instrumentAssignment, 'pair_reservation.required') !== true
                || (data_get($instrumentAssignment, 'pair_reservation.status') === 'reserved'
                    && data_get($instrumentAssignment, 'status') === 'assigned'
                    && (array) data_get($instrumentAssignment, 'selected_keys', []) !== []
                    && (array) data_get($instrumentAssignment, 'selected', []) !== []))
            && (string) data_get($instrumentAssignment, 'hash_protocol') === LabInstrumentResearchService::HASH_PROTOCOL
            && $instrumentHash !== ''
            && hash_equals($instrumentHash, $this->numericCanonicalHash($instrumentHashPayload))
            && (string) data_get($instrumentAssignment, 'source_components.composition_id') === $compositionId
            && collect([
                'strategy_library_id' => 'strategy_id',
                'tactic_library_key' => 'tactic_id',
                'risk_library_id' => 'risk_id',
                'management_id' => 'management_id',
            ])->every(fn (string $componentKey, string $sourceKey): bool =>
                (string) data_get($instrumentAssignment, 'source_components.'.$sourceKey)
                === (string) ($components[$componentKey] ?? '')
            );
        $mtfRequired = $runtimeTimeframe === strtoupper((string) config('services.xauusd_organism.execution_timeframe', 'M5'));
        $mtfManifest = (array) data_get($mtfBundle, 'manifest', []);
        $mtfStreamHashes = collect((array) data_get($mtfManifest, 'streams', []))
            ->filter(fn ($stream): bool => is_array($stream))
            ->mapWithKeys(fn (array $stream, string $timeframe): array => [
                strtoupper($timeframe) => (string) ($stream['sha256'] ?? ''),
            ])->all();
        $mtfBound = ! $mtfRequired || (
            (string) data_get($mtfManifest, 'protocol') === MultiTimeframeSnapshotService::PROTOCOL
            && filled(data_get($mtfBundle, 'bundle_hash'))
            && collect(['M5', 'M15', 'H1', 'H4'])->every(
                fn (string $timeframe): bool => filled($mtfStreamHashes[$timeframe] ?? null),
            )
        );

        $contract = [
            'protocol' => 'xauusd_composition_runtime_contract_v3',
            'hash_protocol' => LabInstrumentResearchService::HASH_PROTOCOL,
            'composition_id' => $compositionId,
            'typed_program_id' => (string) data_get($passport, 'typed_program.program_id', ''),
            'typed_program_protocol' => (string) data_get($passport, 'typed_program.runtime_protocol', ''),
            'components' => [
                'strategy_id' => $strategyId,
                'tactic_id' => $declaredTactic,
                'risk_id' => (string) ($components['risk_id'] ?? ''),
                'management_id' => (string) ($components['management_id'] ?? ''),
            ],
            'runtime_bindings' => [
                'strategy' => [
                    'expected_family' => (string) data_get($strategyRuntime, 'family', ''),
                    'expected_architecture' => (string) data_get($strategyRuntime, 'architecture', ''),
                    'actual_family' => (string) $agent->strategy_family,
                    'actual_architecture' => $actualArchitecture,
                    'base_strategy' => $actualBaseStrategy,
                    'signal_scope_bound' => $strategyScopeBound,
                    'bound' => is_array($strategyRuntime)
                        && (string) data_get($strategyRuntime, 'family') === (string) $agent->strategy_family
                        && (string) data_get($strategyRuntime, 'architecture') === $actualArchitecture
                        && $strategyScopeBound,
                ],
                'tactic' => [
                    'declared' => $declaredTactic,
                    'actual_architecture' => $actualTactic,
                    'bound' => $declaredTactic !== '' && $declaredTactic === $actualTactic,
                ],
                'risk' => [
                    'gene' => $riskGene,
                    'value' => $riskGene !== '' && array_key_exists($riskGene, $parameters)
                        ? $parameters[$riskGene]
                        : null,
                    'bound' => $riskGene !== '' && array_key_exists($riskGene, $parameters),
                ],
                'management' => [
                    'profile' => $managementProfile,
                    'bound' => $managementBound,
                    'adapter' => $managementAdapter,
                    'reason' => $managementBound
                        ? 'exact_runtime_profile_adapter_bound'
                        : 'management_contract_or_runtime_adapter_missing',
                ],
            ],
            'execution_timeframe' => $runtimeTimeframe,
            'laboratory_storage_timeframe' => (string) data_get($passport, 'laboratory_storage_timeframe', ''),
            'strategy_contract' => (array) data_get($passport, 'strategy_contract', []),
            'strategy_signal_scope' => $frozenStrategyScope,
            'strategy_scope_binding' => [
                'expected_runtime' => (string) data_get($frozenStrategyScope, 'runtime', ''),
                'actual_runtime' => $actualBaseStrategy,
                'expected_regimes' => (array) data_get($frozenStrategyScope, 'regimes', []),
                'actual_regimes' => (array) data_get($actualStrategyScope, 'regimes', []),
                'bound' => $strategyScopeBound,
            ],
            'tactic_contract' => (array) data_get($model->metadata, 'tactic_contract', []),
            'risk_contract' => (array) data_get($passport, 'risk_contract', []),
            'management_contract' => $managementContract,
            'typed_program_nodes' => collect((array) data_get($passport, 'typed_program.nodes', []))
                ->filter(fn ($node): bool => is_array($node) && filled($node['module'] ?? null))
                ->map(fn (array $node): array => $node)
                ->values()->all(),
            'decision_tools' => array_values(array_filter(
                (array) data_get($passport, 'decision_tools', []),
                'is_string',
            )),
            'execution_authority' => [
                'protocol' => 'composition_execution_authority_v1',
                'symbol' => strtoupper(str_replace(['/', '_', '-'], '', (string) $agent->symbol)),
                'execution_timeframe' => $runtimeTimeframe,
                'dataset' => [
                    'replay_dataset_hash' => $replayDatasetHash,
                    'bound' => $replayDatasetHash !== '',
                ],
                'execution' => [
                    'protocol' => (string) data_get($executionContract, 'protocol', ''),
                    'execution_hash' => (string) data_get($executionContract, 'execution_hash', ''),
                    'bound' => filled(data_get($executionContract, 'execution_hash')),
                ],
                'instrument' => [
                    'protocol' => (string) data_get($instrumentAssignment, 'protocol', ''),
                    'assignment_hash' => $instrumentHash,
                    'selected_keys' => array_values(array_filter(
                        (array) data_get($instrumentAssignment, 'selected_keys', []),
                        'is_string',
                    )),
                    'bound' => $instrumentBound,
                ],
                'mtf' => [
                    'required' => $mtfRequired,
                    'protocol' => (string) data_get($mtfManifest, 'protocol', ''),
                    'bundle_hash' => (string) data_get($mtfBundle, 'bundle_hash', ''),
                    'stream_hashes' => $mtfStreamHashes,
                    'bound' => $mtfBound,
                ],
                'runtime' => [
                    'nodes' => [
                        [
                            'module' => 'instrument_context_gate',
                            'provides' => 'InstrumentContextReceipt',
                        ],
                        [
                            'module' => 'mtf_permission_gate',
                            'provides' => 'MtfPermissionReceipt',
                        ],
                    ],
                ],
            ],
            'paper_execution_authority' => false,
            'promotion_evidence' => false,
        ];
        $contract['contract_hash'] = $this->numericCanonicalHash($contract);

        return $contract;
    }

    private function numericCanonicalHash(mixed $value): string
    {
        return hash('sha256', json_encode(
            $this->numericCanonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    private function numericCanonicalize(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            $number = rtrim(rtrim(sprintf('%.14F', (float) $value), '0'), '.');

            return 'number:'.($number === '-0' || $number === '' ? '0' : $number);
        }
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->numericCanonicalize($item);
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Keep single-agent recovery and bounded batch screening on one exact
     * strategy/context contract. A batch is only a feature-computation
     * optimization; it must never remove the specialist activation scope or
     * the out-of-scope WAIT rule carried by the same agent.
     *
     * @return array<string,mixed>
     */
    private function screeningStrategyPayload(
        LabAgent $agent,
        string $runtimeTimeframe,
        ?array $mtfBundle,
        string $replayDatasetHash,
    ): array {
        $agent->loadMissing('modelVersion');
        $model = $agent->modelVersion;
        if (! $model) {
            throw new RuntimeException('SCREENING_MODEL_VERSION_REQUIRED');
        }

        $instrumentAssignment = $this->instrumentResearch->assignment($agent);

        return [
            'lab_agent_id' => (int) $agent->id,
            'strategy' => $model->strategy,
            'base_strategy' => $this->schemas->runtimeBaseStrategy(
                $model->strategy,
                data_get($model->metadata, 'base_strategy'),
                $agent->strategy_family,
            ),
            'version' => $model->version,
            'parameters' => $model->parameters ?? [],
            'instrument_research_assignment' => $instrumentAssignment,
            'composition_runtime_contract' => $this->compositionRuntimeContract(
                $agent,
                $instrumentAssignment,
                $runtimeTimeframe,
                $mtfBundle,
                $replayDatasetHash,
            ),
            'specialist_context_contract' => $this->replaySpecialistContextContract($model),
            'specialist_council_evaluation' => app(SpecialistCouncilLifecycleService::class)->evaluationBindingForModel($model, $replayDatasetHash),
            // A declared council is executed as one shared account, not as a
            // sum of independently scored specialists. The persisted version
            // owns every native member identity; caller flags grant nothing.
            'specialist_council_contract' => app(SpecialistCouncilLifecycleService::class)->runtimeContractForModel(
                $model,
                $runtimeTimeframe,
                $replayDatasetHash,
                (string) app(ExecutionContractService::class)->for($agent->symbol, $runtimeTimeframe)['execution_hash'],
                $mtfBundle,
                (string) $agent->symbol,
            ),
        ];
    }

    /** Bind the stored comparison before the original HTTP request is sealed. */
    private function bindCouncilEvaluationRequests(array $request, array $models): array
    {
        foreach ($models as $model) {
            if ($model instanceof ModelVersion && data_get($model->metadata, 'specialist_council_evaluation') !== null) {
                $request = app(SpecialistCouncilLifecycleService::class)->bindEvaluationRequestForModel($model, $request);
            }
        }
        return $request;
    }

    /**
     * A council member keeps the same native runtime authority as a standalone
     * model. Do not reconstruct composition or instrument policy in a second
     * compiler, and do not mutate a sealed source by generating assignments.
     */
    public function specialistCouncilMemberPayload(
        ModelVersion $model,
        string $runtimeTimeframe,
        ?array $mtfBundle,
        string $replayDatasetHash,
        string $symbol = '',
    ): array {
        if (data_get($model->metadata, 'specialist_council') !== null) {
            throw new RuntimeException('NESTED_SPECIALIST_COUNCIL_MEMBER_UNSUPPORTED');
        }
        $agents = LabAgent::query()->where('model_version_id', $model->id);
        if ($symbol !== '') {
            $agents->where('symbol', $symbol);
        }
        $nativeAgents = $agents->orderBy('id')->limit(2)->get();
        $agent = $nativeAgents->first();
        if ($symbol === '' && LabAgent::query()->where('model_version_id', $model->id)
            ->select('symbol')->distinct()->limit(2)->pluck('symbol')->count() > 1) {
            throw new RuntimeException('COUNCIL_MEMBER_NATIVE_SYMBOL_AMBIGUOUS');
        }
        if ($agent === null && $symbol !== '' && LabAgent::query()->where('model_version_id', $model->id)->exists()) {
            throw new RuntimeException('COUNCIL_MEMBER_NATIVE_INSTRUMENT_MISMATCH');
        }
        $hasComposition = filled(data_get($model->metadata, 'smart_composition.composition_passport.composition_id'));
        if ($agent === null && ($hasComposition || data_get($model->metadata, 'causal_learning_cohort') !== null)) {
            throw new RuntimeException('COUNCIL_MEMBER_NATIVE_LAB_IDENTITY_REQUIRED');
        }
        $assignment = (array) data_get($model->metadata, 'instrument_research_assignment', []);
        if ($hasComposition && $assignment === []) {
            throw new RuntimeException('COUNCIL_MEMBER_FROZEN_INSTRUMENT_ASSIGNMENT_REQUIRED');
        }
        $symbol = $symbol !== '' ? $symbol : (string) ($agent?->symbol ?? '');
        if ($agent !== null) {
            $agent->setRelation('modelVersion', $model);
        }
        return array_filter([
            'lab_agent_id' => $agent?->id,
            'symbol' => $symbol !== '' ? $symbol : null,
            'strategy' => $model->strategy,
            'base_strategy' => $this->schemas->runtimeBaseStrategy(
                (string) $model->strategy,
                data_get($model->metadata, 'base_strategy'),
                (string) ($agent?->strategy_family ?? data_get($model->metadata, 'strategy_family', $model->strategy)),
            ),
            'version' => $model->version,
            'parameters' => $model->parameters ?? [],
            'instrument_research_assignment' => $assignment,
            'composition_runtime_contract' => $agent === null ? new \stdClass : $this->compositionRuntimeContract(
                $agent, $assignment, $runtimeTimeframe, $mtfBundle, $replayDatasetHash,
            ),
            'specialist_context_contract' => $this->replaySpecialistContextContract($model),
            'mtf_pilot' => $symbol === '' ? null : app(MultiTimeframePilotService::class)->requestPayload(
                $symbol, $runtimeTimeframe, (string) $model->strategy,
                data_get($mtfBundle, 'bundle_hash'),
            ),
        ], fn ($value): bool => $value !== null);
    }

    private function attestSpecialistCouncilReplay(ModelVersion $model, LabEvaluationRun $run, array $result): void
    {
        if (data_get($model->metadata, 'specialist_council') === null
            && data_get($result, 'specialist_council_receipt') === null) {
            return;
        }
        // Use the original transported request, also on cache/recovery paths.
        // Reconstructing a contract from current model metadata after replay
        // would silently bless a version switch or a receipt from another run.
        $request = (array) data_get($run->request_meta, 'payload', []);
        $owner = app(SpecialistCouncilLifecycleService::class);
        $receipt = $owner->attestReplayResult($model, $request, $result);
        if ($receipt !== null && ($version = $owner->researchVersionForModel($model)) !== null) {
            app(SpecialistCouncilDataUseService::class)->recordReplayUse($version, $request, $run->run_id, $receipt);
        }
    }

    /** A differential child may improve only its declared target lane. */
    private function appendDifferentialNoRegressionEvidence(
        $model,
        array $result,
        ?string $strategyFamily = null,
        ?string $symbol = null,
        ?string $timeframe = null,
    ): array {
        $contract = (array) data_get($model->metadata, 'differential_router_contract', []);
        $router = (array) data_get($result, 'differential_router', []);
        // A paired non-target contract belongs only to an explicitly
        // differential-router experiment.  Previously an empty contract fell
        // through to the default trend_down target and every ordinary
        // regime_ensemble/hybrid/breakout candidate was falsely rejected with
        // FAILED_NON_TARGET_REGRESSION.
        // Family identity is authoritative when the caller has the sealed
        // LabAgent row. A hybrid transition/risk router can inherit an old
        // `base_strategy` label while deliberately using hybrid execution;
        // treating that label as proof of a differential contract creates a
        // false FAILED_NON_TARGET_REGRESSION before the specialist reaches
        // full validation. Keep the legacy metadata fallback only for older
        // callers that do not have the family identity available.
        $isDifferential = $strategyFamily !== null
            ? ($strategyFamily === 'differential_router' || $contract !== [])
            : ($contract !== []
                || data_get($router, 'enabled') === true
                || str_contains((string) data_get($model->metadata, 'base_strategy', ''), 'differential_router'));
        if (! $isDifferential) {
            return $result;
        }
        $targetRegime = (string) data_get($contract, 'target_regime', data_get($result, 'differential_router.target_regime', 'trend_down'));
        if (! in_array($targetRegime, ['trend_up', 'range', 'trend_down'], true)) {
            return $result;
        }
        $parent = ModelVersion::find((int) data_get($contract, 'parent_model_version_id'));
        $parentResult = (array) data_get($parent?->metadata, 'last_screen_result', []);
        $paired = (array) data_get($router, 'paired_lane', []);
        $parentRegimes = (array) data_get($parentResult, 'regime_performance', []);
        $childRegimes = (array) data_get($result, 'regime_performance', []);
        $hasPairedLane = data_get($paired, 'protocol') === LearningProtocolSafetyService::EXECUTION_CONTRACT;
        $parentNonTargetTrades = $hasPairedLane
            ? (int) data_get($paired, 'parent_non_target.trades', -1)
            : collect($parentRegimes)->reject(fn ($row, $regime) => $regime === $targetRegime)->sum(fn ($row) => (int) data_get($row, 'trades', 0));
        $childNonTargetTrades = $hasPairedLane
            ? (int) data_get($paired, 'child_non_target.trades', -1)
            : (int) data_get($router, 'non_target_trade_count', -1);
        $parentNonTargetNet = $hasPairedLane
            ? (float) data_get($paired, 'parent_non_target.net_profit_percent', 0)
            : collect($parentRegimes)->reject(fn ($row, $regime) => $regime === $targetRegime)->sum(fn ($row) => (float) data_get($row, 'profit_percent', 0));
        $childNonTargetNet = $hasPairedLane
            ? (float) data_get($paired, 'child_non_target.net_profit_percent', 0)
            : collect($childRegimes)->reject(fn ($row, $regime) => $regime === $targetRegime)->sum(fn ($row) => (float) data_get($row, 'profit_percent', 0));
        $parentFrozenHash = (string) data_get($contract, 'parent_frozen_hash');
        $parentHashMatches = $parentResult !== [] && hash_equals($parentFrozenHash, hash('sha256', json_encode($parentResult, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)));
        $resultExecutionContract = (array) data_get($result, 'execution_contract', []);
        $canonicalExecutionMatches = app(ExecutionContractService::class)->matches(
            $resultExecutionContract,
            (string) ($symbol ?: data_get($model, 'symbol', data_get($model->metadata, 'lab_symbol', 'XAUUSD'))),
            (string) ($timeframe ?: data_get($model, 'timeframe', data_get($model->metadata, 'lab_timeframe', 'H1'))),
        );
        $legacyExecutionMatches = (string) data_get($result, 'execution_contract.version') === LearningProtocolSafetyService::EXECUTION_CONTRACT;
        $sameExecutionContract = (string) data_get($contract, 'execution_contract') === LearningProtocolSafetyService::EXECUTION_CONTRACT
            && ($legacyExecutionMatches || $canonicalExecutionMatches);
        $entryIdentity = data_get($paired, 'non_target_entry_times_identity') === true;
        $status = $hasPairedLane
            && $parentHashMatches && $sameExecutionContract
            && data_get($paired, 'status') === 'passed'
            && data_get($paired, 'non_target_signal_identity') === true
            && data_get($paired, 'non_target_confidence_identity') === true
            && data_get($paired, 'non_target_ledger_identity') === true
            && $entryIdentity ? 'passed' : 'failed';
        $result['differential_no_regression'] = [
            'status' => $status, 'target_regime' => $targetRegime,
            'protocol' => LearningProtocolSafetyService::EXECUTION_CONTRACT,
            'non_target_signal_identity' => (bool) data_get($paired, 'non_target_signal_identity', data_get($router, 'non_target_signal_identity', false)),
            'non_target_confidence_identity' => (bool) data_get($paired, 'non_target_confidence_identity', data_get($router, 'non_target_confidence_identity', false)),
            'non_target_ledger_identity' => (bool) data_get($paired, 'non_target_ledger_identity', false),
            'non_target_entry_times_identity' => $entryIdentity,
            'parent_frozen_hash_matches' => $parentHashMatches,
            'execution_contract_matches' => $sameExecutionContract,
            'canonical_execution_contract_matches' => $canonicalExecutionMatches,
            'branch_hashes' => data_get($paired, 'non_target_branch_hashes', []),
            'parent_non_target_trade_count' => $parentNonTargetTrades, 'child_non_target_trade_count' => $childNonTargetTrades,
            'parent_non_target_net_profit_percent' => round($parentNonTargetNet, 5), 'child_non_target_net_profit_percent' => round($childNonTargetNet, 5),
            'portfolio_interaction_delta_net_profit_percent' => data_get($paired, 'portfolio_interaction_delta_net_profit_percent'),
            'target_delta_net_profit_percent' => data_get($paired, 'target_delta_net_profit_percent'),
            'epsilon' => .01, 'promotion_evidence' => false,
        ];

        return $result;
    }
}
