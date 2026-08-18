<?php

namespace App\Services;

use App\Jobs\ProcessLabScreeningLearningProjection;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\ModelVersion;
use App\Services\MarketData\CandlePayloadService;
use App\Services\MarketData\MarketVolumeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LabAgentEvaluationService
{
    public function __construct(private CandlePayloadService $candles, private MarketChampionService $champions, private LabDatasetExportService $datasets, private ScreeningLearningOutboxService $screeningOutbox, private CandidateGateDecisionService $gateDecisions, private ShadowVetoLedgerService $shadowVetoLedger, private CandidateHandoffService $handoffs, private CounterfactualBlameGraphService $blameGraph, private LearningProtocolSafetyService $protocolSafety, private LabImmutableEvidenceService $evidence, private StrategyParameterSchemaService $schemas, private MarketVolumeService $volumes, private AgentKnowledgeService $knowledge, private ParentContributionGraphService $parentGraphService, private LabGenerationContextService $generationContext) {}

    public function evaluate(LabAgent $agent, ?LabEvaluationRun $run = null): void
    {
        $run ??= $this->evidence->beginRun($agent, 'full_validation', 'full', ['source' => 'direct_evaluation']);
        $agent->load('modelVersion', 'generation');
        $model = $agent->modelVersion;
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
            'canonical_dataset_snapshots.foundation.sha256',
            ''
        );
        $currentSnapshotPath = (string) data_get(
            $agent->generation?->trigger_context,
            'canonical_dataset_snapshots.price.path',
            data_get($agent->generation?->trigger_context, 'canonical_dataset_snapshots.volume.path', ''),
        );
        $currentFoundationPath = (string) data_get(
            $agent->generation?->trigger_context,
            'canonical_dataset_snapshots.foundation.path',
            '',
        );
        $currentFoundationRowCount = (int) data_get(
            $agent->generation?->trigger_context,
            'canonical_dataset_snapshots.foundation.manifest.row_count',
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
        $cached = data_get($model->metadata, 'full_validation_batch');
        $cachedRuntimePolicy = (array) data_get($cached, 'full_replay_runtime_policy', []);
        $configuredFoundationThreshold = max(1, (int) config('services.lab_selection.full_replay_bounded_cohort_foundation_rows', 100000));
        $configuredMaxCohortSize = max(2, (int) config('services.lab_selection.full_replay_max_cohort_size', 2));
        $cacheRuntimePolicyMatches = data_get($cachedRuntimePolicy, 'protocol') === 'full_replay_runtime_budget_v1'
            && $currentFoundationRowCount > 0
            && (int) data_get($cachedRuntimePolicy, 'foundation_row_count', -1) === $currentFoundationRowCount
            && (int) data_get($cachedRuntimePolicy, 'foundation_threshold_rows', -1) === $configuredFoundationThreshold
            && (int) data_get($cachedRuntimePolicy, 'max_cohort_size', -1) === $configuredMaxCohortSize;
        $cacheIsSealed = (int) data_get($cached, 'generation_id') === (int) $agent->lab_generation_id
            && is_array(data_get($cached, 'item'))
            && is_array(data_get($cached, 'request_manifest'))
            && hash_equals($currentCodeHash, (string) data_get($cached, 'code_hash', ''))
            && hash_equals($currentParameterHash, (string) data_get($cached, 'parameter_hash', ''))
            && $currentSnapshotHash !== ''
            && hash_equals($currentSnapshotHash, (string) data_get($cached, 'data_hash', ''))
            && is_string($currentSnapshotFileHash)
            && hash_equals($currentSnapshotHash, $currentSnapshotFileHash)
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
            $foundationSnapshot = $this->datasets->ensureGenerationFoundationSnapshot($agent->generation);
            $regimeSnapshot = $isM15
                ? $this->datasets->ensureGenerationRegimeSnapshot($agent->generation)
                : null;
            $foundationRowCount = (int) data_get($foundationSnapshot, 'manifest.row_count', 0);
            $boundedThreshold = max(1, (int) config('services.lab_selection.full_replay_bounded_cohort_foundation_rows', 100000));
            $maxCohortSize = max(2, (int) config('services.lab_selection.full_replay_max_cohort_size', 2));
            $volumeEnabled = $cohort->contains(fn (LabAgent $peer): bool => $this->volumeEnabled($peer->modelVersion));
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
                    (string) ($datasetSnapshot['sha256'] ?? ''),
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
                'reason' => $boundedCohort ? 'FOUNDATION_REPLAY_RUNTIME_BUDGET' : 'NO_RUNTIME_CAP_REQUIRED',
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
                $datasetSnapshot = $this->datasets->ensureGenerationSnapshot($agent->generation, $volumeEnabled);
            }
            $dataset = $datasetSnapshot['path'];
            $manifest = (array) ($datasetSnapshot['manifest'] ?? []);
            $manifest['foundation'] = $foundationSnapshot['manifest'];
            if ($regimeSnapshot !== null) {
                $manifest['regime'] = $regimeSnapshot['manifest'];
            }
            $request = [
                'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe, 'strategy' => 'all', 'evaluation_mode' => 'replay',
                'strategies' => $cohort->map(fn (LabAgent $peer) => ['strategy' => $peer->modelVersion->strategy, 'base_strategy' => $this->schemas->runtimeBaseStrategy($peer->modelVersion->strategy, data_get($peer->modelVersion->metadata, 'base_strategy'), $peer->strategy_family), 'version' => $peer->modelVersion->version, 'parameters' => $peer->modelVersion->parameters ?? []])->all(),
                'initial_balance' => 10000, 'risk_per_trade' => 1, 'dataset_path' => $dataset,
                'foundation_dataset_path' => $foundationSnapshot['path'],
                'full_replay_runtime_policy' => $runtimePolicy,
                'volume_context' => $volumeEnabled
                    ? (array) data_get($manifest, 'volume_quality', [])
                    : $this->disabledVolumeContext(),
                'policy_context' => [
                    'trial_ledger' => app(LabTrialLedgerService::class)->selectionContext($agent->symbol, $agent->timeframe),
                    'full_replay_runtime_policy' => $runtimePolicy,
                    // Each cohort member keeps its own one-gene contract;
                    // the Python replay selects the contract by strategy so
                    // a sibling's mutation can never be used for plateau
                    // evidence by mistake.
                    'repair_contracts' => $cohort->mapWithKeys(function (LabAgent $peer): array {
                        $diff = (array) $peer->parameter_diff;
                        $changedGene = count($diff) === 1 ? array_key_first($diff) : null;

                        return [$peer->modelVersion->strategy => [
                            'changed_gene' => $changedGene,
                            'repair_attempt' => (int) data_get($peer->modelVersion->metadata, 'repair_lineage.attempt', 0),
                            'parent_model_version_id' => $peer->parent_a_model_version_id ?: $peer->parent_b_model_version_id,
                            'parent_model_version_ids' => $this->parentGraphService->ids($peer),
                            'single_gene' => count($diff) === 1,
                        ]];
                    })->all(),
                ],
                'execution' => $this->executionAssumptions($agent->symbol),
                'execution_contract' => app(ExecutionContractService::class)->for($agent->symbol, $agent->timeframe),
                'mtf_pilot' => app(MultiTimeframePilotService::class)->requestPayload(
                    $agent->symbol,
                    $agent->timeframe,
                    $model->strategy,
                    $currentRegimeHash ?: null,
                ),
                'emit_decision_trace' => true,
            ];
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
                    'strategy' => $peer->modelVersion->strategy,
                    'base_strategy' => $this->schemas->runtimeBaseStrategy($peer->modelVersion->strategy, data_get($peer->modelVersion->metadata, 'base_strategy'), $peer->strategy_family),
                    'version' => $peer->modelVersion->version,
                    'parameters' => $peer->modelVersion->parameters ?? [],
                    'member_key' => 'lab_agent:'.$peer->id,
                    'role' => data_get($peer->modelVersion->metadata, 'portfolio_council_lane.role', 'specialist'),
                    'target_regime' => $this->normalizeCouncilTarget(data_get($peer->modelVersion->metadata, 'portfolio_research_contract.target_regime'), ['trend_up', 'trend_down', 'range']),
                    'target_volatility' => $this->normalizeCouncilTarget(data_get($peer->modelVersion->metadata, 'portfolio_research_contract.target_volatility'), ['high_volatility', 'normal_volatility', 'low_volatility']),
                    'target_direction' => $this->normalizeCouncilTarget(data_get($peer->modelVersion->metadata, 'portfolio_research_contract.target_direction'), ['BUY', 'SELL']),
                ])->values()->all();
            }
            // M15 entries use the generation-frozen H1 regime. The Python
            // engine delays each H1 state by one H1 bar before merging, so an
            // open H1 candle cannot influence an earlier M15 decision.
            if ($regimeSnapshot !== null) {
                $request['regime_dataset_path'] = $regimeSnapshot['path'];
            }
            $timeout = min(3900, max(60, (int) config('services.lab_selection.full_replay_timeout_seconds', 3900)));
            $requestId = 'full-'.$agent->id.'-'.bin2hex(random_bytes(6));
            $this->evidence->attachRequest($run, $request, ['request_id' => $requestId, 'dataset_manifest' => $manifest]);
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
                $peerItem['result'] = array_merge((array) ($peerItem['result'] ?? []), [
                    'data_manifest' => $manifest,
                    'full_replay_runtime_policy' => $runtimePolicy,
                ]);
                $items->put($peer->modelVersion->strategy, $peerItem);
                $peerModel = $peer->modelVersion;
                $peerModel->update(['metadata' => array_merge($peerModel->metadata ?? [], ['full_validation_batch' => [
                    'protocol' => 'sealed_replay_cache_v2',
                    'generation_id' => $agent->lab_generation_id,
                    'item' => $peerItem,
                    'code_hash' => $currentCodeHash,
                     'parameter_hash' => $this->evidence->parameterHash($peer),
                     'data_hash' => (string) ($manifest['snapshot_sha256'] ?? $manifest['sha256'] ?? ''),
                     'foundation_data_hash' => (string) ($foundationSnapshot['sha256'] ?? ''),
                    'regime_data_hash' => (string) ($regimeSnapshot['sha256'] ?? ''),
                    'request_manifest' => $request,
                    'full_replay_runtime_policy' => $runtimePolicy,
                ]])]);
            }
            $item = $items->get($model->strategy);
            if (! $item) {
                throw new RuntimeException('Empty lab agent result.');
            }
        }
        // Full replay evidence is unusable without the exact canonical
        // execution hash. Never persist a score from a response that omitted
        // the contract or silently changed spread/gap policy.
        $returnedExecutionContract = data_get($item, 'result.execution_contract', data_get($item, 'execution_contract'));
        if (! is_array($returnedExecutionContract)
            || ! app(ExecutionContractService::class)->matches($returnedExecutionContract, $agent->symbol, $agent->timeframe)) {
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
            $this->evidence->recordArtifact($run, 'cohort_response', (array) $rawResponse, [
                'cohort_result_count' => is_array($rawResponse['leaderboard'] ?? null) ? count($rawResponse['leaderboard']) : 0,
                'source' => 'full_validation_cohort',
                'full_replay_runtime_policy' => $runtimePolicy,
            ]);
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
            app(\App\Services\CouncilDisagreementService::class)->recordResult($fullResult, [
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
            $generation->update(['status' => 'completed', 'completed_at' => now()]);
            $generation = $generation->fresh(['agents']);
            app(LabGenerationReportService::class)->record($generation, 'full_completed');
            if ($generation->agents->whereIn('lifecycle_status', ['forward_validated', 'paper', 'champion'])->isEmpty()) {
                $this->handoffs->noForwardCandidate($generation);
            }
        }
    }

    /** Fast, pair-local filter. Promotion never happens from this result. */
    public function screen(LabAgent $agent, ?LabEvaluationRun $run = null): void
    {
        $run ??= $this->evidence->beginRun($agent, 'screening', 'incremental', ['source' => 'direct_screen']);
        $agent->load('modelVersion', 'generation');
        $model = $agent->modelVersion;
        $volumeEnabled = $this->volumeEnabled($model);
        $datasetSnapshot = $this->datasets->ensureGenerationSnapshot($agent->generation, $volumeEnabled);
        $microProbe = data_get($agent->generation->trigger_context, 'shadow_micro_probe.protocol') === ReplayResourceAdmissionService::PROTOCOL;
        $screenRows = $microProbe
            ? (int) config('services.ai_service.shadow_micro_probe_max_rows', 512)
            : 5000;
        $rows = $this->datasets->rowsFromSnapshot($datasetSnapshot['path'], $screenRows);
        if (count($rows) < 500) {
            throw new RuntimeException('Screening uchun yetarli recent candle topilmadi.');
        }
        $regimeSnapshot = strtoupper((string) $agent->timeframe) === 'M15'
            ? $this->datasets->ensureGenerationRegimeSnapshot($agent->generation)
            : null;

        $request = [
            'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe,
            'strategy' => $model->strategy, 'evaluation_mode' => 'incremental',
            'strategies' => [[
                'strategy' => $model->strategy,
                'base_strategy' => $this->schemas->runtimeBaseStrategy($model->strategy, data_get($model->metadata, 'base_strategy'), $agent->strategy_family),
                'version' => $model->version, 'parameters' => $model->parameters ?? [],
            ]],
            'initial_balance' => 10000,
            // Immutable snapshot-path transport keeps the request/evidence
            // contract intact while removing thousands of candle objects from
            // HTTP JSON. Python applies the same bounded tail before
            // normalisation, so the replay stream is unchanged.
            'dataset_path' => $datasetSnapshot['path'],
            'dataset_tail_rows' => $screenRows,
            'volume_context' => $volumeEnabled
                ? $this->volumeContextOrFail($agent->symbol, $agent->timeframe)
                : $this->disabledVolumeContext(),
            // Screening must rank candidates after the same normal execution
            // costs as full replay; otherwise cheap-turnover strategies are
            // incorrectly promoted into the scarce full-validation cohort.
            'execution' => $this->executionAssumptions($agent->symbol),
            'execution_contract' => app(ExecutionContractService::class)->for($agent->symbol, $agent->timeframe),
            'mtf_pilot' => app(MultiTimeframePilotService::class)->requestPayload(
                $agent->symbol,
                $agent->timeframe,
                $model->strategy,
                $regimeSnapshot['sha256'] ?? null,
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
                'snapshot_transport' => [
                    'protocol' => 'immutable_snapshot_path_v1',
                    'dataset_path' => $datasetSnapshot['path'],
                    'dataset_manifest_path' => $datasetSnapshot['path'].'.manifest.json',
                    'dataset_sha256' => $datasetSnapshot['sha256'],
                    'tail_rows' => $screenRows,
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
        $configuredScreenTimeout = $isDifferential
            ? (int) config('services.lab_selection.differential_screen_timeout_seconds', 900)
            : (int) config('services.lab_selection.screen_timeout_seconds', 300);
        // Differential screening contains four paired ledgers. Keep its
        // longer transport budget explicit; ordinary screening remains
        // hard-bounded at 930 seconds so the Python worker's 900-second
        // operational budget has a 30-second response margin.
        $screenTimeout = min($isDifferential ? 900 : 930, max(30, $configuredScreenTimeout));
        $requestId = 'screen-'.$agent->id.'-'.bin2hex(random_bytes(6));
        $manifest = [
            'candle_count' => count($rows),
            'data_hash' => $this->evidence->hash($rows),
            'snapshot_sha256' => $datasetSnapshot['sha256'],
            'snapshot_protocol' => $datasetSnapshot['protocol'],
            'snapshot_generation_id' => $agent->lab_generation_id,
        ];
        if ($regimeSnapshot !== null) {
            $manifest['regime_snapshot_sha256'] = $regimeSnapshot['sha256'];
            $manifest['regime_snapshot_manifest'] = $regimeSnapshot['manifest'];
        }
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
        $screenResult = array_merge($result, [
            'forward_score' => $item['forward_score'] ?? $item['score'] ?? 0,
            'train_score' => $item['train_score'] ?? $item['score'] ?? 0,
            'validation_score' => $item['validation_score'] ?? $item['score'] ?? 0,
            'evidence_run_id' => $run->run_id,
            'data_manifest' => $manifest,
        ]);
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
            : app(ExecutionContractService::class)->for($agent->symbol, $agent->timeframe);
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
        app(\App\Services\CouncilDisagreementService::class)->recordResult($screenResult, [
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

        $generation = $agent->generation()->with('agents')->first();
        // An evaluator/transport failure is not a screen verdict. Keep the
        // generation open until the failed agent is recovered or explicitly
        // quarantined; otherwise the last successful peer could close an
        // incomplete generation as if every candidate had evidence.
        if ($generation->agents->whereIn('lifecycle_status', [
            'draft', 'queued', 'screening', 'evaluation_error',
            'full_queued', 'full_validation', 'training',
        ])->isEmpty()) {
            $this->generationContext->updateWithAttributes($generation, [
                'status' => 'screened',
                'completed_at' => now(),
            ], function (array $context): array {
                $context['screening_terminal'] = [
                    'protocol' => 'generation_terminal_boundary_v1',
                    'status' => 'screened',
                    'completed_at' => now()->utc()->toIso8601String(),
                    'all_agents_terminal' => true,
                    'promotion_evidence' => false,
                ];

                return $context;
            });
            app(LabGenerationReportService::class)->record($generation->fresh(), 'screening_completed');
        }
    }

    /**
     * Evaluate a bounded cohort in one HTTP replay.
     *
     * Python receives one immutable snapshot path and builds H1/M15/volume/
     * ATR features once for the request. The response is still split back
     * into one evidence run and one gate decision per agent.
     *
     * @param array<int, int> $agentIds
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
        $generation = $first->generation;
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
        $datasetSnapshot = $this->datasets->ensureGenerationSnapshot($generation, $volumeEnabled);
        $microProbe = data_get($generation->trigger_context, 'shadow_micro_probe.protocol') === ReplayResourceAdmissionService::PROTOCOL;
        $screenRows = $microProbe
            ? (int) config('services.ai_service.shadow_micro_probe_max_rows', 512)
            : 5000;
        $rows = $this->datasets->rowsFromSnapshot($datasetSnapshot['path'], $screenRows);
        if (count($rows) < 500) {
            throw new RuntimeException('Screening batch uchun yetarli recent candle topilmadi.');
        }
        $regimeSnapshot = strtoupper((string) $first->timeframe) === 'M15'
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
            $strategies[] = [
                'lab_agent_id' => (int) $agent->id,
                'strategy' => $model->strategy,
                'base_strategy' => $this->schemas->runtimeBaseStrategy(
                    $model->strategy,
                    data_get($model->metadata, 'base_strategy'),
                    $agent->strategy_family,
                ),
                'version' => $model->version,
                'parameters' => $model->parameters ?? [],
            ];
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
            'timeframe' => $first->timeframe,
            'strategy' => $first->modelVersion->strategy,
            'evaluation_mode' => 'incremental',
            'strategies' => $strategies,
            'initial_balance' => 10000,
            'risk_per_trade' => 1,
            'dataset_path' => $datasetSnapshot['path'],
            'dataset_tail_rows' => $screenRows,
            'volume_context' => $volumeEnabled
                ? $this->volumeContextOrFail($first->symbol, $first->timeframe)
                : $this->disabledVolumeContext(),
            'execution' => $this->executionAssumptions($first->symbol),
            'execution_contract' => app(ExecutionContractService::class)->for($first->symbol, $first->timeframe),
            'mtf_pilot' => app(MultiTimeframePilotService::class)->requestPayload(
                $first->symbol,
                $first->timeframe,
                $first->modelVersion->strategy,
                $regimeSnapshot['sha256'] ?? null,
            ),
            'policy_context' => [
                'shadow_micro_probe' => $microProbe,
                'trial_ledger' => app(LabTrialLedgerService::class)->selectionContext($first->symbol, $first->timeframe),
                'repair_contracts' => $repairContracts,
                'snapshot_transport' => [
                    'protocol' => 'immutable_snapshot_path_v1',
                    'dataset_path' => $datasetSnapshot['path'],
                    'dataset_manifest_path' => $datasetSnapshot['path'].'.manifest.json',
                    'dataset_sha256' => $datasetSnapshot['sha256'],
                    'tail_rows' => $screenRows,
                    'features_shared_per_generation_request' => true,
                    'bounded_batch_size' => count($agents),
                ],
            ],
            // The canonical per-candidate result keeps its full decision
            // trace. Cost/mutation projections turn this off in Python.
            'emit_decision_trace' => ! $microProbe,
        ];
        if ($regimeSnapshot !== null) {
            $request['regime_dataset_path'] = $regimeSnapshot['path'];
            $request['regime_dataset_tail_rows'] = 2000;
            $request['policy_context']['snapshot_transport']['regime_dataset_path'] = $regimeSnapshot['path'];
            $request['policy_context']['snapshot_transport']['regime_dataset_manifest_path'] = $regimeSnapshot['path'].'.manifest.json';
            $request['policy_context']['snapshot_transport']['regime_dataset_sha256'] = $regimeSnapshot['sha256'];
            $request['policy_context']['snapshot_transport']['regime_tail_rows'] = 2000;
        }

        LabAgent::query()->whereIn('id', $ids)->where('lifecycle_status', 'queued')
            ->update(['lifecycle_status' => 'screening']);
        foreach ($agents as $agent) {
            $agent->lifecycle_status = 'screening';
        }

        $manifest = [
            'candle_count' => count($rows),
            'data_hash' => $this->evidence->hash($rows),
            'dataset_contract' => $volumeEnabled ? 'volume' : 'price',
            'snapshot_sha256' => $datasetSnapshot['sha256'],
            'snapshot_protocol' => $datasetSnapshot['protocol'],
            'snapshot_generation_id' => $first->lab_generation_id,
            'batch_protocol' => 'bounded_screening_batch_v1',
            'batch_size' => count($agents),
        ];
        if ($regimeSnapshot !== null) {
            $manifest['regime_snapshot_sha256'] = $regimeSnapshot['sha256'];
            $manifest['regime_snapshot_manifest'] = $regimeSnapshot['manifest'];
        }
        $runs = [];
        foreach ($agents as $agent) {
            $run = $this->evidence->beginRun($agent, 'screening', 'incremental', [
                'source' => 'bounded_screening_batch',
                'batch_size' => count($agents),
                'batch_agent_ids' => $ids,
            ]);
            $runs[(int) $agent->id] = $run;
            $requestId = 'screen-batch-'.$agent->id.'-'.bin2hex(random_bytes(5));
            $this->evidence->attachRequest($run, $request, [
                'request_id' => $requestId,
                'data_hash' => $manifest['data_hash'],
                'dataset_manifest' => $manifest,
            ]);
        }
        $requestId = 'screen-batch-'.implode('-', $ids).'-'.bin2hex(random_bytes(6));
        $isDifferential = $agents->contains(fn (LabAgent $agent): bool => $agent->strategy_family === 'differential_router'
            || data_get($agent->modelVersion->metadata, 'differential_router_contract') !== null);
        $configuredTimeout = (int) config('services.lab_queue.screening_batch_timeout_seconds', 1800);
        $screenTimeout = min($isDifferential ? 2400 : 1800, max(60, $configuredTimeout));
        try {
            $this->assertAiReplayHealthy($requestId, $runs[(int) $first->id], true);
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
            if ($response->failed()) {
                throw new RuntimeException($response->body());
            }
            $leaderboard = data_get($response->json(), 'leaderboard', []);
            if (! is_array($leaderboard)) {
                throw new RuntimeException('Empty screening batch result.');
            }
        } catch (\Throwable $exception) {
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
        $screenResult = array_merge($result, [
            'forward_score' => $item['forward_score'] ?? $item['score'] ?? 0,
            'train_score' => $item['train_score'] ?? $item['score'] ?? 0,
            'validation_score' => $item['validation_score'] ?? $item['score'] ?? 0,
            'evidence_run_id' => $run->run_id,
            'data_manifest' => $manifest,
        ]);
        if ($regimeSnapshot !== null) {
            $screenResult['regime_snapshot_sha256'] = $regimeSnapshot['sha256'];
            $screenResult['regime_snapshot_protocol'] = $regimeSnapshot['protocol'];
        }
        $screenResult['execution_contract'] = is_array(data_get($result, 'execution_contract'))
            ? (array) data_get($result, 'execution_contract')
            : app(ExecutionContractService::class)->for($agent->symbol, $agent->timeframe);
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
        app(\App\Services\CouncilDisagreementService::class)->recordResult($screenResult, [
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

        $generation = $agent->generation()->with('agents')->first();
        if ($generation->agents->whereIn('lifecycle_status', [
            'draft', 'queued', 'screening', 'evaluation_error',
            'full_queued', 'full_validation', 'training',
        ])->isEmpty()) {
            $this->generationContext->updateWithAttributes($generation, [
                'status' => 'screened',
                'completed_at' => now(),
            ], function (array $context): array {
                $context['screening_terminal'] = [
                    'protocol' => 'generation_terminal_boundary_v1',
                    'status' => 'screened',
                    'completed_at' => now()->utc()->toIso8601String(),
                    'all_agents_terminal' => true,
                    'promotion_evidence' => false,
                ];

                return $context;
            });
            app(LabGenerationReportService::class)->record($generation->fresh(), 'screening_completed');
        }
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

    private function volumeContextOrFail(string $symbol, string $timeframe): array
    {
        $quality = $this->volumes->inspect($symbol, $timeframe);
        if (data_get($quality, 'status') !== 'passed') {
            throw new RuntimeException("{$symbol} {$timeframe} canonical volume quality gate failed.");
        }

        return $quality;
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
            throw new RuntimeException('AI replay lane is busy; strategy verdict withheld for bounded retry.');
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

    /** A differential child may improve only its declared target lane. */
    private function appendDifferentialNoRegressionEvidence(
        $model,
        array $result,
        ?string $strategyFamily = null,
        ?string $symbol = null,
        ?string $timeframe = null,
    ): array
    {
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
