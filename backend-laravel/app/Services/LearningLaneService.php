<?php

namespace App\Services;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AgentLearningLesson;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabLearningLaneDispatch;
use App\Models\LabLearningLanePair;
use App\Models\LabEvaluationRun;
use App\Models\LabMutationResponseMap;
use App\Models\ModelMarketPerformance;
use App\Services\MicroReplayService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Research-only two-speed evolution lane.
 *
 * The service makes a failed screen useful only when its result is paired
 * with a same-contract control/parent/anchor baseline.  It may create a
 * provisional skill and queue a bounded learning replay, but it never opens
 * a paper, forward or genetic-parent gate.
 */
class LearningLaneService
{
    public const PROTOCOL = 'learning_lane_v1';
    public const PAIR_PROTOCOL = 'paired_control_ledger_v1';

    /** @return array<string, mixed>|null */
    public function pairScreeningObservation(
        LabAgent $agent,
        array $result = [],
        ?array $responseMap = null,
    ): ?array {
        if (! $this->available()) return null;

        $agent->loadMissing('modelVersion', 'generation');
        $map = $responseMap && isset($responseMap['id'])
            ? LabMutationResponseMap::query()->find($responseMap['id'])
            : LabMutationResponseMap::query()
                ->where('lab_agent_id', $agent->id)
                ->where('stage', 'screening')
                ->when(data_get($result, 'evidence_run_id'), fn ($query, $run) => $query->where('evidence_run_id', $run))
                ->latest('id')
                ->first();
        if (! $map || $map->status === 'control') return null;
        if ($map->status === 'behavioral_duplicate'
            || (bool) data_get($map->metadata, 'behavioral_duplicate', false)) {
            // The response surface remains immutable and visible, but an
            // identical decision/trade trace is not a new evolution seat.
            return null;
        }

        $control = $this->resolveControl($agent, $map);
        $controlVerified = $this->isVerifiedControl($control);
        $candidateDataHash = $this->snapshotHashOf($map);
        $candidateExecutionHash = $this->executionHashOf($map);
        $controlDataHash = (string) data_get($control, 'data_hash', '');
        $controlExecutionHash = (string) data_get($control, 'execution_hash', '');
        $sameGeneration = $controlVerified
            && (int) data_get($control, 'generation_id', 0) === (int) $agent->lab_generation_id;
        $pairIntegrityStatus = $controlVerified && $sameGeneration
            && $candidateDataHash !== '' && $candidateExecutionHash !== ''
            && $controlDataHash !== '' && $controlExecutionHash !== ''
            ? 'verified'
            : 'diagnostic_only';
        $candidateMetrics = (array) $map->observed_metrics;
        // A parent/anchor/baseline is useful diagnostic context, but it is
        // not a causal control. Only a same-snapshot and same-execution
        // frozen control may populate the learning ledger.
        $controlMetrics = $controlVerified ? (array) data_get($control, 'metrics', []) : [];
        $target = (string) ($map->target ?: data_get($agent->modelVersion?->metadata, 'generation_target', ''));
        $targetDelta = $controlVerified
            ? app(MutationResponseMapService::class)->targetDelta($target, $controlMetrics, $candidateMetrics)
            : [
                'status' => 'missing_control',
                'improved' => false,
                'reason' => 'CONTROL_PAIR_REQUIRED',
                'promotion_evidence' => false,
            ];
        $signature = app(FailureSignatureCompilerService::class)->compile(
            $agent,
            $target,
            [...$result, 'failure_reason' => data_get($map->metadata, 'screening_decision')],
            (string) data_get($map->metadata, 'screening_decision', 'SCREEN_OBSERVATION'),
        );
        $causalSkill = app(CausalSkillCompilerService::class)->compile(
            $agent,
            $signature,
            [
                ...$result,
                'control_pair_available' => $controlVerified,
                'same_snapshot' => (bool) data_get($control, 'same_snapshot', false),
                'same_execution_contract' => (bool) data_get($control, 'same_execution_contract', false),
                'mutation_observability' => [
                    ...((array) data_get($result, 'mutation_observability', [])),
                    'behavioral_delta' => $targetDelta,
                ],
            ],
            $targetDelta,
        );
        $pairKey = hash('sha256', json_encode([
            self::PAIR_PROTOCOL,
            $map->id,
            $target,
            $this->snapshotHashOf($map) ?: data_get($result, 'data_manifest.sha256', data_get($result, 'data_manifest.snapshot_sha256')),
            $this->windowKey($result),
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $baselineSource = (string) data_get($control, 'source', 'missing');
        $pair = LabLearningLanePair::query()
            ->where('candidate_response_map_id', $map->id)
            ->where('target', $target !== '' ? $target : null)
            ->whereNotIn('status', ['superseded'])
            ->latest('id')
            ->first();
        if (! $pair) {
            $pair = LabLearningLanePair::query()->firstOrCreate(
                ['pair_key' => $pairKey],
                [
                'lab_generation_id' => $agent->lab_generation_id,
                'candidate_agent_id' => $agent->id,
                'control_agent_id' => $controlVerified ? data_get($control, 'agent_id') : null,
                'candidate_response_map_id' => $map->id,
                'control_response_map_id' => $controlVerified ? data_get($control, 'map_id') : null,
                'symbol' => strtoupper((string) $agent->symbol),
                'timeframe' => strtoupper((string) $agent->timeframe),
                'strategy_family' => (string) $agent->strategy_family,
                'target' => $target !== '' ? $target : null,
                'specialist_role' => data_get($signature, 'specialist_role'),
                'baseline_source' => $baselineSource,
                'status' => $controlVerified ? 'screen_paired' : 'missing_control',
                'candidate_evidence_run_id' => $map->evidence_run_id,
                'control_evidence_run_id' => $controlVerified ? data_get($control, 'evidence_run_id') : null,
                'candidate_data_hash' => $candidateDataHash !== '' ? $candidateDataHash : null,
                'control_data_hash' => $controlVerified && $controlDataHash !== '' ? $controlDataHash : null,
                'candidate_execution_hash' => $candidateExecutionHash !== '' ? $candidateExecutionHash : null,
                'control_execution_hash' => $controlVerified && $controlExecutionHash !== '' ? $controlExecutionHash : null,
                'pair_integrity_status' => $pairIntegrityStatus,
                'same_generation' => $sameGeneration,
                'independent_window_key' => $this->windowKey($result),
                'candidate_metrics' => $candidateMetrics,
                'control_metrics' => $controlMetrics,
                'target_delta' => $targetDelta,
                'non_target_regression' => (array) ($map->non_target_regression ?? data_get($result, 'no_regression_contract', [])),
                'failure_signature' => $signature,
                'metadata' => [
                    'protocol' => self::PAIR_PROTOCOL,
                    'screening_decision' => data_get($map->metadata, 'screening_decision'),
                    'control_quality' => data_get($control, 'quality'),
                    'control_scope' => data_get($control, 'scope'),
                    'same_snapshot' => $controlVerified && (bool) data_get($control, 'same_snapshot', false),
                    'same_execution_contract' => $controlVerified && (bool) data_get($control, 'same_execution_contract', false),
                    'control_pair_status' => $controlVerified ? 'verified' : 'missing_control',
                    'pair_integrity_status' => $pairIntegrityStatus,
                    'same_generation' => $sameGeneration,
                    'baseline_is_diagnostic_only' => ! $controlVerified,
                    'causal_skill_compiler' => $causalSkill,
                    'promotion_evidence' => false,
                ],
                ],
            );
        }

        $microFrozen = in_array((string) $pair->status, ['micro_failed', 'micro_deferred'], true);
        if (! $microFrozen) {
            $pair->update([
                'status' => $controlVerified ? 'screen_paired' : 'missing_control',
                'pair_key' => $pair->pair_key ?: $pairKey,
                'lab_generation_id' => $agent->lab_generation_id,
                'candidate_agent_id' => $agent->id,
                'control_agent_id' => $controlVerified ? data_get($control, 'agent_id') : null,
                'control_response_map_id' => $controlVerified ? data_get($control, 'map_id') : null,
                'control_evidence_run_id' => $controlVerified ? data_get($control, 'evidence_run_id') : null,
                'candidate_data_hash' => $candidateDataHash !== '' ? $candidateDataHash : null,
                'control_data_hash' => $controlVerified && $controlDataHash !== '' ? $controlDataHash : null,
                'candidate_execution_hash' => $candidateExecutionHash !== '' ? $candidateExecutionHash : null,
                'control_execution_hash' => $controlVerified && $controlExecutionHash !== '' ? $controlExecutionHash : null,
                'pair_integrity_status' => $pairIntegrityStatus,
                'same_generation' => $sameGeneration,
                'control_metrics' => $controlVerified ? $controlMetrics : [],
                'target_delta' => $targetDelta,
                'baseline_source' => $baselineSource,
                'metadata' => [
                    ...((array) $pair->metadata),
                    'control_quality' => data_get($control, 'quality'),
                    'control_scope' => data_get($control, 'scope'),
                    'same_snapshot' => $controlVerified && (bool) data_get($control, 'same_snapshot', false),
                    'same_execution_contract' => $controlVerified && (bool) data_get($control, 'same_execution_contract', false),
                    'control_pair_status' => $controlVerified ? 'verified' : 'missing_control',
                    'pair_integrity_status' => $pairIntegrityStatus,
                    'same_generation' => $sameGeneration,
                    'baseline_is_diagnostic_only' => ! $controlVerified,
                    'causal_skill_compiler' => $causalSkill,
                    'promotion_evidence' => false,
                ],
            ]);
        }

        $freshPair = $pair->fresh();
        app(LearningMemoryService::class)->recordPair($freshPair, 'screening');
        app(FailureDojoService::class)->recordPair($freshPair);
        $this->recordProvisionalSkill($freshPair, 'screening');

        return $freshPair->toArray();
    }

    /** Pair older rows after a control finished later than the candidate. */
    public function pairUnpairedScreeningObservations(
        ?string $symbol = null,
        ?string $timeframe = null,
        ?string $family = null,
        int $limit = 500,
    ): int {
        if (! $this->available()) return 0;

        $maps = LabMutationResponseMap::query()
            ->with(['agent.modelVersion', 'agent.generation'])
            ->where('stage', 'screening')
            ->where('status', '!=', 'control')
            ->when($symbol, fn ($query) => $query->where('symbol', strtoupper($symbol)))
            ->when($timeframe, fn ($query) => $query->where('timeframe', strtoupper($timeframe)))
            ->when($family, fn ($query) => $query->where('strategy_family', $family))
            ->latest('id')
            ->limit(max(1, $limit))
            ->get();
        $count = 0;
        foreach ($maps as $map) {
            if (! $map->agent) continue;
            $this->pairScreeningObservation(
                $map->agent,
                ['evidence_run_id' => $map->evidence_run_id, ...((array) $map->observed_metrics)],
                $map->toArray(),
            );
            $count++;
        }

        return $count;
    }

    /** Read-only preview for the operator-approved control materializer. */
    public function controlMaterializationPreview(
        string $symbol,
        string $timeframe,
        ?string $family = null,
        int $limit = 500,
    ): array {
        if (! $this->available()) return ['available' => false, 'missing' => 0, 'pairable' => 0];
        $requestedLimit = max(1, $limit);
        // This is an operator preview, not a bulk repair worker. Keep one
        // invocation bounded so a large legacy backlog cannot consume the
        // same CPU/DB budget as live replay workers; callers can page with
        // smaller bounded invocations when they need a complete audit.
        $scanLimit = min(
            $requestedLimit,
            max(1, (int) config('services.learning_lane.materialization_preview_limit', 50)),
        );
        $pairs = LabLearningLanePair::query()
            ->with(['candidateAgent', 'candidateResponseMap'])
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('status', 'missing_control')
            ->when($family, fn ($query) => $query->where('strategy_family', $family))
            ->latest('id')->limit($scanLimit)->get();
        // Resolve every candidate against one immutable control snapshot.
        // Calling resolveControl() with its default query inside this loop
        // turned a read-only preview of the legacy rows into an N+1 scan and
        // could starve the live evidence workers.
        $controls = LabMutationResponseMap::query()
            ->with('agent')
            ->where('stage', 'screening')
            ->where('status', 'control')
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->latest('id')
            ->get();
        $pairable = 0;
        foreach ($pairs as $pair) {
            if (! $pair->candidateAgent || ! $pair->candidateResponseMap) continue;
            $control = $this->resolveControl($pair->candidateAgent, $pair->candidateResponseMap, $controls);
            if ($this->isVerifiedControl($control)) {
                $pairable++;
            }
        }
        return [
            'available' => true,
            'missing' => $pairs->count(),
            'pairable' => $pairable,
            'limit' => $scanLimit,
            'requested_limit' => $requestedLimit,
            'truncated' => $requestedLimit > $scanLimit,
        ];
    }

    /**
     * Reconcile legacy pair rows against the strict control identity rule.
     * Historical rows are never deleted or rewritten into promotion evidence;
     * an unverified screen/provisional row is simply returned to
     * `missing_control` until a real frozen control is materialized.
     *
     * @return array<string, mixed>
     */
    public function reconcileControlPairs(
        string $symbol,
        string $timeframe,
        ?string $family = null,
        int $limit = 1000,
        bool $apply = false,
    ): array {
        if (! $this->available()) return ['available' => false, 'inspected' => 0, 'invalid' => 0, 'reconciled' => 0];

        $pairs = LabLearningLanePair::query()
            ->with(['candidateAgent.modelVersion', 'candidateResponseMap'])
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->when($family, fn ($query) => $query->where('strategy_family', $family))
            ->whereIn('status', ['screen_paired', 'provisional', 'learning_queued', 'learning_observed', 'confirmed'])
            ->latest('id')
            ->limit(max(1, $limit))
            ->get();
        $invalid = 0;
        $reconciled = 0;
        foreach ($pairs as $pair) {
            $agent = $pair->candidateAgent;
            $map = $pair->candidateResponseMap;
            if (! $agent || ! $map) continue;
            $control = $this->resolveControl($agent, $map);
            if ($this->isVerifiedControl($control)) {
                $reconciled++;
                $controlMetrics = (array) data_get($control, 'metrics', []);
                $target = (string) ($pair->target ?: $map->target ?: data_get($agent->modelVersion?->metadata, 'generation_target', ''));
                $targetDelta = app(MutationResponseMapService::class)->targetDelta(
                    $target,
                    $controlMetrics,
                    (array) $map->observed_metrics,
                );
                if ($apply) {
                    $pair->update([
                        'control_agent_id' => data_get($control, 'agent_id'),
                        'control_response_map_id' => data_get($control, 'map_id'),
                        'control_evidence_run_id' => data_get($control, 'evidence_run_id'),
                        'candidate_data_hash' => $this->snapshotHashOf($map) ?: null,
                        'control_data_hash' => data_get($control, 'data_hash') ?: null,
                        'candidate_execution_hash' => $this->executionHashOf($map) ?: null,
                        'control_execution_hash' => data_get($control, 'execution_hash') ?: null,
                        'pair_integrity_status' => 'verified',
                        'same_generation' => true,
                        'control_metrics' => $controlMetrics,
                        'target_delta' => $targetDelta,
                        'baseline_source' => (string) data_get($control, 'source', 'control'),
                        'metadata' => [
                            ...((array) $pair->metadata),
                            'control_pair_status' => 'verified',
                            'pair_integrity_status' => 'verified',
                            'same_generation' => true,
                            'same_snapshot' => true,
                            'same_execution_contract' => true,
                            'baseline_is_diagnostic_only' => false,
                            'promotion_evidence' => false,
                        ],
                    ]);
                }
                continue;
            }
            $invalid++;
            if (! $apply) continue;
            $pair->update([
                'status' => 'missing_control',
                'control_agent_id' => null,
                'control_response_map_id' => null,
                'control_evidence_run_id' => null,
                'control_metrics' => [],
                'candidate_data_hash' => $this->snapshotHashOf($map) ?: null,
                'control_data_hash' => null,
                'candidate_execution_hash' => $this->executionHashOf($map) ?: null,
                'control_execution_hash' => null,
                'pair_integrity_status' => 'diagnostic_only',
                'same_generation' => false,
                'target_delta' => [
                    'status' => 'missing_control',
                    'improved' => false,
                    'reason' => 'CONTROL_PAIR_REQUIRED',
                    'promotion_evidence' => false,
                ],
                'metadata' => [
                    ...((array) $pair->metadata),
                    'control_pair_status' => 'missing_control',
                    'pair_integrity_status' => 'diagnostic_only',
                    'same_generation' => false,
                    'baseline_is_diagnostic_only' => true,
                    'reconciled_at' => now()->utc()->toIso8601String(),
                    'promotion_evidence' => false,
                ],
            ]);
        }

        return [
            'available' => true,
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'family' => $family,
            'limit' => max(1, $limit),
            'apply' => $apply,
            'inspected' => $pairs->count(),
            'invalid' => $invalid,
            'reconciled' => $reconciled,
            'promotion_evidence' => false,
        ];
    }

    /** @return Collection<int, LabLearningLanePair> */
    public function frontier(
        string $symbol,
        string $timeframe,
        ?string $family = null,
        int $limit = 4,
        bool $refreshPairs = true,
    ): Collection {
        if (! $this->available() || ! (bool) config('services.learning_lane.enabled', true)) return collect();

        if ($refreshPairs) {
            $this->pairUnpairedScreeningObservations($symbol, $timeframe, $family);
        }
        $dispatched = LabLearningLaneDispatch::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->whereIn('status', ['selected', 'queued', 'running', 'completed'])
            ->pluck('lab_agent_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $pairs = LabLearningLanePair::query()
            ->with(['candidateAgent.modelVersion', 'candidateAgent.generation'])
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->when($family, fn ($query) => $query->where('strategy_family', $family))
            ->whereIn('status', ['screen_paired', 'provisional'])
            ->whereNotNull('candidate_agent_id')
            ->latest('id')
            ->get()
            ->reject(fn (LabLearningLanePair $pair): bool => in_array((int) $pair->candidate_agent_id, $dispatched, true))
            ->filter(fn (LabLearningLanePair $pair): bool => $pair->candidateAgent !== null
                && in_array((string) $pair->candidateAgent->lifecycle_status, ['screened', 'challenger', 'rejected', 'stagnated'], true)
                && $this->pairHasVerifiedControl($pair)
                && is_numeric(data_get($pair->target_delta, 'delta')))
            ->sortByDesc(fn (LabLearningLanePair $pair): array => [
                (bool) data_get($pair->target_delta, 'improved', false) ? 1 : 0,
                $this->targetUtility((string) $pair->target, (float) data_get($pair->target_delta, 'delta', 0)),
                data_get($pair->metadata, 'causal_skill_compiler.reusable_lesson.status') === 'reusable' ? 1 : 0,
                (float) data_get($pair->metadata, 'information_gain_priority.score', data_get($pair->metadata, 'causal_skill_compiler.information_gain_priority.score', 0)),
                (int) $pair->id,
            ])
            ->values();

        // One seat per role is the default. A second seat is available only
        // when the operator explicitly raises the per-role budget.
        $perRole = max(1, (int) config('services.learning_lane.max_per_role', 1));
        $roleLimited = $pairs
            ->groupBy(fn (LabLearningLanePair $pair): string => (string) ($pair->specialist_role ?: 'unassigned'))
            ->flatMap(fn (Collection $rows): Collection => $rows->take($perRole))
            ->values();
        $perGeneration = max(1, (int) config('services.learning_lane.max_total_per_generation', 4));

        return $roleLimited
            ->groupBy(fn (LabLearningLanePair $pair): string => (string) ($pair->lab_generation_id ?: 'unknown'))
            ->flatMap(fn (Collection $rows): Collection => $rows->take($perGeneration))
            ->take(max(1, $limit))
            ->values();
    }

    /**
     * Revisit micro seats left pending by a worker/recovery event before
     * opening a newer frontier seat. A pending seat already consumed a
     * bounded learning allocation and must not be silently starved.
     *
     * @return Collection<int, LabLearningLanePair>
     */
    public function pendingMicroPairs(
        string $symbol,
        string $timeframe,
        ?string $family = null,
        int $limit = 4,
    ): Collection {
        if (! $this->available()) return collect();

        return LabLearningLaneDispatch::query()
            ->with(['pair.candidateAgent.modelVersion', 'pair.candidateAgent.generation'])
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->when($family, fn ($query) => $query->where('strategy_family', $family))
            ->where('stage', 'micro')
            ->where('micro_status', 'pending')
            ->whereIn('status', ['retry_ready', 'selected'])
            ->whereHas('pair', fn ($query) => $query->whereIn('status', ['screen_paired', 'provisional']))
            ->oldest('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (LabLearningLaneDispatch $dispatch): ?LabLearningLanePair => $dispatch->pair)
            ->filter()
            ->filter(fn (LabLearningLanePair $pair): bool => $this->pairHasVerifiedControl($pair))
            ->unique('id')
            ->values();
    }

    /**
     * Close recovery rows whose pair was already terminalized by another
     * reconciliation pass. This keeps the dispatch ledger idempotent when an
     * old dispatch key no longer matches the pair's reconciled delta.
     */
    public function reconcileStaleMicroDispatches(
        string $symbol,
        string $timeframe,
        ?string $family = null,
    ): int {
        if (! $this->available()) return 0;

        $rows = LabLearningLaneDispatch::query()
            ->with('pair')
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->when($family, fn ($query) => $query->where('strategy_family', $family))
            ->where('stage', 'micro')
            ->where('micro_status', 'pending')
            ->whereIn('status', ['retry_ready', 'selected'])
            ->get();
        $closed = 0;
        foreach ($rows as $dispatch) {
            $pair = $dispatch->pair;
            if (! $pair) continue;
            $pairStatus = (string) $pair->status;
            if (! in_array($pairStatus, ['micro_failed', 'superseded'], true)) continue;
            $micro = (array) data_get($pair->metadata, 'micro_replay', []);
            $dispatch->update([
                'status' => $pairStatus === 'micro_failed' ? 'micro_failed' : 'superseded',
                'micro_status' => $pairStatus === 'micro_failed' ? 'failed' : 'deferred',
                'micro_completed_at' => now(),
                'micro_metadata' => $micro !== [] ? $micro : [
                    'protocol' => MicroReplayService::PROTOCOL,
                    'status' => $pairStatus === 'micro_failed' ? 'failed' : 'deferred',
                    'reason' => 'STALE_PENDING_MICRO_RECONCILED',
                ],
                'metadata' => [
                    ...((array) $dispatch->metadata),
                    'recovery_protocol' => 'stale_micro_dispatch_reconciled_v1',
                    'pair_status' => $pairStatus,
                    'promotion_evidence' => false,
                ],
            ]);
            $closed++;
        }

        return $closed;
    }

    /**
     * Collapse duplicate observations from the same candidate/control/data
     * cell.  A queued or confirmed pair always wins; immutable response maps
     * themselves are never deleted or rewritten.
     */
    public function deduplicatePairs(
        ?string $symbol = null,
        ?string $timeframe = null,
        ?string $family = null,
    ): int {
        if (! $this->available()) return 0;

        $pairs = LabLearningLanePair::query()
            ->with('candidateResponseMap')
            ->whereNotIn('status', ['superseded'])
            ->when($symbol, fn ($query) => $query->where('symbol', strtoupper($symbol)))
            ->when($timeframe, fn ($query) => $query->where('timeframe', strtoupper($timeframe)))
            ->when($family, fn ($query) => $query->where('strategy_family', $family))
            ->orderBy('id')
            ->get();
        $superseded = 0;
        foreach ($pairs->groupBy(fn (LabLearningLanePair $pair): string => $this->stablePairCell($pair)) as $rows) {
            if ($rows->count() < 2) continue;
            $ids = $rows->pluck('id')->all();
            $activeIds = LabLearningLaneDispatch::query()
                ->whereIn('pair_id', $ids)
                ->where(function ($query): void {
                    $query->whereIn('status', ['selected', 'queued', 'running', 'completed'])
                        ->orWhere(function ($micro): void {
                            $micro->whereIn('status', ['retry_ready', 'selected'])
                                ->where('stage', 'micro')
                                ->where('micro_status', 'pending');
                        });
                })
                ->pluck('pair_id')->map(fn ($id): int => (int) $id)->all();
            $keep = $rows->sortByDesc(fn (LabLearningLanePair $pair): array => [
                in_array((int) $pair->id, $activeIds, true) ? 1 : 0,
                $pair->status === 'confirmed' ? 1 : 0,
                $pair->status === 'provisional' ? 1 : 0,
                (int) $pair->id,
            ])->first();
            foreach ($rows as $pair) {
                if ((int) $pair->id === (int) $keep->id) continue;
                $pair->update([
                    'status' => 'superseded',
                    'metadata' => [
                        ...((array) $pair->metadata),
                        'superseded_by_pair_id' => $keep->id,
                        'superseded_reason' => 'DUPLICATE_LEARNING_CELL',
                        'promotion_evidence' => false,
                    ],
                ]);
                $superseded++;
            }
        }

        return $superseded;
    }

    /**
     * Re-open only research dispatches whose batch is already terminal. This
     * is for worker restarts/old admission code; an active batch is never
     * duplicated and no immutable replay artifact is rewritten.
     */
    public function recoverQueuedDispatches(
        string $symbol,
        string $timeframe,
        ?string $family = null,
    ): int {
        // Generation creation can be paused while this research-only lane
        // remains open. Promotion is closed elsewhere; learning must not
        // cold-restart merely because the normal generation funnel is paused.
        if (! $this->available()) {
            return 0;
        }

        // Older cancellation recovery wrote cancelled_at but left
        // finished_at null. That row is already terminal by definition; close
        // only this invariant gap so historical batch metadata cannot keep a
        // research dispatch looking active forever. No strategy or promotion
        // state is changed.
        DB::table('job_batches')
            ->whereNotNull('cancelled_at')
            ->whereNull('finished_at')
            ->update(['finished_at' => DB::raw('cancelled_at')]);

        $dispatches = LabLearningLaneDispatch::query()
            ->with(['pair', 'agent'])
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->when($family, fn ($query) => $query->where('strategy_family', $family))
            ->whereIn('status', ['queued', 'running', 'retry_ready'])
            ->get();
        $recovered = 0;
        $requeuedBatches = [];
        $cancelledBatches = [];
        $extendedBatches = [];
        $fullQueue = (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation');
        $queueState = app(LabQueueJobInspector::class);
        if ((string) config('queue.default', 'database') === 'redis') {
            $queueSnapshot = $queueState->queueSnapshot([$fullQueue, 'lab-full-hold']);
            if (($queueSnapshot['available'] ?? false) !== true) return 0;
            $batchJobs = collect((array) ($queueSnapshot['rows'] ?? []))
                ->map(fn (array $row): object => (object) $row);
        } else {
            $batchJobs = DB::table('jobs')
                ->whereIn('queue', [$fullQueue, 'lab-full-hold'])
                ->get(['id', 'queue', 'payload']);
        }
        $heldJobs = $batchJobs->where('queue', 'lab-full-hold');
        foreach ($dispatches as $dispatch) {
            $batch = $dispatch->queue_batch_id
                ? DB::table('job_batches')->where('id', $dispatch->queue_batch_id)->first()
                : null;
            $jobsForBatch = $dispatch->queue_batch_id
                ? $batchJobs->filter(fn ($job): bool => (string) data_get(json_decode((string) $job->payload, true), 'data.batchId', '') === (string) $dispatch->queue_batch_id)
                : collect();
            $heldForBatch = $dispatch->queue_batch_id
                ? $heldJobs->filter(fn ($job): bool => (string) data_get(json_decode((string) $job->payload, true), 'data.batchId', '') === (string) $dispatch->queue_batch_id)
                : collect();
            $orphanBatch = (bool) ($batch
                && $batch->finished_at === null
                && $batch->cancelled_at === null
                && $jobsForBatch->isEmpty()
                && ! DB::table('lab_evaluation_runs')
                    ->where('lab_agent_id', $dispatch->lab_agent_id)
                    ->where('status', 'started')
                    ->exists());
            $terminalBatch = (bool) ($batch
                && ($batch->finished_at !== null || $batch->cancelled_at !== null));
            if ($orphanBatch && ! isset($cancelledBatches[$dispatch->queue_batch_id])) {
                $cancelledAt = now()->timestamp;
                DB::table('job_batches')->where('id', $dispatch->queue_batch_id)->update([
                    // Laravel's default job_batches migration stores these
                    // lifecycle fields as Unix integers (not datetimes).
                    // Writing Carbon here is silently truncated by MySQL and
                    // leaves the learning dispatch permanently `running`.
                    // A cancelled batch is also terminal: leaving
                    // finished_at null makes later queue monitors treat the
                    // historical row as unfinished work.
                    'cancelled_at' => $cancelledAt,
                    'finished_at' => $cancelledAt,
                ]);
                $cancelledBatches[$dispatch->queue_batch_id] = true;
            }
            $activeHeldBatch = $batch && $batch->finished_at === null && ! $orphanBatch && $heldForBatch->isNotEmpty();
            if ($batch && $batch->finished_at === null && ! $orphanBatch && $jobsForBatch->isNotEmpty() && ! isset($extendedBatches[$dispatch->queue_batch_id])) {
                $extended = 0;
                foreach ($jobsForBatch as $batchJob) {
                    $payload = json_decode((string) $batchJob->payload, true);
                    $command = (string) data_get($payload, 'data.command', '');
                    $decoded = $command !== '' ? @unserialize($command) : null;
                    if (! is_object($decoded) || ! property_exists($decoded, 'retryDeadline')) continue;
                    $decoded->retryDeadline = now()->utc()->addMinutes(180);
                    data_set($payload, 'data.command', serialize($decoded));
                    if ((string) config('queue.default', 'database') !== 'redis') {
                        DB::table('jobs')->where('id', $batchJob->id)->update(['payload' => json_encode($payload, JSON_UNESCAPED_SLASHES)]);
                    }
                    $extended++;
                }
                $extendedBatches[$dispatch->queue_batch_id] = $extended;
            }
            if ($activeHeldBatch && ! isset($requeuedBatches[$dispatch->queue_batch_id])) {
                $jobIds = [];
                foreach ($heldForBatch as $heldJob) {
                    $moved = (string) config('queue.default', 'database') === 'redis'
                        ? $queueState->movePendingPayload('lab-full-hold', $fullQueue, (string) $heldJob->payload)
                        : (bool) DB::table('jobs')->where('id', $heldJob->id)->where('queue', 'lab-full-hold')->update([
                            'queue' => $fullQueue, 'reserved_at' => null, 'available_at' => now()->timestamp,
                        ]);
                    if ($moved) $jobIds[] = $heldJob->id;
                }
                $requeuedBatches[$dispatch->queue_batch_id] = $jobIds;
            }
            if ($batch && $batch->finished_at === null && ! $activeHeldBatch && ! $orphanBatch && ! $terminalBatch) {
                if ($jobsForBatch->isNotEmpty()) {
                    $dispatch->update([
                        'status' => 'queued',
                        'metadata' => [
                            ...((array) $dispatch->metadata),
                            'recovery_protocol' => 'learning_lane_queue_reconciled_v1',
                            'queue_job_present' => true,
                            'promotion_evidence' => false,
                        ],
                    ]);
                }
                continue;
            }

            $dispatch->update([
                'status' => ($requeuedBatches[$dispatch->queue_batch_id] ?? []) !== [] ? 'queued' : 'retry_ready',
                'completed_at' => null,
                'metadata' => [
                    ...((array) $dispatch->metadata),
                    'recovery_protocol' => 'learning_lane_terminal_batch_recovery_v1',
                    'recovered_at' => now()->utc()->toIso8601String(),
                    'requeued_held_job_ids' => $requeuedBatches[$dispatch->queue_batch_id] ?? [],
                    'orphan_batch_cancelled' => $orphanBatch,
                    'retry_deadline_extended_jobs' => $extendedBatches[$dispatch->queue_batch_id] ?? 0,
                    'promotion_evidence' => false,
                ],
            ]);
            if ($dispatch->pair) {
                $pendingMicro = (string) $dispatch->stage === 'micro'
                    && (string) $dispatch->micro_status === 'pending';
                $dispatch->pair->update([
                    // A pending micro seat is not a terminal full replay. Do
                    // not rewrite its pair state during queue recovery; the
                    // micro pump must decide whether it passes or fails.
                    'status' => $pendingMicro
                        ? $dispatch->pair->status
                        : (data_get($dispatch->pair->target_delta, 'improved', false)
                            ? 'screen_paired' : 'provisional'),
                    'metadata' => [
                        ...((array) $dispatch->pair->metadata),
                        'recovery_dispatch_id' => $dispatch->id,
                        'promotion_evidence' => false,
                    ],
                ]);
            }
            if ($dispatch->agent) {
                $recoveredAgent = $dispatch->agent->fresh(['modelVersion', 'generation']);
                $performance = $recoveredAgent?->model_version_id
                    ? ModelMarketPerformance::query()
                        ->where('model_version_id', $recoveredAgent->model_version_id)
                        ->where('symbol', $recoveredAgent->symbol)
                        ->where('timeframe', $recoveredAgent->timeframe)
                        ->latest('id')
                        ->first()
                    : null;
                $latestFullRun = LabEvaluationRun::query()
                    ->where('lab_agent_id', $recoveredAgent?->id)
                    ->where('phase', 'full_validation')
                    ->where('status', 'completed')
                    ->latest('id')
                    ->first();
                $sealedEvidenceComplete = $latestFullRun !== null
                    && app(LabImmutableEvidenceService::class)->learningEligibility($latestFullRun)['complete'] === true;

                if ($performance !== null) {
                    // A late queue callback must not move an already projected
                    // candidate backwards. Restore the operational lifecycle
                    // from the sealed performance projection only.
                    $recoveredAgent->update([
                        'lifecycle_status' => (string) $performance->status,
                        'decision_reason' => 'Learning-lane recovery reconciled an already projected full replay; promotion remains blocked.',
                    ]);
                } elseif ($sealedEvidenceComplete) {
                    // The immutable replay is valid but its mutable learning
                    // projection was interrupted. Return the agent to the
                    // research-screened state so the sealed cache can be
                    // consumed exactly once by the bounded retry path. No
                    // strategy verdict, parent credit or paper evidence is
                    // invented here.
                    $recoveredAgent->update([
                        'lifecycle_status' => 'screened',
                        'decision_reason' => 'Sealed full evidence exists but learning projection was interrupted; projection retry is allowed, promotion remains blocked.',
                    ]);
                    $dispatch->update([
                        'metadata' => [
                            ...((array) $dispatch->metadata),
                            'recovery_protocol' => 'learning_lane_terminal_evidence_projection_retry_v1',
                            'sealed_full_run_id' => $latestFullRun->run_id,
                            'projection_retry_allowed' => true,
                            'promotion_evidence' => false,
                        ],
                    ]);
                } elseif (in_array((string) $recoveredAgent->lifecycle_status, ['screened', 'evaluation_error', 'technical_quarantine'], true)) {
                    $recoveredAgent->update([
                        'lifecycle_status' => 'screened',
                        'decision_reason' => 'Learning-lane replay reopened after explicit technical/worker recovery; promotion remains blocked.',
                    ]);
                }
            }
            $recovered++;
        }

        return $recovered;
    }

    /** @return array<string, mixed> */
    public function bestProvisionalFor(
        string $symbol,
        string $timeframe,
        string $family,
        string $target,
        ?string $role = null,
    ): ?array {
        if (! $this->available()) return null;

        $lessons = AgentLearningLesson::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('strategy_family', $family)
            ->where('lesson_type', 'skill_lesson')
            ->whereIn('status', ['provisional', 'confirmed'])
            ->where('failure_class', $target)
            ->latest('id')
            ->get()
            ->when($role !== null && $role !== '', fn (Collection $rows): Collection => $rows->filter(
                fn (AgentLearningLesson $lesson): bool => (string) data_get($lesson->evidence, 'specialist_role', '') === $role,
            ))
            ->filter(fn (AgentLearningLesson $lesson): bool => filled($lesson->parameter_key)
                && $this->lessonHasVerifiedControlPair($lesson));
        $lesson = $lessons->sortByDesc(fn (AgentLearningLesson $row): array => [
            $row->status === 'confirmed' ? 1 : 0,
            $this->targetUtility($target, (float) data_get($row->evidence, 'target_delta.delta', 0)),
            (int) $row->id,
        ])->first();
        if (! $lesson) return null;

        return [
            'lesson_id' => (int) $lesson->id,
            'parameter_key' => $lesson->parameter_key,
            'direction' => data_get($lesson->evidence, 'direction'),
            'target' => $target,
            'status' => $lesson->status,
            'target_delta' => data_get($lesson->evidence, 'target_delta', []),
            'specialist_role' => data_get($lesson->evidence, 'specialist_role'),
            'research_only' => true,
            'promotion_evidence' => false,
        ];
    }

    private function lessonHasVerifiedControlPair(AgentLearningLesson $lesson): bool
    {
        $pairId = (int) data_get($lesson->evidence, 'pair_id', 0);
        if ($pairId <= 0) return false;
        $pair = LabLearningLanePair::query()->find($pairId);
        if (! $pair) return false;

        return $this->pairHasVerifiedControl($pair);
    }

    private function pairHasVerifiedControl(LabLearningLanePair $pair): bool
    {
        return in_array((string) $pair->status, [
            'screen_paired', 'provisional', 'learning_queued', 'learning_observed', 'confirmed',
        ], true)
            && (string) $pair->pair_integrity_status === 'verified'
            && (bool) $pair->same_generation
            && (int) $pair->control_response_map_id > 0
            && (array) $pair->control_metrics !== []
            && filled($pair->candidate_data_hash)
            && filled($pair->control_data_hash)
            && filled($pair->candidate_execution_hash)
            && filled($pair->control_execution_hash)
            && hash_equals((string) $pair->candidate_data_hash, (string) $pair->control_data_hash)
            && hash_equals((string) $pair->candidate_execution_hash, (string) $pair->control_execution_hash)
            && (bool) data_get($pair->metadata, 'same_snapshot', false)
            && (bool) data_get($pair->metadata, 'same_execution_contract', false)
            && LabMutationResponseMap::query()
                ->whereKey($pair->control_response_map_id)
                ->where('status', 'control')
                ->exists();
    }

    /**
     * Persist the learning result for an explicitly learning-lane replay.
     * Returns a verification contract only after independent evidence; the
     * caller may then ask SkillMentorService to create a mentor, never a
     * parent.
     *
     * @return array<string, mixed>
     */
    public function recordFullReplayObservation(
        LabAgent $agent,
        ModelMarketPerformance $performance,
        array $result,
    ): array {
        if (! $this->isLearningAgent($agent) || ! $this->available()) return [];

        $pair = LabLearningLanePair::query()
            ->where('candidate_agent_id', $agent->id)
            ->whereIn('status', ['screen_paired', 'provisional', 'learning_queued', 'learning_observed'])
            ->latest('id')
            ->first();
        if (! $pair) return ['status' => 'missing_pair', 'promotion_evidence' => false];
        if (! $this->pairHasVerifiedControl($pair)) {
            return [
                'status' => 'missing_control',
                'pair_id' => $pair->id,
                'promotion_evidence' => false,
            ];
        }
        $dispatch = LabLearningLaneDispatch::query()
            ->where('pair_id', $pair->id)
            ->whereIn('status', ['selected', 'queued', 'running'])
            ->latest('id')
            ->first();

        $baseline = (array) $pair->control_metrics;
        $map = app(MutationResponseMapService::class)->recordFullReplay(
            $agent->fresh(['modelVersion']),
            $result,
            $performance,
            null,
            [
                'baseline_metrics' => $baseline,
                'status' => 'learning_observed',
                'metadata' => [
                    'learning_lane' => true,
                    'pair_id' => $pair->id,
                    'promotion_evidence' => false,
                ],
            ],
        );
        $delta = (array) data_get($map, 'target_delta', []);
        $changedGenes = array_keys((array) $agent->parameter_diff);
        $causalCreditEligible = count($changedGenes) === 1
            && data_get($map, 'parameter_key') !== null
            && data_get($map, 'metadata.causal_credit_eligible', true) !== false;
        $pair->update([
            'status' => 'learning_observed',
            'target_delta' => $delta !== [] ? $delta : $pair->target_delta,
            'independent_window_key' => $this->windowKey($result) ?: $pair->independent_window_key,
            'metadata' => [
                ...((array) $pair->metadata),
                'last_learning_run_id' => data_get($result, 'evidence_run_id'),
                'promotion_evidence' => false,
            ],
        ]);
        $dispatch?->update([
            'status' => 'completed',
            'stage' => 'full_replay',
            'micro_status' => 'passed',
            'completed_at' => now(),
            'metadata' => [
                ...((array) $dispatch->metadata),
                'last_response_map_id' => data_get($map, 'id'),
                'last_target_delta' => $delta,
                'promotion_evidence' => false,
            ],
        ]);

        app(LearningMemoryService::class)->recordPair($pair->fresh(), 'full_replay');
        // Bridge the legacy replay lane into the canonical episode ledger.
        // This is observational only; normal immutable promotion gates retain
        // all authority.
        $this->recordCanonicalFullReplay($agent, $pair->fresh(), $result, $causalCreditEligible, $delta);
        app(FailureDojoService::class)->recordPair($pair->fresh());
        app(CouncilDisagreementService::class)->recordResult($result, [
            'symbol' => $agent->symbol,
            'timeframe' => $agent->timeframe,
            'family' => $agent->strategy_family,
            'evidence_run_id' => data_get($result, 'evidence_run_id'),
        ]);

        $lesson = null;
        $verification = null;
        if ($causalCreditEligible && (bool) data_get($delta, 'improved', false)) {
            $lesson = $this->recordProvisionalSkill($pair->fresh(), 'full_replay', $result, $delta);
            $independent = $this->independentObservationCount($pair->fresh(), $result);
            $requiredIndependent = max(2, (int) config('services.learning_lane.independent_confirmations_required', 2));
            if ($independent >= $requiredIndependent && $this->independentConfirmationEligible($result, $requiredIndependent)) {
                // A confirmed response map without a confirmed lesson is an
                // incomplete learning loop. The lesson is the reusable skill
                // artifact consumed by the next constructor, so require its
                // materialization before declaring the skill confirmed.
                $provisionalLesson = $lesson?->fresh();
                if (! $provisionalLesson) {
                    $pair->update([
                        'status' => 'provisional',
                        'metadata' => [
                            ...((array) $pair->metadata),
                            'confirmation_blocked' => 'CONFIRMED_SKILL_ARTIFACT_MISSING',
                            'promotion_evidence' => false,
                        ],
                    ]);

                    return [
                        'protocol' => self::PROTOCOL,
                        'status' => 'confirmed_skill_artifact_missing',
                        'pair_id' => $pair->id,
                        'dispatch_id' => $dispatch?->id,
                        'target_delta' => $delta,
                        'independent_observation_count' => $independent,
                        'promotion_evidence' => false,
                    ];
                }
                $verification = [
                    'status' => 'confirmed',
                    'protocol' => 'learning_lane_independent_skill_v1',
                    'pair_id' => $pair->id,
                    'independent_observation_count' => $independent,
                    'independent_confirmations_required' => $requiredIndependent,
                    'parameter_key' => $map['parameter_key'] ?? null,
                    'target' => $pair->target,
                    'promotion_evidence' => false,
                ];
                $result['verified_mutation_skill'] = $verification;
                $mentor = app(SkillMentorService::class)->recordFullReplayOutcome(
                    $agent->fresh(['modelVersion']),
                    $performance->fresh(),
                    $result,
                    null,
                );
                $map = app(MutationResponseMapService::class)->recordFullReplay(
                    $agent->fresh(['modelVersion']),
                    $result,
                    $performance->fresh(),
                    $verification,
                    [
                        'baseline_metrics' => $baseline,
                        'status' => 'independently_confirmed',
                        'metadata' => [
                            'learning_lane' => true,
                            'pair_id' => $pair->id,
                            'skill_mentor' => $mentor,
                            'promotion_evidence' => false,
                        ],
                    ],
                );
                $provisionalLesson->update([
                    'status' => 'confirmed',
                    'independent_window_count' => $independent,
                    'confirmation_count' => $requiredIndependent,
                    'evidence' => [
                        ...((array) $provisionalLesson->evidence),
                        'confirmed_at' => now()->utc()->toIso8601String(),
                        'verification' => $verification,
                        'mentor' => $mentor,
                        'promotion_evidence' => false,
                    ],
                    'expires_at' => null,
                ]);
                $pair->update(['status' => 'confirmed']);
            } else {
                $pair->update(['status' => 'provisional']);
            }
        }

        return [
            'protocol' => self::PROTOCOL,
            'status' => $verification ? 'skill_mentor_candidate' : ($lesson ? 'provisional_skill' : 'observed_without_skill'),
            'pair_id' => $pair->id,
            'dispatch_id' => $dispatch?->id,
            'response_map_id' => data_get($map, 'id'),
            'target_delta' => $delta,
            'independent_observation_count' => $this->independentObservationCount($pair->fresh(), $result),
            'verification' => $verification,
            'promotion_evidence' => false,
        ];
    }

    public function isLearningAgent(LabAgent $agent): bool
    {
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);
        return data_get($metadata, 'learning_lane.protocol') === self::PROTOCOL
            && data_get($metadata, 'learning_lane.promotion_evidence', false) !== true;
    }

    /** @return array<string, mixed> */
    public function status(string $symbol, string $timeframe, ?string $family = null): array
    {
        if (! $this->available()) return ['protocol' => self::PROTOCOL, 'status' => 'migration_pending'];

        $pairs = LabLearningLanePair::query()
            ->with(['candidateAgent', 'candidateResponseMap'])
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->when($family, fn ($query) => $query->where('strategy_family', $family))
            ->get();
        $activePairs = $pairs->whereNotIn('status', ['superseded']);
        // This method backs read-only monitoring commands. Learning memory
        // and Failure Dojo materialization happen when a pair is created or
        // settles, never while an operator merely observes the dashboard.
        // Besides violating the monitor contract, mutating every historical
        // pair here made status checks increasingly expensive over time.
        $pairedRows = $activePairs
            ->whereIn('status', ['screen_paired', 'provisional', 'learning_queued', 'learning_observed', 'confirmed'])
            ->filter(fn (LabLearningLanePair $pair): bool => $this->pairHasVerifiedControl($pair));
        $pairedCount = $pairedRows->count();
        $missingCount = $activePairs->filter(fn (LabLearningLanePair $pair): bool => ! $this->pairHasVerifiedControl($pair))->count();
        // Only compare improvement against verified control-paired rows.  A
        // micro-failed/missing-control row may retain a historical target
        // delta, but it is not a causal control comparison and must not inflate
        // this KPI above 100%.
        $improvedCount = $pairedRows->filter(fn (LabLearningLanePair $pair): bool => (bool) data_get($pair->target_delta, 'improved', false))->count();
        $signatureCounts = $activePairs
            ->filter(fn (LabLearningLanePair $pair): bool => filled(data_get($pair->failure_signature, 'signature')))
            ->countBy(fn (LabLearningLanePair $pair): string => (string) data_get($pair->failure_signature, 'signature'));
        $repeatCount = $signatureCounts->filter(fn (int $count): bool => $count > 1)->sum(fn (int $count): int => $count - 1);
        $lessons = AgentLearningLesson::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('lesson_type', 'skill_lesson')
            ->when($family, fn ($query) => $query->where('strategy_family', $family))
            ->get();
        // Legacy lessons can outlive their pair.  They remain audit history,
        // but only lessons backed by a verified control pair are usable by the
        // learning lane and should appear in operational skill KPIs.
        $usableLessons = $lessons->filter(fn (AgentLearningLesson $lesson): bool => $this->lessonHasVerifiedControlPair($lesson));
        $dispatches = LabLearningLaneDispatch::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->when($family, fn ($query) => $query->where('strategy_family', $family))
            ->get();
        $learningBatchIds = $dispatches->pluck('queue_batch_id')->filter()->unique()->values()->all();
        $activeBatchIds = $learningBatchIds === [] ? collect() : DB::table('job_batches')
            ->whereIn('id', $learningBatchIds)
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->pluck('id');
        $fullQueue = (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation');
        $queueSnapshot = app(LabQueueJobInspector::class)->queueSnapshot([$fullQueue]);
        $queuedReplayJobs = collect((array) ($queueSnapshot['rows'] ?? []))
            ->filter(fn (array $job): bool => in_array((string) data_get(json_decode((string) ($job['payload'] ?? ''), true), 'data.batchId', ''), $learningBatchIds, true))
            ->count();
        $completedReplays = $dispatches->where('status', 'completed')->count();
        $observedReplays = $activePairs->whereIn('status', ['learning_observed', 'confirmed'])->count();
        $oldestQueuedAt = $dispatches->whereIn('status', ['selected', 'queued', 'running'])
            ->pluck('queued_at')
            ->filter()
            ->map(fn ($value) => \Carbon\Carbon::parse((string) $value)->utc())
            ->sortBy(fn (\Carbon\Carbon $value): int => $value->timestamp)
            ->first();
        $coverageDenominator = $pairedCount + $missingCount;

        return [
            'protocol' => self::PROTOCOL,
            'status' => 'available',
            'pairs' => $pairs->count(),
            'paired' => $pairedCount,
            'missing_control' => $missingCount,
            'provisional_skills' => $usableLessons->where('status', 'provisional')->count(),
            'confirmed_skills' => $usableLessons->where('status', 'confirmed')->count(),
            'active_dispatches' => $dispatches->filter(fn (LabLearningLaneDispatch $dispatch): bool => in_array((string) $dispatch->status, ['selected', 'queued', 'running'], true)
                || ((string) $dispatch->status === 'retry_ready' && $activeBatchIds->contains((string) $dispatch->queue_batch_id)))->count(),
            'queued_replay_jobs' => $queuedReplayJobs,
            'kpis' => [
                'paired_delta_coverage_percent' => $coverageDenominator > 0 ? round(($pairedCount / $coverageDenominator) * 100, 2) : 0.0,
                'target_improvement_rate_percent' => $pairedCount > 0 ? round(($improvedCount / $pairedCount) * 100, 2) : 0.0,
                'repeat_failure_rate_percent' => $activePairs->count() > 0 ? round(($repeatCount / $activePairs->count()) * 100, 2) : 0.0,
                'provisional_skill_birth_rate_percent' => $pairedCount > 0 ? round(($usableLessons->where('status', 'provisional')->count() / $pairedCount) * 100, 2) : 0.0,
                'confirmed_mentor_birth_rate_percent' => $usableLessons->count() > 0 ? round(($usableLessons->where('status', 'confirmed')->count() / $usableLessons->count()) * 100, 2) : 0.0,
                'full_replay_throughput' => $completedReplays,
                'full_replay_observed' => $observedReplays,
                'forward_confirmation_rate_percent' => $observedReplays > 0 ? round(($usableLessons->where('status', 'confirmed')->count() / $observedReplays) * 100, 2) : 0.0,
                'queue_oldest_age_seconds' => $oldestQueuedAt ? max(0, now()->utc()->timestamp - $oldestQueuedAt->timestamp) : 0,
                'superseded_duplicate_pairs' => $pairs->where('status', 'superseded')->count(),
            ],
            'memory' => app(LearningMemoryService::class)->progress($symbol, $timeframe),
            'learning_kernel' => app(LearningKernelService::class)->pulse($symbol, $timeframe, $family),
            'failure_dojo' => app(FailureDojoService::class)->progress($symbol, $timeframe),
            'council_disagreement' => app(CouncilDisagreementService::class)->progress($symbol, $timeframe),
            'gene_interactions' => app(GeneInteractionLabService::class)->progress($symbol, $timeframe),
            'micro' => [
                'pending' => $dispatches->where('micro_status', 'pending')->count(),
                'deferred' => $dispatches->where('micro_status', 'deferred')->count(),
                'passed' => $dispatches->where('micro_status', 'passed')->count(),
                'failed' => $dispatches->where('micro_status', 'failed')->count(),
            ],
            'pair_learning_states' => [
                'micro_failed' => $activePairs->where('status', 'micro_failed')->count(),
                'micro_deferred' => $activePairs->where('status', 'micro_deferred')->count(),
            ],
            'promotion_evidence' => false,
        ];
    }

    private function targetUtility(string $target, float $delta): float
    {
        return in_array(strtolower($target), ['drawdown', 'drawdown_risk', 'max_drawdown', 'risk'], true)
            ? -$delta
            : $delta;
    }

    /** @return array{map_id:?int,agent_id:?int,evidence_run_id:?string,metrics:array<string,mixed>,source:string}|array<string,mixed> */
    private function resolveControl(
        LabAgent $agent,
        LabMutationResponseMap $candidate,
        ?Collection $controlRows = null,
    ): array
    {
        $candidateExecution = $this->executionHashOf($candidate);
        $candidateSnapshot = $this->snapshotHashOf($candidate);
        $controls = $controlRows ?? LabMutationResponseMap::query()
            ->with('agent')
            ->where('stage', 'screening')
            ->where('status', 'control')
            ->where('symbol', strtoupper((string) $agent->symbol))
            ->where('timeframe', strtoupper((string) $agent->timeframe))
            ->latest('id')
            ->get();
        $sameGenerationControl = $controls
            ->first(fn (LabMutationResponseMap $row): bool => (string) $row->strategy_family === (string) $agent->strategy_family
                && (int) ($row->agent?->lab_generation_id ?? 0) === (int) $agent->lab_generation_id
                && $candidateExecution !== ''
                && $this->sameExecutionContract($candidate, $row)
                && $this->sameSnapshot($candidate, $row));
        if ($sameGenerationControl) {
            return [
                'map_id' => $sameGenerationControl->id,
                'agent_id' => $sameGenerationControl->lab_agent_id,
                'evidence_run_id' => $sameGenerationControl->evidence_run_id,
                'metrics' => (array) $sameGenerationControl->observed_metrics,
                'generation_id' => (int) ($sameGenerationControl->agent?->lab_generation_id ?? 0),
                'data_hash' => $this->snapshotHashOf($sameGenerationControl),
                'execution_hash' => $this->executionHashOf($sameGenerationControl),
                'source' => 'control',
                'scope' => 'same_generation_family',
                'quality' => 'same_generation_family',
                'same_snapshot' => $this->sameSnapshot($candidate, $sameGenerationControl),
                'same_execution_contract' => $this->sameExecutionContract($candidate, $sameGenerationControl),
            ];
        }

        $baseline = (array) $candidate->baseline_metrics;
        if ($baseline !== []) {
            $source = ((int) $agent->parent_a_model_version_id > 0 || (int) $agent->parent_b_model_version_id > 0)
                ? 'parent' : (((int) data_get($agent->modelVersion?->metadata, 'repair_anchor.id', 0) > 0) ? 'anchor' : 'baseline');
            return ['map_id' => null, 'agent_id' => null, 'evidence_run_id' => null, 'metrics' => $baseline, 'source' => $source];
        }

        return ['map_id' => null, 'agent_id' => null, 'evidence_run_id' => null, 'metrics' => [], 'source' => 'missing'];
    }

    /**
     * A causal pair is valid only when the baseline is an explicit control
     * response and both immutable identities match. Parent/anchor/baseline
     * rows remain visible for diagnosis but can never enter the learning
     * frontier or earn a provisional skill.
     */
    private function isVerifiedControl(array $control): bool
    {
        return data_get($control, 'source') === 'control'
            && (array) data_get($control, 'metrics', []) !== []
            && (int) data_get($control, 'generation_id', 0) > 0
            && filled(data_get($control, 'data_hash'))
            && filled(data_get($control, 'execution_hash'))
            && (bool) data_get($control, 'same_snapshot', false)
            && (bool) data_get($control, 'same_execution_contract', false);
    }

    private function sameExecutionContract(LabMutationResponseMap $candidate, LabMutationResponseMap $control): bool
    {
        $candidateHash = $this->executionHashOf($candidate);
        $controlHash = $this->executionHashOf($control);

        return $candidateHash !== '' && $controlHash !== '' && hash_equals($candidateHash, $controlHash);
    }

    private function sameSnapshot(LabMutationResponseMap $candidate, LabMutationResponseMap $control): bool
    {
        $candidateHash = $this->snapshotHashOf($candidate);
        $controlHash = $this->snapshotHashOf($control);
        if ($candidateHash !== '' && $controlHash !== '') return hash_equals($candidateHash, $controlHash);

        $candidateWindow = (string) ($candidate->temporal_window_key ?: '');
        $controlWindow = (string) ($control->temporal_window_key ?: '');

        return $candidateWindow !== '' && $controlWindow !== '' && $candidateWindow === $controlWindow;
    }

    private function executionHashOf(LabMutationResponseMap $map): string
    {
        $direct = (string) data_get($map->metadata, 'execution_hash', data_get(
            $map->observed_metrics,
            'execution_contract.execution_hash',
            data_get($map->observed_metrics, 'execution_hash', ''),
        ));
        if ($direct !== '') return $direct;
        $meta = $this->evidenceRequestMeta($map);
        return (string) data_get($meta, 'payload.execution_contract.execution_hash', data_get($meta, 'execution_contract.execution_hash', ''));
    }

    private function snapshotHashOf(LabMutationResponseMap $map): string
    {
        $direct = (string) data_get($map->metadata, 'data_manifest_hash', data_get(
            $map->observed_metrics,
            'data_manifest.sha256',
            data_get($map->observed_metrics, 'data_manifest.snapshot_sha256', data_get($map->observed_metrics, 'data_manifest_hash', '')),
        ));
        if ($direct !== '') return $direct;
        $meta = $this->evidenceRequestMeta($map);
        return (string) data_get($meta, 'dataset_manifest.snapshot_sha256', data_get($meta, 'dataset_manifest.data_hash', data_get($meta, 'dataset_hash', '')));
    }

    /** @return array<string, mixed> */
    private function evidenceRequestMeta(LabMutationResponseMap $map): array
    {
        if (! $map->evidence_run_id) return [];
        return (array) LabEvaluationRun::query()
            ->where('run_id', $map->evidence_run_id)
            ->first()?->request_meta;
    }

    private function recordProvisionalSkill(
        LabLearningLanePair $pair,
        string $stage,
        array $result = [],
        array $delta = [],
    ): ?AgentLearningLesson {
        if (! $this->pairHasVerifiedControl($pair) || ! data_get($pair->target_delta, 'improved', false)) return null;
        if ((string) data_get($pair->metadata, 'screening_decision', '') === 'passed') return null;
        if (! (bool) data_get($pair->metadata, 'same_execution_contract', false)) return null;
        $runId = (string) ($pair->candidate_evidence_run_id ?: data_get($result, 'evidence_run_id', ''));
        if ($runId !== '' && ! app(LabImmutableEvidenceService::class)->learningEligibility($runId)['complete']) return null;

        $agent = $pair->candidateAgent()->with('modelVersion')->first();
        $map = $pair->candidateResponseMap;
        if (! $agent || ! $map) return null;
        if ($map->parameter_key === null || data_get($map->metadata, 'causal_credit_eligible', false) !== true) return null;
        $signature = (string) data_get($pair->failure_signature, 'signature', $pair->pair_key);
        $evidence = [
            'protocol' => self::PROTOCOL,
            'pair_id' => $pair->id,
            'pair_key' => $pair->pair_key,
            'stage' => $stage,
            'specialist_role' => $pair->specialist_role,
            'failure_signature' => $pair->failure_signature,
            'target_delta' => $delta !== [] ? $delta : $pair->target_delta,
            'direction' => $map->direction,
            'old_value' => $map->old_value,
            'new_value' => $map->new_value,
            'independent_window_key' => $this->windowKey($result),
            'promotion_evidence' => false,
        ];
        $hash = hash('sha256', json_encode([
            self::PROTOCOL, $signature, $stage, $runId, data_get($result, 'evidence_run_id'),
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        return AgentLearningLesson::query()->firstOrCreate(
            ['lesson_hash' => $hash],
            [
                'lesson_id' => (string) \Illuminate\Support\Str::uuid(),
                'lab_agent_id' => $agent->id,
                'model_version_id' => $agent->model_version_id,
                'symbol' => $agent->symbol,
                'timeframe' => $agent->timeframe,
                'strategy_family' => $agent->strategy_family,
                'lesson_type' => 'skill_lesson',
                'status' => 'provisional',
                'failure_class' => $pair->target,
                'parameter_key' => $map->parameter_key,
                'state_cluster_id' => data_get($pair->failure_signature, 'state.cluster_id'),
                'regime' => data_get($pair->failure_signature, 'state.regime'),
                'volatility' => data_get($pair->failure_signature, 'state.volatility'),
                'transition_state' => data_get($pair->failure_signature, 'state.transition_state'),
                'spread_liquidity_state' => data_get($pair->failure_signature, 'state.spread_liquidity_state'),
                'outcome' => 'beneficial',
                'independent_window_count' => 0,
                'confirmation_count' => 0,
                'source_run_ids' => array_values(array_unique(array_filter([
                    $pair->candidate_evidence_run_id,
                    $pair->control_evidence_run_id,
                    data_get($result, 'evidence_run_id'),
                ]))),
                'evidence' => $evidence,
                'observed_at' => now(),
                'expires_at' => now()->addDays(max(1, (int) config('services.learning_lane.provisional_skill_ttl_days', 30))),
            ],
        );
    }

    private function independentObservationCount(LabLearningLanePair $pair, array $result): int
    {
        $keys = collect([
            $pair->independent_window_key,
            $this->windowKey($result),
        ])->merge(collect((array) data_get($result, 'forward_window_protocol.windows', []))->map(
            fn ($row) => data_get($row, 'window_key', data_get($row, 'key')),
        ))->filter()->unique()->values();
        $protocolIndependent = data_get($result, 'forward_window_protocol.independence_verified') === true;
        $observed = max(0, (int) data_get($result, 'forward_window_protocol.observed_windows', 0));

        return max($keys->count(), $protocolIndependent ? $observed : 0);
    }

    private function independentConfirmationEligible(array $result, int $required): bool
    {
        $protocol = (array) data_get($result, 'forward_window_protocol', []);
        if (data_get($protocol, 'independence_verified') !== true) return false;
        if (data_get($protocol, 'overlap_detected') === true) return false;
        $positive = max(
            (int) data_get($protocol, 'positive_windows', 0),
            (int) data_get($protocol, 'confirmed_windows', 0),
        );
        return $positive >= $required;
    }

    private function windowKey(array $result): ?string
    {
        $key = data_get($result, 'learning_lane.independent_window_key', data_get(
            $result,
            'temporal_window_key',
            data_get($result, 'forward_window_protocol.window_key'),
        ));

        return filled($key) ? (string) $key : null;
    }

    private function stablePairCell(LabLearningLanePair $pair): string
    {
        $map = $pair->candidateResponseMap;
        $dataHash = $map ? $this->snapshotHashOf($map) : '';
        $window = $pair->independent_window_key ?: 'same_snapshot';

        return hash('sha256', json_encode([
            self::PAIR_PROTOCOL,
            $pair->candidate_agent_id,
            $pair->control_agent_id,
            $pair->target,
            $pair->specialist_role,
            $dataHash,
            $window,
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function recordCanonicalFullReplay(
        LabAgent $agent,
        LabLearningLanePair $pair,
        array $result,
        bool $causalCreditEligible,
        array $delta,
    ): void {
        try {
            $kernel = app(LearningKernelService::class);
            $map = $pair->candidateResponseMap;
            $context = (array) data_get($pair->failure_signature, 'state', []);
            $episode = $kernel->openEpisode($agent, [
                'decision_key' => 'learning-lane:pair:'.$pair->id.':'.(string) data_get($result, 'evidence_run_id', 'full'),
                'symbol' => $pair->symbol, 'timeframe' => $pair->timeframe, 'strategy_family' => $pair->strategy_family,
                'stage' => 'full_replay', 'decision' => 'MUTATE', 'context' => $context,
                'data_manifest_hash' => $map ? $this->snapshotHashOf($map) : null,
                'execution_hash' => $map ? $this->executionHashOf($map) : null,
                'parameter_hash' => $map?->response_key,
            ]);
            $settled = $kernel->settleOutcome($episode, [
                'source_key' => 'learning-lane:full:'.$pair->id.':'.(string) data_get($result, 'evidence_run_id', 'none'),
                'source_type' => LabLearningLanePair::class, 'source_id' => $pair->id, 'outcome_status' => 'settled',
                'failure_class' => data_get($pair->failure_signature, 'failure_type', $pair->target),
                'parameter_key' => $map?->parameter_key, 'independent_window_key' => $pair->independent_window_key,
                'control_present' => $pair->control_agent_id !== null && (bool) data_get($pair->metadata, 'same_execution_contract', false),
                'evidence_state' => $causalCreditEligible && (bool) data_get($delta, 'improved', false) ? 'positive' : 'negative',
                'metrics' => $result,
            ]);
            $kernel->consolidate($settled['settlement']);
        } catch (\Throwable) {
            // A not-yet-migrated canonical ledger must not block a completed
            // immutable replay. The bridge will be re-run by reconciliation.
        }
    }

    private function available(): bool
    {
        try {
            return Schema::hasTable('lab_learning_lane_pairs')
                && Schema::hasTable('lab_learning_lane_dispatches')
                && Schema::hasTable('agent_learning_lessons');
        } catch (\Throwable) {
            return false;
        }
    }
}
