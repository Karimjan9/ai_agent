<?php

namespace App\Services;

use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabFailureRepairAnchor;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use Illuminate\Support\Collection;

/**
 * Converts complete strategy failures into immutable repair baselines.
 *
 * An anchor is deliberately not a parent and never carries promotion credit.
 * It only says: "this exact failed parameter vector is the baseline for one
 * declared repair attempt". Technical/incomplete evidence is excluded before
 * an anchor can be written.
 */
class FailureRepairAnchorService
{
    public const PROTOCOL = 'failure_repair_anchor_v1';

    /** @var array<string, string> */
    private const TARGETS = [
        'FAILED_TRADE_COUNT' => 'trade_frequency',
        'FAILED_LOW_SCREEN_TRADES' => 'trade_frequency',
        'FAILED_NO_OPPORTUNITY' => 'trade_frequency',
        'FAILED_PROFIT_FACTOR' => 'profit_factor',
        'FAILED_NON_POSITIVE_SCORE' => 'profit_factor',
        'FAILED_FORWARD_SCORE' => 'profit_factor',
        'FAILED_RESCUE_PROFIT_FACTOR' => 'profit_factor',
        'FAILED_STRESS_COST' => 'stress_cost',
        'FAILED_EXECUTION_STRESS_GATE' => 'stress_cost',
        'FAILED_RESCUE_STRESS_COST' => 'stress_cost',
        'FAILED_TEMPORAL_CHUNK_SURVIVAL' => 'temporal_stability',
        'FAILED_CALENDAR_MONTH_SURVIVAL' => 'temporal_stability',
        'FAILED_MONTHLY_SURVIVAL' => 'monthly_survival',
        'FAILED_TRAIN_FORWARD_GAP' => 'temporal_stability',
        'FAILED_PARAMETER_STABILITY' => 'temporal_stability',
        'FAILED_SIGNAL_TIMING_STABILITY' => 'temporal_stability',
        'FAILED_RESCUE_TEMPORAL_SURVIVAL' => 'temporal_stability',
        'FAILED_RESCUE_TEMPORAL_GAP' => 'temporal_stability',
        'FAILED_RESCUE_PARAMETER_STABILITY' => 'temporal_stability',
        'FAILED_REGIME_COVERAGE' => 'regime_coverage',
        'INSUFFICIENT_REGIME_EVIDENCE' => 'regime_coverage',
        'FAILED_TRANSITION' => 'regime_coverage',
        'FAILED_NON_TARGET_REGRESSION' => 'drawdown_risk',
        'FAILED_DRAWDOWN' => 'drawdown_risk',
        'FAILED_RUIN' => 'drawdown_risk',
        'FAILED_RUIN_RISK' => 'drawdown_risk',
        'FAILED_OVERFIT' => 'architecture',
        'FAILED_STATISTICAL' => 'architecture',
        'FAILED_PASSPORT_OPPORTUNITY_RECALL' => 'trade_frequency',
        // Aggregate/robustness failures are still strategy evidence when the
        // underlying replay is complete. These aliases keep them on a
        // bounded causal lane instead of falling through to random restart.
        'FAILED_NOISE_SANITY' => 'stress_cost',
        'FAILED_STATISTICAL_FALSIFIER' => 'architecture',
        'FAILED_ELITE_PASSPORT' => 'architecture',
        'FAILED_PARAMETER_PLATEAU' => 'architecture',
    ];

    /** @var array<int, string> */
    private const ALLOWED_TARGETS = [
        'trade_frequency',
        'profit_factor',
        'stress_cost',
        'temporal_stability',
        'monthly_survival',
        'regime_coverage',
        'drawdown_risk',
        'architecture',
    ];

    public function targetForReason(string $reason): ?string
    {
        $reason = strtoupper(trim($reason));
        return self::TARGETS[$reason]
            ?? self::TARGETS[preg_replace('/^FAILED_RESCUE_/', 'FAILED_', $reason)]
            ?? null;
    }

    public function isTechnicalReason(string $reason): bool
    {
        $reason = strtoupper(trim($reason));

        return $reason === 'INSUFFICIENT_SCREENING_EVIDENCE'
            || in_array($reason, [
                'FAILED_INDEPENDENT_FORWARD_WINDOWS',
                'FAILED_OVERLAPPING_FORWARD_WINDOWS',
                'FAILED_SINGLE_GENE_CONTRACT',
                'FAILED_RESCUE_SINGLE_GENE_CONTRACT',
            ], true)
            || str_contains($reason, 'EVIDENCE')
            || str_contains($reason, 'TECHNICAL')
            || str_contains($reason, 'SNAPSHOT')
            || str_contains($reason, 'DATASET')
            || str_contains($reason, 'DATA_QUALITY')
            || str_contains($reason, 'FEED_UPTIME')
            || str_contains($reason, 'CALENDAR_ALIGNMENT')
            || str_contains($reason, 'REPLAY_TIMEOUT')
            || str_contains($reason, 'FEED_');
    }

    /**
     * Record anchors only after the caller has proved the evidence chain is
     * complete. `evidence_complete` is intentionally explicit so a future
     * caller cannot accidentally turn a technical projection into learning.
     */
    public function recordForAgent(
        LabAgent $agent,
        string $failureReason,
        ?string $failureTarget = null,
        array $evidence = [],
        bool $evidenceComplete = false,
        bool $allowStaleRebase = false,
    ): ?LabFailureRepairAnchor {
        $reason = strtoupper(trim($failureReason));
        $agent->loadMissing('modelVersion', 'generation');
        $gateContract = app(GateContractService::class)->forReason($reason);
        $target = $this->normalizeTarget($failureTarget)
            ?: $this->resolveTarget($reason, $evidence)
            ?: $this->repairTargetFromMetadata($agent);
        // A repair sibling is already attached to an immutable anchor. Its
        // failure belongs to that anchor's cohort ledger; creating a child
        // anchor here would reset the bounded-attempt counter and make the
        // escape/quarantine policy impossible to reach. The screen/forward
        // outcome methods are the only legal learning projections for it.
        $existingAnchorId = (int) data_get($agent->modelVersion?->metadata, 'repair_anchor.id', 0);
        $rebaseFromAnchorId = null;
        $rebaseResult = (array) data_get($evidence, 'screening_result', []);
        if ($existingAnchorId > 0) {
            if (! $allowStaleRebase || $rebaseResult === []) return null;

            $existingAnchor = LabFailureRepairAnchor::query()->find($existingAnchorId);
            if (! $existingAnchor || $this->snapshotMatches($existingAnchor, $rebaseResult)) {
                return null;
            }

            // A repair child evaluated on a different immutable dataset cannot
            // be paired with its old anchor. Preserve that anchor unchanged
            // and create a new baseline for the current evidence stream.
            $rebaseFromAnchorId = $existingAnchorId;
        }
        if (! $evidenceComplete
            || $target === null
            || $this->isTechnicalReason($reason)
            || $this->isTerminalFailureReason($reason)
            || data_get($agent->modelVersion?->metadata, 'repair_lineage.status') === 'quarantined') {
            return null;
        }

        $model = $agent->modelVersion;
        $parameters = (array) ($model?->parameters ?? []);
        if ($model === null || $parameters === []) return null;

        $snapshot = $this->canonicalize($parameters);
        $failureSignature = app(FailureSignatureCompilerService::class)->compile(
            $agent,
            $target,
            $evidence,
            $reason,
        );
        $fingerprint = hash('sha256', json_encode([
            'symbol' => strtoupper((string) $agent->symbol),
            'timeframe' => strtoupper((string) $agent->timeframe),
            'strategy_family' => (string) $agent->strategy_family,
            'parameters' => $snapshot,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $anchorKeyPayload = [
            'protocol' => self::PROTOCOL,
            'source_lab_agent_id' => $agent->id,
            'source_model_version_id' => $model->id,
            'failure_signature' => data_get($failureSignature, 'signature'),
            'parameter_fingerprint' => $fingerprint,
        ];
        if ($rebaseFromAnchorId !== null) {
            $anchorKeyPayload['rebase_from_anchor_id'] = $rebaseFromAnchorId;
            $anchorKeyPayload['rebase_data_hash'] = $this->snapshotHash($rebaseResult);
        }
        $anchorKey = hash('sha256', json_encode($anchorKeyPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $anchor = LabFailureRepairAnchor::firstOrCreate(
            ['anchor_key' => $anchorKey],
            [
                'source_lab_agent_id' => $agent->id,
                'source_model_version_id' => $model->id,
                'source_lab_generation_id' => $agent->lab_generation_id,
                'symbol' => strtoupper((string) $agent->symbol),
                'timeframe' => strtoupper((string) $agent->timeframe),
                'strategy_family' => (string) $agent->strategy_family,
                'failure_class' => 'strategy',
                'failure_reason' => $reason,
                'failure_target' => $target,
                'status' => 'open',
                // These fields are written once. The service never updates a
                // snapshot, even when a later retry observes new evidence.
                'parameter_snapshot' => $snapshot,
                'parameter_fingerprint' => $fingerprint,
                'parameter_diff' => (array) ($agent->parameter_diff ?? []),
                'evidence' => [
                    'protocol' => self::PROTOCOL,
                    'evidence_complete' => true,
                    'failure_class' => 'strategy',
                    'failure_reason' => $reason,
                    'failure_target' => $target,
                    'optimization_target' => data_get($gateContract, 'optimization_target', $target),
                    'optimization_gate' => data_get($gateContract, 'gate'),
                    'failure_contract_protocol' => GateContractService::PROTOCOL,
                    'failure_signature' => $failureSignature,
                    'source_lifecycle_status' => $agent->lifecycle_status,
                    'source_origin' => $agent->origin,
                    'source_generation' => $agent->generation?->generation,
                    'source_parameter_diff_keys' => array_keys((array) ($agent->parameter_diff ?? [])),
                    'screening_result' => $evidence['screening_result'] ?? null,
                    'rebase' => $rebaseFromAnchorId !== null ? [
                        'protocol' => 'stale_anchor_rebase_v1',
                        'from_anchor_id' => $rebaseFromAnchorId,
                        'data_hash' => $this->snapshotHash($rebaseResult),
                        'promotion_evidence' => false,
                    ] : null,
                    'immutable_snapshot_hash' => $fingerprint,
                    'observed' => $evidence,
                    'promotion_evidence' => false,
                    'parent_eligible' => false,
                    'mutation_credit' => 'not_yet_earned',
                ],
            ],
        );

        return $anchor;
    }

    /**
     * Consume a screening gate decision after immutable evidence is complete.
     * Multiple reasons create separate anchors; one failed agent therefore
     * teaches multiple bounded specialists without becoming a parent.
     *
     * @return array<int, LabFailureRepairAnchor>
     */
    public function recordFromScreeningDecision(LabAgent $agent, CandidateGateDecision $decision, array $result = []): array
    {
        if ($decision->decision !== 'failed' || $decision->stage !== 'screening') return [];
        $eligibility = app(LabImmutableEvidenceService::class)->learningEligibility((string) data_get($result, 'evidence_run_id', ''));
        if (! (bool) data_get($eligibility, 'complete', false)) return [];

        $screeningPair = $this->screeningPair($agent, $result);
        $anchors = [];
        foreach (array_values(array_unique((array) $decision->reason_codes)) as $reason) {
            $anchor = $this->recordForAgent(
                $agent,
                (string) $reason,
                null,
                [
                    'stage' => 'screening',
                    'gate_decision_id' => $decision->id,
                    'evidence_run_id' => $eligibility['run_id'] ?? null,
                    'screening_pair' => $screeningPair,
                    'screening_result' => $result,
                ],
                true,
                true,
            );
            if ($anchor) $anchors[] = $anchor;
        }

        return collect($anchors)->unique('id')->values()->all();
    }

    /**
     * Backfill/record anchors when a terminal handoff is assembled. This is
     * idempotent and only uses the latest complete run for each failed agent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recordFromHandoff(LabGeneration $generation, string $stage = 'screening'): array
    {
        $generation->loadMissing('agents');
        $agentIds = $generation->agents->pluck('id')->values()->all();
        if ($agentIds === []) return [];

        $decisionStage = $stage === 'forward' ? 'statistical_forward_gate' : 'screening';
        $decisions = CandidateGateDecision::query()
            ->where('stage', $decisionStage)
            ->whereIn('lab_agent_id', $agentIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('lab_agent_id')
            ->map(fn (Collection $rows) => $rows->first());

        $anchors = [];
        foreach ($generation->agents as $agent) {
            $decision = $decisions->get($agent->id);
            if (! $decision || $decision->decision !== 'failed') continue;
            $run = $this->latestCompleteRun($agent, $stage);
            if (! $run) continue;
            $eligibility = app(LabImmutableEvidenceService::class)->learningEligibility($run);
            if (! (bool) data_get($eligibility, 'complete', false)) continue;

            foreach (array_values(array_unique((array) $decision->reason_codes)) as $reason) {
                $anchor = $this->recordForAgent(
                    $agent,
                    (string) $reason,
                    null,
                    [
                        'stage' => $stage,
                        'gate_decision_id' => $decision->id,
                        'evidence_run_id' => $run->run_id,
                        'handoff_backfill' => true,
                        'screening_result' => (array) $decision->metrics,
                        'screening_pair' => $this->screeningPair($agent, (array) $decision->metrics),
                    ],
                    true,
                    true,
                );
                if ($anchor) $anchors[] = $this->descriptor($anchor);
            }
        }

        return collect($anchors)->unique('id')->values()->all();
    }

    /** @return array<string, mixed> */
    public function descriptor(LabFailureRepairAnchor $anchor): array
    {
        $anchor->loadMissing('sourceModelVersion');
        return [
            'id' => (int) $anchor->id,
            'failure_reason' => $anchor->failure_reason,
            'failure_target' => $anchor->failure_target,
            'symbol' => $anchor->symbol,
            'timeframe' => $anchor->timeframe,
            'strategy_family' => $anchor->strategy_family,
            'source_lab_agent_id' => $anchor->source_lab_agent_id,
            'source_model_version_id' => $anchor->source_model_version_id,
            'source_lab_generation_id' => $anchor->source_lab_generation_id,
            'parameter_fingerprint' => $anchor->parameter_fingerprint,
            'parameter_snapshot' => $anchor->parameter_snapshot,
            'architecture' => data_get($anchor->sourceModelVersion?->metadata, 'strategy_architecture'),
            'parameter_diff_keys' => array_keys((array) $anchor->parameter_diff),
            'status' => $anchor->status,
            'policy' => $this->policyFor($anchor),
            'protocol' => self::PROTOCOL,
            'promotion_evidence' => false,
        ];
    }

    /**
     * A repair baseline is only comparable when both immutable hashes match.
     * This is intentionally public so targeted-handoff planning can detect a
     * stale inherited anchor without mutating the historical record.
     */
    public function snapshotMatches(LabFailureRepairAnchor $anchor, array $candidate): bool
    {
        $baseline = (array) data_get($anchor->evidence, 'screening_result', []);
        if ($baseline === [] || $candidate === []) return false;

        return $this->sameHash($baseline, $candidate, 'data_manifest.snapshot_sha256', 'data_manifest.sha256')
            && $this->sameHash($baseline, $candidate, 'execution_contract.execution_hash', 'execution_hash');
    }

    /**
     * The anchor budget is measured across cohorts, not only the latest
     * generation. Three clean paired attempts without target improvement
     * leave the parameter surface and recommend an architecture/specialist
     * escape. Two independent forward failures quarantine the lineage.
     *
     * @return array<string, mixed>
     */
    public function policyFor(LabFailureRepairAnchor $anchor): array
    {
        $evidence = (array) $anchor->evidence;
        $screenings = collect((array) data_get($evidence, 'repair_screenings', []));
        $confirmedScreenings = $screenings->filter(fn (mixed $row): bool =>
            data_get($row, 'status') === 'confirmed'
        );
        $parameterScreenings = $confirmedScreenings->filter(fn (mixed $row): bool =>
            ! in_array(data_get($row, 'sibling_kind'), ['frozen_control', 'architecture_escape'], true)
        );
        // One four-sibling cohort is one attempt. Count by cohort id so the
        // escape rule is not triggered after three children from one cohort.
        $cohorts = $confirmedScreenings->groupBy(fn (mixed $row): string =>
            (string) (data_get($row, 'sibling_cohort_id') ?: data_get($row, 'child_model_version_id'))
        );
        // A partial/technical cohort must not consume a strategy attempt.
        // Normal cohorts require primary + reverse + alternative; an escape
        // cohort has primary + reverse plus its architecture observation.
        $completeCohorts = $cohorts->filter(function (Collection $rows): bool {
            $kinds = $rows->pluck('sibling_kind')->filter()->unique()->values();
            $hasPrimary = $kinds->contains('primary_direction');
            $hasReverse = $kinds->contains('reverse_direction');
            $newContract = $rows->contains(fn (mixed $row): bool =>
                data_get($row, 'cohort_contract') === 'four_siblings_plus_control_v1'
            );
            $hasThird = $kinds->contains('alternative_gene') || $kinds->contains('architecture_escape');
            $hasFourth = $kinds->contains('secondary_alternative_gene') || $kinds->contains('architecture_escape');
            $hasControl = $kinds->contains('frozen_control');

            return $hasPrimary && $hasReverse && $hasThird && $hasControl
                && (! $newContract || $hasFourth);
        });
        $cleanAttempts = $completeCohorts->filter(fn (Collection $rows): bool =>
            $rows->every(fn (mixed $row): bool => data_get($row, 'target_improved') !== true)
        )->count();
        $improvements = $completeCohorts->filter(fn (Collection $rows): bool =>
            $rows->contains(fn (mixed $row): bool => data_get($row, 'target_improved') === true)
        )->count();
        $forwardOutcomes = collect((array) data_get($evidence, 'repair_forward_outcomes', []));
        $forwardFailures = $forwardOutcomes->filter(fn (mixed $row): bool =>
            data_get($row, 'independent_forward_failure') === true
        )->count();
        $action = $forwardFailures >= 2
            ? 'quarantine'
            : ($cleanAttempts >= 3 && $improvements === 0 ? 'escape_to_architecture' : 'bounded_gene_repair');

        return [
            'protocol' => 'anchor_escape_policy_v1',
            'action' => $action,
            'parameter_attempts' => $completeCohorts->count(),
            'incomplete_cohorts' => max(0, $cohorts->count() - $completeCohorts->count()),
            'sibling_observations' => $parameterScreenings->count(),
            'clean_target_failures' => $cleanAttempts,
            'target_improvements' => $improvements,
            'independent_forward_failures' => $forwardFailures,
            'max_clean_target_failures_before_escape' => 3,
            'max_independent_forward_failures_before_quarantine' => 2,
            'mutation_allowed' => ! in_array($action, ['quarantine'], true),
            'next_hypothesis' => $action === 'escape_to_architecture'
                ? 'architecture_or_specialist_hypothesis'
                : null,
            'promotion_evidence' => false,
        ];
    }

    /**
     * Record the completed full/forward observation against the same anchor.
     * Core anchor fields remain untouched; this is an append-only evidence
     * projection used by the escape policy and response-map compiler.
     */
    public function recordRepairForwardOutcome(LabAgent $agent, array $verification, array $result = []): ?array
    {
        $agent->loadMissing('modelVersion');
        $anchorId = (int) data_get($agent->modelVersion?->metadata, 'repair_anchor.id', 0);
        if ($anchorId <= 0) return null;
        $anchor = LabFailureRepairAnchor::query()->find($anchorId);
        if (! $anchor) return null;
        $siblingKind = (string) data_get($agent->modelVersion?->metadata, 'repair_anchor.sibling_kind', data_get($agent->modelVersion?->metadata, 'repair_anchor_sibling.kind', ''));
        if ($siblingKind === 'frozen_control') {
            return ['status' => 'control', 'promotion_evidence' => false];
        }
        if ($siblingKind === 'architecture_escape') {
            $evidence = (array) $anchor->evidence;
            $rows = collect((array) data_get($evidence, 'architecture_escape_outcomes', []))
                ->reject(fn (mixed $existing): bool => (int) data_get($existing, 'child_model_version_id', 0) === (int) $agent->model_version_id)
                ->push([
                    'child_lab_agent_id' => (int) $agent->id,
                    'child_model_version_id' => (int) $agent->model_version_id,
                    'verification_status' => data_get($verification, 'status', 'escape_only'),
                    'evidence_run_id' => data_get($result, 'evidence_run_id'),
                    'independent_forward_observed' => filled(data_get($result, 'evidence_run_id')),
                    'recorded_at' => now()->utc()->toIso8601String(),
                    'promotion_evidence' => false,
                ])->values()->all();
            $evidence['architecture_escape_outcomes'] = $rows;
            $anchor->update(['evidence' => $evidence]);

            return [
                'status' => 'escape_only',
                'architecture_escape' => true,
                'repair_anchor_id' => (int) $anchor->id,
                'promotion_evidence' => false,
            ];
        }

        $windows = (array) data_get($verification, 'independent_forward_windows', []);
        $row = [
            'child_lab_agent_id' => (int) $agent->id,
            'child_model_version_id' => (int) $agent->model_version_id,
            'verification_status' => data_get($verification, 'status', 'not_confirmed'),
            'independent_forward_failure' => data_get($verification, 'status') !== 'confirmed'
                && ((int) data_get($windows, 'confirmed_windows', 0) > 0 || filled(data_get($result, 'evidence_run_id'))),
            'target_improved' => data_get($verification, 'target_gate.improved') === true,
            'evidence_run_id' => data_get($result, 'evidence_run_id'),
            'recorded_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ];
        $evidence = (array) $anchor->evidence;
        $rows = collect((array) data_get($evidence, 'repair_forward_outcomes', []))
            ->reject(fn (mixed $existing): bool => (int) data_get($existing, 'child_model_version_id', 0) === (int) $agent->model_version_id)
            ->push($row)->values()->all();
        $evidence['repair_forward_outcomes'] = $rows;
        $policy = $this->policyFor($anchor->setAttribute('evidence', $evidence));
        $evidence['latest_policy'] = $policy;
        $anchor->update([
            'evidence' => $evidence,
            'status' => $policy['action'] === 'quarantine' ? 'quarantined' : 'open',
        ]);
        return $row + ['policy' => $policy];
    }

    public function findForTarget(int $id, string $symbol, string $timeframe, string $family, string $target): ?LabFailureRepairAnchor
    {
        return LabFailureRepairAnchor::query()
            ->whereKey($id)
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('strategy_family', $family)
            ->where('failure_target', $target)
            ->where('status', 'open')
            ->first();
    }

    /** @return array<string, mixed> */
    public function screeningPair(LabAgent $agent, array $childResult): array
    {
        $agent->loadMissing('modelVersion');
        $baseline = (array) data_get($agent->modelVersion?->metadata, 'last_screen_result', []);
        if ($baseline === [] || $childResult === []) {
            return [
                'protocol' => 'repair_anchor_paired_screen_v1',
                'status' => 'pending',
                'baseline_model_version_id' => $agent->model_version_id,
                'promotion_evidence' => false,
            ];
        }

        $baselineData = (string) data_get($baseline, 'data_manifest.snapshot_sha256', data_get($baseline, 'data_manifest.sha256', ''));
        $childData = (string) data_get($childResult, 'data_manifest.snapshot_sha256', data_get($childResult, 'data_manifest.sha256', ''));
        $baselineExecution = (string) data_get($baseline, 'execution_contract.execution_hash', data_get($baseline, 'execution_hash', ''));
        $childExecution = (string) data_get($childResult, 'execution_contract.execution_hash', data_get($childResult, 'execution_hash', ''));
        $sameData = $baselineData !== '' && $childData !== '' && hash_equals($baselineData, $childData);
        $sameExecution = $baselineExecution !== '' && $childExecution !== '' && hash_equals($baselineExecution, $childExecution);

        return [
            'protocol' => 'repair_anchor_paired_screen_v1',
            'status' => $sameData && $sameExecution ? 'confirmed' : 'not_confirmed',
            'baseline_model_version_id' => $agent->model_version_id,
            'same_data_hash' => $sameData,
            'same_execution_hash' => $sameExecution,
            'promotion_evidence' => false,
            'rule' => 'Repair screening may be compared only on the same data snapshot and execution-cost contract as the failed baseline.',
        ];
    }

    /**
     * Attach the repair child's paired-screen result to the existing anchor.
     * Only mutable evidence/status fields are updated; the parameter snapshot
     * and fingerprint are intentionally never touched.
     */
    public function recordRepairScreeningOutcome(LabAgent $agent, array $childResult): ?array
    {
        $agent->loadMissing('modelVersion');
        $anchorId = (int) data_get($agent->modelVersion?->metadata, 'repair_anchor.id', 0);
        if ($anchorId <= 0) return null;
        $anchor = LabFailureRepairAnchor::query()
            ->whereKey($anchorId)
            ->where('symbol', strtoupper((string) $agent->symbol))
            ->where('timeframe', strtoupper((string) $agent->timeframe))
            ->first();
        if (! $anchor) return null;

        $eligibility = app(LabImmutableEvidenceService::class)->learningEligibility((string) data_get($childResult, 'evidence_run_id', ''));
        if (! (bool) data_get($eligibility, 'complete', false)) return null;
        // The child model's metadata is updated after the gate decision is
        // recorded. Compare against the immutable failed baseline, not the
        // child's previous/empty last_screen_result value.
        $pair = $this->screeningPairAgainstAnchor($anchor, $childResult);
        $evidence = (array) $anchor->evidence;
        $repairScreening = [
            ...$pair,
            'status' => data_get($pair, 'status', 'not_confirmed'),
            'sibling_kind' => data_get($agent->modelVersion?->metadata, 'repair_anchor.sibling_kind', data_get($agent->modelVersion?->metadata, 'repair_anchor_sibling.kind')),
            'sibling_cohort_id' => data_get($agent->modelVersion?->metadata, 'repair_anchor.sibling_cohort_id', data_get($agent->modelVersion?->metadata, 'repair_anchor_sibling.cohort_id')),
            'cohort_contract' => data_get($agent->modelVersion?->metadata, 'repair_anchor.cohort_contract', data_get($agent->modelVersion?->metadata, 'repair_anchor_sibling.cohort_contract', 'legacy_three_siblings_plus_control_v1')),
            'target_improved' => data_get($pair, 'target_improved') === true,
            'evidence_run_id' => $eligibility['run_id'] ?? null,
            'child_lab_agent_id' => $agent->id,
            'child_model_version_id' => $agent->model_version_id,
            'recorded_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ];
        // The frozen control is part of the paired cohort's completeness
        // proof, but its unchanged score must never count as a target
        // improvement. Keep it in the ledger with an explicit false value.
        if (data_get($repairScreening, 'sibling_kind') === 'frozen_control') {
            $repairScreening['target_improved'] = false;
        }
        $screenings = collect((array) data_get($evidence, 'repair_screenings', []))
            ->reject(fn (mixed $row): bool => (int) data_get($row, 'child_model_version_id', 0) === (int) $agent->model_version_id)
            ->push($repairScreening)
            ->values()
            ->all();
        $evidence['repair_screenings'] = $screenings;
        $evidence['repair_screening'] = $repairScreening;
        $anchor->update(['evidence' => $evidence]);

        return $repairScreening;
    }

    /**
     * Verification contract for an anchor child. This is intentionally
     * separate from ordinary parent-child skill verification: the failed
     * source is a comparison baseline, never a genetic parent.
     *
     * @return array<string, mixed>
     */
    public function verifyRepairCandidate(LabAgent $agent, array $childResult): array
    {
        $agent->loadMissing('modelVersion');
        $anchorId = (int) data_get($agent->modelVersion?->metadata, 'repair_anchor.id', 0);
        if ($anchorId <= 0) return ['protocol' => self::PROTOCOL, 'status' => 'not_applicable', 'promotion_evidence' => false];
        $anchor = LabFailureRepairAnchor::query()->with('sourceModelVersion')->find($anchorId);
        if (! $anchor) return ['protocol' => self::PROTOCOL, 'status' => 'not_confirmed', 'reason_codes' => ['REPAIR_ANCHOR_MISSING'], 'promotion_evidence' => false];

        $siblingKind = (string) data_get($agent->modelVersion?->metadata, 'repair_anchor.sibling_kind', data_get($agent->modelVersion?->metadata, 'repair_anchor_sibling.kind', ''));
        if (in_array($siblingKind, ['frozen_control', 'architecture_escape'], true)) {
            return [
                'protocol' => self::PROTOCOL,
                'status' => $siblingKind === 'architecture_escape' ? 'escape_only' : 'control_only',
                'control_only' => true,
                'architecture_escape' => $siblingKind === 'architecture_escape',
                'repair_anchor_id' => (int) $anchor->id,
                'failure_target' => $anchor->failure_target,
                'changed_genes' => [],
                'parent_eligible_after_confirmation' => false,
                'promotion_evidence' => false,
                'rule' => $siblingKind === 'architecture_escape'
                    ? 'Architecture escape is a separate hypothesis lane; it never earns scalar mutation credit, parent or paper status.'
                    : 'Frozen control measures the same baseline and never earns skill, parent or paper status.',
            ];
        }

        $baseline = $this->baselineResult($agent);
        $screeningPair = collect((array) data_get($anchor->evidence, 'repair_screenings', []))
            ->first(fn (mixed $row): bool => (int) data_get($row, 'child_model_version_id', 0) === (int) $agent->model_version_id)
            ?: (array) data_get($anchor->evidence, 'repair_screening', []);
        $sameData = $this->sameHash($baseline, $childResult, 'data_manifest.snapshot_sha256', 'data_manifest.sha256');
        $sameExecution = $this->sameHash($baseline, $childResult, 'execution_contract.execution_hash', 'execution_hash');
        $target = (string) $anchor->failure_target;
        $baselineScore = $this->targetScore($target, $baseline);
        $childScore = $this->targetScore($target, $childResult);
        $targetImproved = $baselineScore !== null && $childScore !== null
            ? ($target === 'drawdown_risk' ? $childScore < $baselineScore : $childScore > $baselineScore)
            : false;
        $windows = app(MutationSkillVerificationService::class)->independentForwardWindows($childResult);
        $reasons = [];
        if (data_get($screeningPair, 'status') !== 'confirmed') $reasons[] = 'REPAIR_PAIRED_SCREEN_NOT_CONFIRMED';
        if (! $sameData) $reasons[] = 'REPAIR_BASELINE_DATA_MISMATCH';
        if (! $sameExecution) $reasons[] = 'REPAIR_BASELINE_EXECUTION_MISMATCH';
        if (count((array) $agent->parameter_diff) !== 1) $reasons[] = 'REPAIR_SINGLE_GENE_CONTRACT_FAILED';
        if (! filled(data_get($childResult, 'evidence_run_id'))
            || data_get($childResult, 'full_replay_runtime_policy.protocol') !== 'full_replay_runtime_budget_v1') {
            $reasons[] = 'REPAIR_FULL_REPLAY_EVIDENCE_MISSING';
        }
        if (data_get($childResult, 'no_regression_contract.status') !== 'passed') $reasons[] = 'REPAIR_NON_TARGET_REGRESSION';
        if ((int) data_get($windows, 'confirmed_windows', 0) < 2
            || data_get($windows, 'independence_verified') !== true) {
            $reasons[] = 'REPAIR_INDEPENDENT_FORWARD_NOT_CONFIRMED';
        }
        $cohortContract = (string) data_get(
            $agent->modelVersion?->metadata,
            'repair_anchor.cohort_contract',
            data_get($agent->modelVersion?->metadata, 'repair_anchor_sibling.cohort_contract', ''),
        );
        $controlParity = null;
        if ($cohortContract === 'four_siblings_plus_control_v1') {
            $generation = LabGeneration::query()->find((int) $agent->lab_generation_id);
            $controlParity = $generation
                ? app(FrozenControlParityService::class)->assess($generation)
                : ['status' => 'incomplete', 'promotion_evidence' => false];
            if (data_get($controlParity, 'status') !== 'passed') {
                $reasons[] = 'REPAIR_FROZEN_CONTROL_NOT_PASSED';
            }
        }
        if (! $targetImproved) $reasons[] = 'REPAIR_TARGET_GATE_NOT_IMPROVED';

        return [
            'protocol' => self::PROTOCOL,
            'status' => $reasons === [] ? 'confirmed' : 'not_confirmed',
            'repair_anchor_id' => (int) $anchor->id,
            'source_model_version_id' => (int) $anchor->source_model_version_id,
            'failure_target' => $target,
            'failure_reason' => $anchor->failure_reason,
            'changed_genes' => array_keys((array) $agent->parameter_diff),
            'paired_screening' => $screeningPair,
            'same_data_manifest' => $sameData,
            'same_execution_contract' => $sameExecution,
            'target_gate' => [
                'baseline_score' => $baselineScore,
                'child_score' => $childScore,
                'improved' => $targetImproved,
            ],
            'full_replay' => [
                'evidence_run_id' => data_get($childResult, 'evidence_run_id'),
                'runtime_policy' => data_get($childResult, 'full_replay_runtime_policy.protocol'),
            ],
            'independent_forward_windows' => $windows,
            'frozen_control_parity' => $controlParity,
            'no_regression_status' => data_get($childResult, 'no_regression_contract.status'),
            'reason_codes' => $reasons,
            'parent_eligible_after_confirmation' => $reasons === [],
            'mutation_credit_after' => ['paired_screening', 'full_replay', 'independent_forward'],
            'promotion_evidence' => false,
        ];
    }

    /**
     * Return the failed source's measured result for non-target comparison.
     * This is a baseline only; callers must never attach it as a parent.
     */
    public function baselineResult(LabAgent $agent): array
    {
        $agent->loadMissing('modelVersion');
        $anchorId = (int) data_get($agent->modelVersion?->metadata, 'repair_anchor.id', 0);
        if ($anchorId <= 0) return [];
        $anchor = LabFailureRepairAnchor::query()->with('sourceModelVersion')->find($anchorId);
        if (! $anchor) return [];
        $sourcePerformance = ModelMarketPerformance::query()
            ->where('model_version_id', $anchor->source_model_version_id)
            ->where('symbol', $agent->symbol)
            ->where('timeframe', $agent->timeframe)
            ->latest('id')
            ->first();

        return (array) ($sourcePerformance?->metrics
            ?? data_get($anchor->evidence, 'screening_result', data_get($anchor->sourceModelVersion?->metadata, 'last_screen_result', [])));
    }

    private function latestCompleteRun(LabAgent $agent, string $stage): ?LabEvaluationRun
    {
        $phases = $stage === 'forward'
            ? ['full_validation', 'forward', 'statistical_forward']
            : ['screening'];
        $runs = LabEvaluationRun::query()
            ->where('lab_agent_id', $agent->id)
            ->whereIn('phase', $phases)
            ->latest('id')
            ->get();
        $evidence = app(LabImmutableEvidenceService::class);
        foreach ($runs as $run) {
            if ((bool) data_get($evidence->learningEligibility($run), 'complete', false)) return $run;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function screeningPairAgainstAnchor(LabFailureRepairAnchor $anchor, array $childResult): array
    {
        $source = $anchor->sourceModelVersion;
        $baseline = (array) data_get(
            $anchor->evidence,
            'screening_result',
            data_get($source?->metadata, 'last_screen_result', []),
        );
        $sameData = $this->sameHash($baseline, $childResult, 'data_manifest.snapshot_sha256', 'data_manifest.sha256');
        $sameExecution = $this->sameHash($baseline, $childResult, 'execution_contract.execution_hash', 'execution_hash');
        $target = (string) $anchor->failure_target;
        $baselineScore = $this->targetScore($target, $baseline);
        $childScore = $this->targetScore($target, $childResult);
        $improved = $baselineScore !== null && $childScore !== null
            ? ($target === 'drawdown_risk' ? $childScore < $baselineScore : $childScore > $baselineScore)
            : false;

        return [
            'protocol' => 'repair_anchor_paired_screen_v1',
            'status' => $sameData && $sameExecution ? 'confirmed' : 'not_confirmed',
            'same_data_hash' => $sameData,
            'same_execution_hash' => $sameExecution,
            'failure_target' => $target,
            'baseline_score' => $baselineScore,
            'child_score' => $childScore,
            'target_improved' => $improved,
            'promotion_evidence' => false,
        ];
    }

    private function sameHash(array $baseline, array $child, string $primary, string $fallback): bool
    {
        $left = data_get($baseline, $primary, data_get($baseline, $fallback, ''));
        $right = data_get($child, $primary, data_get($child, $fallback, ''));
        return is_string($left) && $left !== '' && is_string($right) && $right !== '' && hash_equals($left, $right);
    }

    private function snapshotHash(array $result): string
    {
        return (string) data_get(
            $result,
            'data_manifest.snapshot_sha256',
            data_get($result, 'data_manifest.sha256', ''),
        );
    }

    private function targetScore(string $target, array $metrics): ?float
    {
        $value = match ($target) {
            'profit_factor' => data_get($metrics, 'profit_factor'),
            'stress_cost' => data_get($metrics, 'screening_survival.stress_cost_pf', data_get($metrics, 'pf_attribution.stress_cost.profit_factor', data_get($metrics, 'stress_test.profit_factor'))),
            'temporal_stability' => data_get($metrics, 'screening_survival.worst_temporal_chunk_pf', data_get($metrics, 'screening_survival.worst_window_pf', data_get($metrics, 'monthly_passport.worst_month_pf'))),
            'monthly_survival' => data_get($metrics, 'monthly_passport.worst_month_pf', data_get($metrics, 'screening_survival.worst_window_pf')),
            'regime_coverage' => data_get($metrics, 'screening_survival.worst_regime_pf', data_get($metrics, 'statistical_evidence.edge_quality.worst_regime_pf')),
            'drawdown_risk' => data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown')),
            'architecture' => data_get($metrics, 'profit_factor'),
            default => null,
        };

        return is_numeric($value) ? (float) $value : null;
    }

    private function resolveTarget(string $reason, array $evidence): ?string
    {
        $direct = $this->targetForReason($reason);
        if ($direct !== null) return $direct;

        $observedReasons = [
            ...(array) data_get($evidence, 'screening_result.screening_survival.reason_codes', []),
            ...(array) data_get($evidence, 'screening_result.reason_codes', []),
            ...(array) data_get($evidence, 'observed.screening_result.screening_survival.reason_codes', []),
            ...(array) data_get($evidence, 'observed.screening_result.reason_codes', []),
        ];
        foreach ($observedReasons as $observedReason) {
            $target = $this->targetForReason((string) $observedReason);
            if ($target !== null && ! $this->isTechnicalReason((string) $observedReason)) return $target;
        }

        $screen = (array) data_get($evidence, 'screening_result', data_get($evidence, 'observed.screening_result', []));
        if ((int) data_get($screen, 'total_trades', data_get($screen, 'sample_count', 0)) < 10) return 'trade_frequency';
        if ((float) data_get($screen, 'profit_factor', 0) < 1.0) return 'profit_factor';
        if ((float) data_get($screen, 'max_drawdown_percent', data_get($screen, 'max_drawdown', 0)) > 15) return 'drawdown_risk';

        return null;
    }

    private function normalizeTarget(?string $target): ?string
    {
        $target = strtolower(trim((string) $target));

        return in_array($target, self::ALLOWED_TARGETS, true) ? $target : null;
    }

    /**
     * A failed repair must continue the same declared causal question. It
     * may not silently restart from the generic mutation curriculum merely
     * because its new gate reason was an aggregate confirmation failure.
     */
    private function repairTargetFromMetadata(LabAgent $agent): ?string
    {
        return $this->normalizeTarget(data_get($agent->modelVersion?->metadata, 'repair_anchor.failure_target'));
    }

    private function isTerminalFailureReason(string $reason): bool
    {
        return in_array(strtoupper(trim($reason)), [
            'FAILED_STATISTICAL_FALSIFIER',
            'FAILED_TWO_REPAIR_REPLAYS',
        ], true);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
