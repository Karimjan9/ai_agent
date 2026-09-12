<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningLesson;
use App\Models\AgentLearningMutationIntent;
use App\Models\AgentLearningPolicy;
use App\Models\AgentLearningSettlement;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabLearningLanePair;
use App\Models\ModelMarketPerformance;
use Illuminate\Support\Facades\Schema;

/** Confirms memory only when it beats both blinded mutation and frozen control. */
class CausalLearningConfirmationService
{
    public const EVIDENCE_PROTOCOL = 'target_aligned_causal_confirmation_v2';

    /** @return array<string, mixed> */
    public function recordEvaluationOutcome(
        LabAgent $agent,
        array $result,
        ?ModelMarketPerformance $performance = null,
        ?object $forwardDecision = null,
    ): array {
        $experiment = AgentLearningCausalExperiment::query()
            ->where('guided_agent_id', $agent->id)
            ->orWhere('blinded_agent_id', $agent->id)
            ->orWhere('control_agent_id', $agent->id)
            ->first();
        if (! $experiment) {
            return ['status' => 'not_applicable', 'confirmed' => false, 'promotion_evidence' => false];
        }
        $candidateId = (int) $agent->id === (int) $experiment->control_agent_id
            ? (int) $experiment->guided_agent_id
            : (int) $agent->id;
        $pair = LabLearningLanePair::query()
            ->with('controlResponseMap')
            ->where('candidate_agent_id', $candidateId)
            ->where('control_agent_id', $experiment->control_agent_id)
            ->latest('id')
            ->first();
        if (! $pair) {
            return ['status' => 'missing_causal_pair', 'confirmed' => false, 'promotion_evidence' => false];
        }
        $delta = (array) $pair->target_delta;
        if ((int) $agent->id === (int) $experiment->control_agent_id) {
            $delta = ['delta' => 0.0, 'improved' => false];
        }
        $lesson = AgentLearningLesson::query()
            ->where('lab_agent_id', $agent->id)
            ->where('parameter_key', $experiment->gene_key)
            ->where('lesson_type', 'skill_lesson')
            ->latest('id')
            ->first();

        $outcome = $this->recordOutcome(
            $agent,
            $pair,
            $result,
            $delta,
            $lesson,
            $performance,
            $forwardDecision,
        );
        // Bind and settle only full candidate/control evidence. Screening
        // run IDs are preserved in pair metadata but can never satisfy this
        // causal settlement boundary.
        $canonical = $this->reconcileCanonicalPairs($experiment->fresh());
        $experiment->refresh();
        $allOutcomesPresent = collect([$this->guidedRole($experiment), 'blinded', 'frozen_control'])
            ->every(fn (string $role): bool => (array) data_get($experiment->evidence, 'outcomes.'.$role, []) !== []);
        if ($allOutcomesPresent && (string) $experiment->status !== 'confirmed') {
            $freshPair = $pair->fresh('controlResponseMap');
            $freshDelta = (int) $agent->id === (int) $experiment->control_agent_id
                ? ['delta' => 0.0, 'improved' => false]
                : (array) $freshPair->target_delta;
            $outcome = $this->recordOutcome(
                $agent,
                $freshPair,
                $result,
                $freshDelta,
                $lesson,
                $performance,
                $forwardDecision,
            );
        }

        return [
            ...$outcome,
            'canonical_pair_settlements' => $canonical,
            'promotion_evidence' => false,
        ];
    }

    /**
     * Project the two candidate/control pairs after both sides have terminal
     * full replay evidence. This method is idempotent through the canonical
     * outbox key and never treats a screening run as settlement authority.
     *
     * @return array<string, mixed>
     */
    private function reconcileCanonicalPairs(AgentLearningCausalExperiment $experiment): array
    {
        $outcomes = (array) data_get($experiment->evidence, 'outcomes', []);
        $control = (array) data_get($outcomes, 'frozen_control', []);
        if ($control === [] || ! filled(data_get($control, 'evidence_run_id'))) {
            return ['status' => 'awaiting_full_control', 'settled_pair_ids' => [], 'promotion_evidence' => false];
        }

        $settled = [];
        $pending = [];
        $projections = [];
        foreach ([$this->guidedRole($experiment), 'blinded'] as $role) {
            $candidate = (array) data_get($outcomes, $role, []);
            if ($candidate === [] || ! filled(data_get($candidate, 'evidence_run_id'))) {
                $pending[] = $role;

                continue;
            }
            $pair = LabLearningLanePair::query()->with('controlResponseMap')
                ->where('candidate_agent_id', (int) data_get($candidate, 'agent_id'))
                ->where('control_agent_id', (int) $experiment->control_agent_id)
                ->latest('id')->first();
            $agent = LabAgent::query()->with('modelVersion')->find((int) data_get($candidate, 'agent_id'));
            $performance = ModelMarketPerformance::query()->find((int) data_get($candidate, 'performance_id'));
            if (! $pair || ! $agent || ! $performance) {
                $pending[] = $role;

                continue;
            }
            $candidateRunId = (string) data_get($candidate, 'evidence_run_id');
            $controlRunId = (string) data_get($control, 'evidence_run_id');
            $candidateRun = LabEvaluationRun::query()->where('run_id', $candidateRunId)->first();
            $controlRun = LabEvaluationRun::query()->where('run_id', $controlRunId)->first();
            if (! $candidateRun || ! $controlRun
                || (string) $candidateRun->phase !== 'full_validation'
                || (string) $controlRun->phase !== 'full_validation'
                || (string) $candidateRun->status !== 'completed'
                || (string) $controlRun->status !== 'completed') {
                $pending[] = $role;
                $projections[$role] = [
                    'status' => 'awaiting_terminal_full_pair',
                    'candidate_run_phase' => $candidateRun?->phase,
                    'candidate_run_status' => $candidateRun?->status,
                    'control_run_phase' => $controlRun?->phase,
                    'control_run_status' => $controlRun?->status,
                    'promotion_evidence' => false,
                ];

                continue;
            }
            $comparison = $this->compareWindows($candidate, $control, 3);
            $delta = [
                'protocol' => 'causal_full_window_delta_v1',
                'baseline' => 0.0,
                'observed' => (float) data_get($comparison, 'mean_delta', 0),
                'delta' => (float) data_get($comparison, 'mean_delta', 0),
                'improved' => (bool) data_get($comparison, 'protocol_verified', false)
                    && (float) data_get($comparison, 'mean_delta', 0) > 0.00000001,
                'positive_windows' => (int) data_get($comparison, 'positive_delta_windows', 0),
                'common_windows' => (int) data_get($comparison, 'common_window_count', 0),
            ];
            $metadata = (array) $pair->metadata;
            $bindingHistory = (array) data_get($metadata, 'causal_full_replay_bindings', []);
            $binding = [
                'protocol' => 'causal_pair_full_evidence_binding_v1',
                'candidate_full_run_id' => $candidateRunId,
                'control_full_run_id' => $controlRunId,
                'previous_candidate_run_id' => $pair->candidate_evidence_run_id,
                'previous_control_run_id' => $pair->control_evidence_run_id,
                'experiment_id' => (int) $experiment->id,
                'bound_at' => now()->utc()->toIso8601String(),
                'promotion_evidence' => false,
            ];
            $bindingKey = hash('sha256', json_encode([
                $candidateRunId, $controlRunId, (int) $experiment->id,
            ]));
            $bindingHistory[$bindingKey] = $binding;
            $nonTargetRegression = $this->compareNonTargetInvariants(
                $candidate,
                $control,
                (string) $pair->target,
            );
            $pair->update([
                'candidate_evidence_run_id' => $candidateRunId,
                'control_evidence_run_id' => $controlRunId,
                'candidate_metrics' => $this->outcomeMetrics($candidate),
                'control_metrics' => $this->outcomeMetrics($control),
                'target_delta' => $delta,
                'non_target_regression' => $nonTargetRegression,
                'status' => 'causal_full_paired',
                'metadata' => [
                    ...$metadata,
                    'causal_experiment_id' => (int) $experiment->id,
                    'causal_full_replay_bindings' => $bindingHistory,
                    'promotion_evidence' => false,
                ],
            ]);
            $result = (array) $performance->metrics;
            $result['evidence_run_id'] = $candidateRunId;
            $receipt = (array) data_get($agent->modelVersion?->metadata, 'learning_receipt', []);
            $guidedRole = $this->guidedRole($experiment);
            $expectedInfluence = $guidedRole === 'repair_guided' ? 'causal_repair_guided' : 'memory_guided';
            $causalCreditEligible = $role === $guidedRole
                && data_get($receipt, 'integrity.valid') === true
                && data_get($receipt, 'causal_influence') === $expectedInfluence
                && (bool) $delta['improved'];
            $projection = app(CanonicalLearningOutboxService::class)->record(
                $agent,
                $pair->fresh('controlResponseMap'),
                $result,
                $causalCreditEligible,
                $delta,
            );
            $projections[$role] = $projection;
            if (in_array((string) data_get($projection, 'status'), ['completed', 'duplicate'], true)) {
                $settled[] = (int) $pair->id;
            } else {
                $pending[] = $role;
            }
        }

        return [
            'status' => $pending === [] ? 'settled' : 'partially_settled',
            'settled_pair_ids' => array_values(array_unique($settled)),
            'pending_roles' => array_values(array_unique($pending)),
            'projections' => $projections,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function outcomeMetrics(array $outcome): array
    {
        return [
            'protocol' => 'causal_full_outcome_projection_v1',
            'evidence_run_id' => data_get($outcome, 'evidence_run_id'),
            'performance_id' => data_get($outcome, 'performance_id'),
            'independent_window_count' => (int) data_get($outcome, 'independent_window_count', 0),
            'positive_windows' => (int) data_get($outcome, 'positive_windows', 0),
            'powered_windows' => (int) data_get($outcome, 'powered_windows', 0),
            'minimum_powered_windows' => (int) data_get($outcome, 'minimum_powered_windows', 0),
            'minimum_trades_per_window' => (int) data_get($outcome, 'minimum_trades_per_window', 0),
            'windows' => (array) data_get($outcome, 'windows', []),
            'target_measurement' => (array) data_get($outcome, 'target_measurement', []),
            'invariant_vector' => (array) data_get($outcome, 'invariant_vector', []),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function recordOutcome(
        LabAgent $agent,
        LabLearningLanePair $pair,
        array $result,
        array $delta,
        ?AgentLearningLesson $lesson = null,
        ?ModelMarketPerformance $performance = null,
        ?object $forwardDecision = null,
    ): array {
        if (! Schema::hasTable('agent_learning_causal_experiments')) {
            return ['status' => 'unavailable', 'confirmed' => false, 'promotion_evidence' => false];
        }
        $experiment = AgentLearningCausalExperiment::query()
            ->where('guided_agent_id', $agent->id)
            ->orWhere('blinded_agent_id', $agent->id)
            ->orWhere('control_agent_id', $agent->id)
            ->first();
        if (! $experiment) {
            return ['status' => 'not_applicable', 'confirmed' => false, 'promotion_evidence' => false];
        }
        // Confirmation is monotonic. A duplicate queue callback may replay
        // an already persisted outcome, but it can never reopen or downgrade
        // a confirmed causal skill.
        if ((string) $experiment->status === 'confirmed') {
            return [
                'status' => 'confirmed',
                'confirmed' => true,
                'experiment_id' => (int) $experiment->id,
                'promotion_evidence' => false,
            ];
        }
        $role = match ((int) $agent->id) {
            (int) $experiment->guided_agent_id => $this->guidedRole($experiment),
            (int) $experiment->blinded_agent_id => 'blinded',
            default => 'frozen_control',
        };
        $windows = $this->windows($pair, $result);
        $measurementResult = $performance
            ? array_replace_recursive((array) $performance->metrics, $result)
            : $result;
        $evidence = (array) $experiment->evidence;
        $evidence['outcomes'][$role] = [
            'agent_id' => (int) $agent->id,
            'pair_id' => (int) $pair->id,
            'lesson_id' => $lesson?->id,
            'control_agent_id' => $pair->control_agent_id,
            'verified_control' => $pair->isVerifiedControlPair(),
            'target_delta' => $delta,
            'utility' => $this->utility((string) $pair->target, $delta),
            'independent_window_keys' => $windows['keys'],
            'independent_window_count' => $windows['count'],
            'independence_verified' => $windows['verified'],
            'purge_embargo_verified' => $windows['purge_embargo_verified'],
            'positive_windows' => $windows['positive'],
            'minimum_trades_per_window' => $windows['minimum_trades_per_window'],
            'powered_windows' => $windows['powered_windows'],
            'minimum_powered_windows' => $windows['minimum_powered_windows'],
            'power_contract_declared' => $windows['power_contract_declared'],
            'power_quorum_verified' => $windows['power_quorum_verified'],
            'windows' => $windows['windows'],
            'target_measurement' => $this->targetMeasurement((string) $pair->target, $measurementResult),
            'invariant_vector' => $this->invariantVector($measurementResult),
            'maximum_holding_bars' => $windows['maximum_holding_bars'],
            'execution_horizon_overlay_applied' => $windows['execution_horizon_overlay_applied'],
            'evidence_run_id' => data_get($result, 'evidence_run_id'),
            'performance_id' => $performance?->id,
            'forward_decision' => data_get($forwardDecision, 'decision'),
            'elite_agent_passport_status' => data_get($result, 'elite_agent_passport.status'),
            'recorded_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ];
        $experiment->update(['evidence' => $evidence, 'status' => 'outcomes_pending']);
        $experiment = $experiment->fresh();
        $guidedRole = $this->guidedRole($experiment);
        $guided = (array) data_get($experiment->evidence, 'outcomes.'.$guidedRole, []);
        $blinded = (array) data_get($experiment->evidence, 'outcomes.blinded', []);
        $control = (array) data_get($experiment->evidence, 'outcomes.frozen_control', []);
        if ($guided === [] || $blinded === [] || $control === []) {
            return [
                'status' => 'awaiting_counterfactual_outcomes',
                'confirmed' => false,
                'experiment_id' => (int) $experiment->id,
                'promotion_evidence' => false,
            ];
        }
        $required = max(3, (int) config('services.learning_lane.independent_confirmations_required', 3));
        $positiveRequired = 2;
        $guidedIntent = AgentLearningMutationIntent::query()->where('lab_agent_id', $experiment->guided_agent_id)->first();
        $guidedAgent = LabAgent::query()->with('modelVersion')->find($experiment->guided_agent_id);
        $guidedReceipt = (array) data_get($guidedAgent?->modelVersion?->metadata, 'learning_receipt', []);
        // Screening receipt status is diagnostic, not confirmation authority.
        // Requiring an early `provisional` result here makes the full causal
        // experiment circular: a screening no-effect could never be disproved
        // by the three independent full-replay windows it was sent to obtain.
        $repairExperiment = $guidedRole === 'repair_guided';
        $expectedInfluence = $repairExperiment ? 'causal_repair_guided' : 'memory_guided';
        $receiptValid = data_get($guidedReceipt, 'protocol') === LearningReceiptService::PROTOCOL
            && data_get($guidedReceipt, 'integrity.valid') === true
            && data_get($guidedReceipt, 'causal_influence') === $expectedInfluence
            && (string) data_get($guidedReceipt, 'changed_gene') === (string) $experiment->gene_key
            && ($repairExperiment
                ? ((array) data_get($guidedReceipt, 'causally_applied_lesson_ids', []) === []
                    && (int) data_get($experiment->evidence, 'source_causal_experiment_id', 0) > 0)
                : in_array((int) $experiment->source_lesson_id, array_map(
                    'intval',
                    (array) data_get($guidedReceipt, 'causally_applied_lesson_ids', []),
                ), true));
        $componentWindowEffect = $this->compareWindows($guided, $control, $required);
        $selectorWindowEffect = $this->compareWindows($guided, $blinded, $required);
        $componentTargetEffect = $this->compareTargetMeasurements($guided, $control);
        $selectorTargetEffect = $this->compareTargetMeasurements($guided, $blinded);
        $componentEffect = [
            ...$componentWindowEffect,
            'economic_window_effect' => $componentWindowEffect,
            'target_effect' => $componentTargetEffect,
            'passed' => data_get($componentWindowEffect, 'passed') === true
                && data_get($componentTargetEffect, 'passed') === true,
        ];
        $selectorEffect = [
            ...$selectorWindowEffect,
            'economic_window_effect' => $selectorWindowEffect,
            'target_effect' => $selectorTargetEffect,
            'passed' => data_get($selectorWindowEffect, 'passed') === true
                && data_get($selectorTargetEffect, 'passed') === true,
        ];
        $guidedBeatsControl = (bool) data_get($componentEffect, 'passed', false);
        $guidedBeatsBlinded = (bool) data_get($selectorEffect, 'passed', false);
        $confirmedWindowCount = (int) data_get($componentEffect, 'common_window_count', 0);
        $causalPositiveWindows = (int) data_get($componentEffect, 'positive_delta_windows', 0);
        $guidedPairId = (int) data_get($guided, 'pair_id', 0);
        $guidedPair = $guidedPairId > 0 ? LabLearningLanePair::query()->find($guidedPairId) : null;
        $canonicalSettlement = $guidedPair
            ? AgentLearningSettlement::query()
                ->where('source_type', LabLearningLanePair::class)
                ->where('source_id', $guidedPair->id)
                ->where('evidence_state', 'positive')
                ->where('hard_failure', false)
                ->latest('id')
                ->first()
            : null;
        $latestCanonicalSettlement = $guidedPair
            ? AgentLearningSettlement::query()
                ->where('source_type', LabLearningLanePair::class)
                ->where('source_id', $guidedPair->id)
                ->latest('id')
                ->first()
            : null;
        $nonTargetSafe = $this->nonTargetSafe($guidedPair);
        $reasons = [];
        if ((string) $experiment->status === 'invalid_counterfactual_contract'
            || data_get($experiment->evidence, 'construction_validation.status') !== 'ready_for_replay') {
            $reasons[] = 'COUNTERFACTUAL_CONSTRUCTION_INVALID';
        }
        if ($repairExperiment) {
            if ($guidedIntent?->influence_type !== 'causal_repair_guided'
                || (array) $guidedIntent?->causally_applied_lesson_ids !== []) {
                $reasons[] = 'GUIDED_REPAIR_FRONTIER_NOT_CAUSALLY_APPLIED';
            }
        } elseif ($guidedIntent?->influence_type !== 'memory_guided'
            || (array) $guidedIntent?->causally_applied_lesson_ids === []) {
            $reasons[] = 'GUIDED_MEMORY_NOT_CAUSALLY_APPLIED';
        }
        if (! $receiptValid) {
            $reasons[] = 'GUIDED_RECEIPT_INVALID';
        }
        if (! $guidedPair || ! $latestCanonicalSettlement) {
            $reasons[] = 'GUIDED_CANONICAL_SETTLEMENT_MISSING';
        } elseif (! $canonicalSettlement) {
            $reasons[] = 'GUIDED_ABSOLUTE_VIABILITY_FAILED';
        }
        if (! $nonTargetSafe) {
            $reasons[] = 'NON_TARGET_REGRESSION_UNSAFE';
        }
        if (data_get($guided, 'verified_control') !== true
            || (int) data_get($guided, 'control_agent_id') !== (int) $experiment->control_agent_id) {
            $reasons[] = 'FROZEN_CONTROL_MISMATCH';
        }
        if (data_get($blinded, 'verified_control') !== true
            || (int) data_get($blinded, 'control_agent_id') !== (int) $experiment->control_agent_id) {
            $reasons[] = 'BLINDED_CONTROL_MISMATCH';
        }
        if (data_get($control, 'verified_control') !== true
            || (int) data_get($control, 'agent_id') !== (int) $experiment->control_agent_id) {
            $reasons[] = 'FROZEN_CONTROL_OUTCOME_MISSING_OR_INVALID';
        }
        if (! $guidedBeatsControl) {
            $reasons[] = 'GUIDED_DID_NOT_BEAT_CONTROL';
        }
        if (! $guidedBeatsBlinded) {
            $reasons[] = 'GUIDED_DID_NOT_BEAT_BLINDED';
        }
        if (data_get($componentTargetEffect, 'passed') !== true) {
            $reasons[] = 'GUIDED_DECLARED_TARGET_DID_NOT_BEAT_CONTROL';
        }
        if (data_get($selectorTargetEffect, 'passed') !== true) {
            $reasons[] = 'GUIDED_DECLARED_TARGET_DID_NOT_BEAT_BLINDED';
        }
        if (! (bool) data_get($componentEffect, 'protocol_verified', false)
            || ! (bool) data_get($selectorEffect, 'protocol_verified', false)
            || (int) data_get($componentEffect, 'common_window_count', 0) < (int) data_get($componentEffect, 'required_common_windows', $required)
            || (int) data_get($componentEffect, 'positive_delta_windows', 0) < (int) data_get($componentEffect, 'required_positive_windows', $positiveRequired)) {
            $reasons[] = 'INDEPENDENT_WINDOWS_INSUFFICIENT';
        }
        if ($reasons !== []) {
            $repairDepth = (int) data_get($experiment->evidence, 'repair_lineage.depth', 0);
            $experimentKind = (string) data_get($experiment->evidence, 'experiment_kind');
            $interactionExperiment = $experimentKind === 'causal_architecture_interaction';
            $architectureExperiment = in_array($experimentKind, [
                'causal_architecture_escape', 'causal_architecture_interaction',
            ], true);
            $architectureDepth = (int) data_get($experiment->evidence, 'architecture_lineage.depth', 0);
            $scalarBudgetExhausted = $repairExperiment && ! $architectureExperiment && $repairDepth >= 3;
            $architectureBudgetExhausted = ! $interactionExperiment && $architectureExperiment && $architectureDepth >= 3;
            $researchRatchet = $this->researchRatchetDecision(
                $experiment,
                $guidedPair,
                $guidedAgent,
                $latestCanonicalSettlement,
                $componentEffect,
                $selectorEffect,
                $reasons,
            );
            $experiment->update([
                'status' => 'provisional',
                'independent_window_count' => $confirmedWindowCount,
                'guided_beats_blinded' => $guidedBeatsBlinded,
                'guided_beats_control' => $guidedBeatsControl,
                'evidence' => [...((array) $experiment->evidence),
                    'confirmation_evidence_protocol' => self::EVIDENCE_PROTOCOL,
                    'component_effect' => $componentEffect,
                    'selector_effect' => $selectorEffect,
                    'confirmation_blockers' => $reasons,
                    'absolute_viability' => [
                        'status' => $canonicalSettlement ? 'passed' : ($latestCanonicalSettlement ? 'failed' : 'missing'),
                        'settlement_id' => $latestCanonicalSettlement?->id,
                        'evidence_state' => $latestCanonicalSettlement?->evidence_state,
                        'hard_failure' => $latestCanonicalSettlement?->hard_failure,
                        'vetoes' => (array) data_get($latestCanonicalSettlement?->reward_components, 'vetoes', []),
                        'rule' => 'Relative uplift cannot become inheritable skill while absolute economic safety vetoes fail.',
                        'promotion_evidence' => false,
                    ],
                    'repair_frontier' => [
                        'status' => ! $latestCanonicalSettlement
                            ? 'awaiting_settlement'
                            : ($interactionExperiment
                                ? 'architecture_portfolio_exhausted'
                                : ($architectureExperiment
                                ? ($architectureBudgetExhausted ? 'architecture_portfolio_required' : 'architecture_escape_retry_required')
                                : ($scalarBudgetExhausted ? 'architecture_escape_required' : 'bounded_repair_required'))),
                        'preserve_as_observation_only' => (bool) data_get($componentEffect, 'passed', false),
                        // A causally positive but still absolutely losing
                        // model is never a production parent. When all
                        // counterfactual and non-target checks pass, however,
                        // it may become the frozen baseline of the next
                        // research-only repair. This is the missing
                        // compounding step: the next experiment measures one
                        // additional gene on top of an already replicated
                        // beneficial component instead of resetting to the
                        // original losing control.
                        'research_ratchet' => $researchRatchet,
                        'inherit_gene' => false,
                        'target' => (string) ($latestCanonicalSettlement?->failure_class ?: 'evidence_completion'),
                        'next_experiment' => $interactionExperiment
                            ? 'manual_architecture_redesign_required'
                            : ($scalarBudgetExhausted
                                ? 'architecture_hypothesis_paired_replay'
                                : ($architectureExperiment
                                ? ($architectureBudgetExhausted
                                    ? 'bounded_architecture_interaction_paired_replay'
                                    : 'one_gene_structural_paired_replay')
                                : 'one_gene_paired_replay')),
                        'scalar_repair_depth' => $repairDepth,
                        'scalar_repair_budget' => 3,
                        'architecture_escape_depth' => $architectureDepth,
                        'architecture_escape_budget' => 3,
                        'required_controls' => ['frozen_control', 'memory_blinded', 'non_target_invariants'],
                        'promotion_evidence' => false,
                    ],
                    'promotion_evidence' => false,
                ],
            ]);

            return ['status' => 'provisional', 'confirmed' => false, 'reason_codes' => $reasons, 'promotion_evidence' => false];
        }
        $experiment->update([
            'status' => 'confirmed',
            'independent_window_count' => $confirmedWindowCount,
            'guided_beats_blinded' => true,
            'guided_beats_control' => true,
            'confirmed_at' => now(),
            'evidence' => [...((array) $experiment->evidence),
                'confirmation_evidence_protocol' => self::EVIDENCE_PROTOCOL,
                'confirmation_protocol' => $this->confirmationProtocol($experiment),
                'component_effect' => $componentEffect,
                'selector_effect' => $selectorEffect,
                'confirmation_blockers' => [],
                'promotion_evidence' => false,
            ],
        ]);
        $guidedLesson = AgentLearningLesson::query()
            ->where('lab_agent_id', $experiment->guided_agent_id)
            ->where('parameter_key', $experiment->gene_key)
            ->where('lesson_type', 'skill_lesson')
            ->where(fn ($query) => $query->where('evidence->pair_id', $guidedPairId)->orWhere('id', data_get($guided, 'lesson_id')))
            ->latest('id')
            ->first();
        $guidedLesson?->update([
            'status' => 'confirmed',
            'independent_window_count' => $confirmedWindowCount,
            'confirmation_count' => $required,
            'evidence' => [...((array) $guidedLesson?->evidence),
                'causal_experiment_id' => (int) $experiment->id,
                'confirmation_protocol' => $this->confirmationProtocol($experiment),
                'confirmed_at' => now()->utc()->toIso8601String(),
                'promotion_evidence' => false,
            ],
            'expires_at' => null,
        ]);
        if ($guidedPairId > 0) {
            if ($guidedPair && $canonicalSettlement) {
                app(LearningCompilerService::class)->compileCanonical([
                    // Upgrade the provisional receipt produced by the
                    // canonical outbox instead of creating a parallel memory
                    // row for the same replay evidence.
                    'source_key' => 'canonical-pair:'.$guidedPair->id.':'.(string) data_get($guided, 'evidence_run_id', 'none'),
                    'pair_id' => $guidedPair->id,
                    'settlement_id' => $canonicalSettlement->id,
                    'causal_experiment_id' => $experiment->id,
                    'lab_agent_id' => $guidedPair->candidate_agent_id,
                    'lab_generation_id' => $experiment->lab_generation_id,
                    'symbol' => $experiment->symbol,
                    'timeframe' => $experiment->timeframe,
                    'parameter_key' => $experiment->gene_key,
                    'old_value' => $guidedIntent?->old_value,
                    'new_value' => $guidedIntent?->new_value,
                    'causal_uplift_r' => (float) data_get($guided, 'target_delta.delta', 0),
                    'scope' => ['strategy_family' => $experiment->strategy_family],
                    'source_experiments' => ['causal-experiment-'.$experiment->id],
                    'support' => max(1, $confirmedWindowCount),
                    'independent_windows' => $confirmedWindowCount,
                    'positive_windows' => $causalPositiveWindows,
                    'non_target_regression' => false,
                ]);
                $guidedPair->update(['metadata' => [
                    ...((array) $guidedPair->metadata),
                    'skill_state' => 'confirmed',
                    'causal_experiment_id' => (int) $experiment->id,
                    'promotion_evidence' => false,
                ]]);
                app(CanonicalLearningOutboxService::class)->finalizeDispatch($guidedPair->fresh(), 'skill_confirmed');
            }
        }
        $guidedIntent?->update(['status' => 'settled', 'metadata' => [
            ...((array) $guidedIntent?->metadata),
            'causal_experiment_id' => (int) $experiment->id,
            'causal_confirmation' => 'confirmed',
            'promotion_evidence' => false,
        ]]);
        if ($guidedAgent?->modelVersion) {
            $guidedMetadata = (array) $guidedAgent->modelVersion->metadata;
            $priorReceipt = (array) data_get($guidedMetadata, 'learning_receipt', []);
            $priorSettlement = (array) data_get($priorReceipt, 'settlement', []);
            $guidedMetadata['learning_receipt'] = [
                ...$priorReceipt,
                'status' => 'confirmed',
                'settlement' => [
                    ...$priorSettlement,
                    'status' => 'confirmed',
                    'causal_experiment_id' => (int) $experiment->id,
                    'confirmation_protocol' => $this->confirmationProtocol($experiment),
                    'independent_window_count' => $confirmedWindowCount,
                    'positive_windows' => $causalPositiveWindows,
                    'evidence_run_ids' => collect((array) data_get($experiment->fresh()->evidence, 'outcomes', []))
                        ->pluck('evidence_run_id')->filter()->values()->all(),
                    'confirmed_at' => now()->utc()->toIso8601String(),
                    'promotion_evidence' => false,
                ],
            ];
            $guidedMetadata['causal_learning_experiment'] = [
                'protocol' => $this->confirmationProtocol($experiment),
                'experiment_id' => (int) $experiment->id,
                'status' => 'confirmed',
                'guided_beats_blinded' => true,
                'guided_beats_control' => true,
                'independent_window_count' => (int) $experiment->independent_window_count,
                'source_lesson_id' => $experiment->source_lesson_id,
                'confirmed_at' => $experiment->confirmed_at?->toIso8601String(),
                'promotion_evidence' => false,
            ];
            $guidedAgent->modelVersion->update(['metadata' => $guidedMetadata]);
        }
        $mentor = $this->projectConfirmedGuidedMentor(
            $guidedAgent,
            [
                ...$guided,
                'independent_window_count' => $confirmedWindowCount,
                'positive_windows' => $causalPositiveWindows,
            ],
            $required,
        );
        if ($mentor !== null) {
            $experiment->update(['evidence' => [
                ...((array) $experiment->fresh()->evidence),
                'confirmed_guided_mentor' => $mentor,
                'promotion_evidence' => false,
            ]]);
        }
        $policy = app(LearningPolicyRegistryService::class)->register(
            ($repairExperiment ? 'causal-repair:' : 'causal-memory:').strtoupper($experiment->symbol).':'.strtoupper($experiment->timeframe).':'.$experiment->strategy_family,
            [
                'protocol' => $repairExperiment ? 'causal_repair_policy_v1' : 'causal_memory_policy_v1',
                'gene' => $experiment->gene_key,
                'value' => data_get($guidedIntent?->new_value, 'value'),
                'source_lesson_id' => $experiment->source_lesson_id,
                'causal_experiment_id' => (int) $experiment->id,
                'selection_rule' => 'Use only in compatible contexts; preserve blinded/control evidence and all ordinary gates.',
                'promotion_evidence' => false,
            ],
            [
                'symbol' => $experiment->symbol,
                'timeframe' => $experiment->timeframe,
                'strategy_family' => $experiment->strategy_family,
            ],
        );
        if ($policy instanceof AgentLearningPolicy) {
            $policy = app(LearningPolicyRegistryService::class)->transition($policy, 'shadow', [
                'causal_experiment_id' => (int) $experiment->id,
                'confirmed_lesson_id' => $guidedLesson?->id,
                'promotion_evidence' => false,
            ]);
        }

        return [
            'status' => 'confirmed',
            'confirmed' => true,
            'experiment_id' => (int) $experiment->id,
            'guided_lesson_id' => $guidedLesson?->id,
            'policy_id' => $policy instanceof AgentLearningPolicy ? (int) $policy->id : null,
            'mentor' => $mentor,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function researchRatchetDecision(
        AgentLearningCausalExperiment $experiment,
        ?LabLearningLanePair $guidedPair,
        ?LabAgent $guidedAgent,
        ?AgentLearningSettlement $latestSettlement,
        array $componentEffect,
        array $selectorEffect,
        array $confirmationReasons,
    ): array {
        $nonTarget = (array) ($guidedPair?->non_target_regression ?? []);
        $nonTargetStatus = (string) data_get($nonTarget, 'status', 'not_recorded');
        $explicitNonTargetPass = data_get($nonTarget, 'safe') === true
            && in_array($nonTargetStatus, ['passed', 'confirmed'], true);
        $otherBlockers = array_values(array_diff(
            array_values(array_unique(array_map('strval', $confirmationReasons))),
            ['GUIDED_ABSOLUTE_VIABILITY_FAILED'],
        ));
        $reasonCodes = [];
        if (! $guidedPair || ! $guidedPair->isVerifiedControlPair()) {
            $reasonCodes[] = 'VERIFIED_GUIDED_PAIR_REQUIRED';
        }
        if (! $guidedAgent || ! $guidedAgent->modelVersion) {
            $reasonCodes[] = 'GUIDED_RESEARCH_BASELINE_MISSING';
        }
        if (! $latestSettlement || ! $latestSettlement->hard_failure
            || (string) $latestSettlement->evidence_state !== 'negative') {
            $reasonCodes[] = 'ABSOLUTE_FAILURE_SETTLEMENT_REQUIRED';
        }
        if (data_get($componentEffect, 'passed') !== true) {
            $reasonCodes[] = 'COMPONENT_EFFECT_NOT_REPLICATED';
        }
        if (data_get($selectorEffect, 'passed') !== true) {
            $reasonCodes[] = 'SELECTOR_EFFECT_NOT_REPLICATED';
        }
        if (! $explicitNonTargetPass) {
            $reasonCodes[] = 'NON_TARGET_EVIDENCE_NOT_EXPLICITLY_PASSED';
        }
        if ($otherBlockers !== []) {
            $reasonCodes = [...$reasonCodes, ...$otherBlockers];
        }
        $allowed = $reasonCodes === [];
        $previous = (array) data_get($experiment->evidence, 'research_ratchet.retained_steps', []);
        $candidateStep = [
            'experiment_id' => (int) $experiment->id,
            'pair_id' => (int) ($guidedPair?->id ?? 0),
            'agent_id' => (int) ($guidedAgent?->id ?? 0),
            'model_version_id' => (int) ($guidedAgent?->model_version_id ?? 0),
            'gene' => (string) $experiment->gene_key,
            'component_mean_delta' => (float) data_get($componentEffect, 'mean_delta', 0),
            'component_positive_windows' => (int) data_get($componentEffect, 'positive_delta_windows', 0),
            'selector_mean_delta' => (float) data_get($selectorEffect, 'mean_delta', 0),
            'selector_positive_windows' => (int) data_get($selectorEffect, 'positive_delta_windows', 0),
            'absolute_failure_class' => $latestSettlement?->failure_class,
            'absolute_settlement_id' => $latestSettlement?->id,
            'promotion_evidence' => false,
        ];

        return [
            'protocol' => 'causal_research_ratchet_v1',
            'status' => $allowed ? 'eligible_research_baseline' : 'blocked',
            'allowed' => $allowed,
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'baseline_policy' => $allowed
                ? 'retain_causal_component_for_next_research_only_step'
                : 'reset_to_original_frozen_control',
            'candidate_step' => $candidateStep,
            'retained_steps' => $allowed ? [...$previous, $candidateStep] : $previous,
            'root_source_pair_id' => (int) data_get(
                $experiment->evidence,
                'root_source_pair_id',
                data_get($experiment->evidence, 'source_pair_id', 0),
            ),
            'root_control_agent_id' => (int) data_get(
                $experiment->evidence,
                'research_ratchet.root_control_agent_id',
                data_get($experiment->evidence, 'source_control_agent_id', 0),
            ),
            'root_baseline_model_version_id' => (int) data_get(
                $experiment->evidence,
                'research_ratchet.root_baseline_model_version_id',
                data_get($experiment->evidence, 'baseline_model_version_id', 0),
            ),
            'research_baseline_agent_id' => $allowed ? (int) $guidedAgent->id : null,
            'research_baseline_model_version_id' => $allowed ? (int) $guidedAgent->model_version_id : null,
            'production_parent_allowed' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed>|null */
    private function projectConfirmedGuidedMentor(?LabAgent $agent, array $guided, int $required): ?array
    {
        if (! $agent || (int) data_get($guided, 'performance_id', 0) <= 0) {
            return null;
        }
        $performance = ModelMarketPerformance::query()->find((int) data_get($guided, 'performance_id'));
        if (! $performance || (int) $performance->model_version_id !== (int) $agent->model_version_id) {
            return null;
        }
        $windows = (int) data_get($guided, 'independent_window_count', 0);
        $positive = (int) data_get($guided, 'positive_windows', 0);
        $result = [
            'evidence_run_id' => data_get($guided, 'evidence_run_id'),
            'elite_agent_passport' => ['status' => data_get($guided, 'elite_agent_passport_status')],
            'verified_mutation_skill' => [
                'protocol' => 'learning_lane_independent_skill_v1',
                'status' => 'confirmed',
                'independent_observation_count' => $windows,
                'independent_confirmations_required' => $required,
                'required_windows' => $windows,
                'minimum_positive_windows' => $positive,
                'independent_forward_windows' => [
                    'independent_windows' => $windows,
                    'positive_windows' => $positive,
                ],
                'promotion_evidence' => false,
            ],
        ];
        $forward = filled(data_get($guided, 'forward_decision'))
            ? (object) ['decision' => (string) data_get($guided, 'forward_decision')]
            : null;

        return app(SkillMentorService::class)->recordFullReplayOutcome(
            $agent->fresh(['modelVersion', 'generation']),
            $performance->fresh(),
            $result,
            $forward,
        );
    }

    /** @return array<string, mixed> */
    private function windows(LabLearningLanePair $pair, array $result): array
    {
        $protocol = (array) data_get($result, 'forward_window_protocol', []);
        $rows = collect((array) data_get($protocol, 'windows', []))
            ->filter(fn ($row): bool => is_array($row))
            ->map(function (array $row): array {
                $id = (string) data_get($row, 'id', data_get($row, 'window_key', data_get($row, 'key', '')));

                return [
                    'id' => $id,
                    'start' => data_get($row, 'start'),
                    'end' => data_get($row, 'end'),
                    'score' => is_numeric(data_get($row, 'score')) ? (float) data_get($row, 'score') : null,
                    'profit_factor' => is_numeric(data_get($row, 'profit_factor')) ? (float) data_get($row, 'profit_factor') : null,
                    'net_profit_percent' => is_numeric(data_get($row, 'net_profit_percent')) ? (float) data_get($row, 'net_profit_percent') : null,
                    'trades' => (int) data_get($row, 'trades', 0),
                ];
            })->filter(fn (array $row): bool => $row['id'] !== '')->unique('id')->values();
        $keys = $rows->pluck('id')->map('strval')->values();
        $observed = (int) data_get($protocol, 'observed_windows', 0);
        $holding = (int) data_get($protocol, 'maximum_holding_bars', 0);
        $powerDeclared = data_get($protocol, 'power_quorum_passed') !== null;
        $minimumTrades = max(1, (int) data_get(
            $protocol,
            'minimum_trades_per_powered_window',
            config('services.learning_lane.causal_minimum_trades_per_window', 8),
        ));
        $poweredWindows = $rows->filter(fn (array $row): bool => (int) $row['trades'] >= $minimumTrades)->count();
        $minimumPowered = max(3, (int) data_get(
            $protocol,
            'minimum_powered_windows',
            config('services.learning_lane.causal_minimum_powered_windows', 6),
        ));

        return [
            'keys' => $keys->all(),
            'windows' => $rows->all(),
            'count' => $keys->count(),
            'verified' => data_get($protocol, 'independence_verified') === true
                && data_get($protocol, 'overlap_detected') !== true
                && $keys->count() === $observed,
            'purge_embargo_verified' => data_get($protocol, 'purge_embargo_applied') === true
                && data_get($protocol, 'label_holding_period_purged') === true
                && (int) data_get($protocol, 'purge_bars', 0) > 0
                && (int) data_get($protocol, 'embargo_bars', 0) > 0,
            'positive' => max((int) data_get($protocol, 'positive_windows', 0), (int) data_get($protocol, 'confirmed_windows', 0)),
            'minimum_trades_per_window' => $minimumTrades,
            'powered_windows' => $poweredWindows,
            'minimum_powered_windows' => $minimumPowered,
            'power_contract_declared' => $powerDeclared,
            'power_quorum_verified' => ! $powerDeclared || (
                data_get($protocol, 'power_quorum_passed') === true
                && $poweredWindows >= $minimumPowered
            ),
            'maximum_holding_bars' => $holding,
            'execution_horizon_overlay_applied' => data_get($protocol, 'execution_horizon_overlay_applied') === true
                && $holding > 0,
        ];
    }

    /** @return array<string, mixed> */
    private function compareWindows(array $treatment, array $baseline, int $required): array
    {
        $treatmentRows = collect((array) data_get($treatment, 'windows', []))->keyBy('id');
        $baselineRows = collect((array) data_get($baseline, 'windows', []))->keyBy('id');
        $treatmentKeys = $treatmentRows->keys()->map('strval')->sort()->values();
        $baselineKeys = $baselineRows->keys()->map('strval')->sort()->values();
        $common = $treatmentKeys->intersect($baselineKeys)->values();
        $treatmentPower = data_get($treatment, 'power_contract_declared') === true;
        $baselinePower = data_get($baseline, 'power_contract_declared') === true;
        $powerContractMatched = $treatmentPower === $baselinePower;
        $powerContract = $treatmentPower && $baselinePower;
        $minimumTrades = max(
            1,
            (int) data_get($treatment, 'minimum_trades_per_window', 0),
            (int) data_get($baseline, 'minimum_trades_per_window', 0),
        );
        if ($powerContract) {
            $common = $common->filter(fn (string $key): bool => (int) data_get($treatmentRows->get($key), 'trades', 0) >= $minimumTrades
                && (int) data_get($baselineRows->get($key), 'trades', 0) >= $minimumTrades
            )->values();
        }
        $requiredCommon = $powerContract
            ? max($required, (int) config('services.learning_lane.causal_minimum_powered_windows', 6))
            : $required;
        $requiredPositive = $powerContract
            ? max(2, (int) config('services.learning_lane.causal_minimum_positive_windows', 4))
            : 2;
        $sameHorizon = (int) data_get($treatment, 'maximum_holding_bars', 0) > 0
            && (int) data_get($treatment, 'maximum_holding_bars', 0)
                === (int) data_get($baseline, 'maximum_holding_bars', -1);
        $protocolVerified = data_get($treatment, 'independence_verified') === true
            && data_get($baseline, 'independence_verified') === true
            && data_get($treatment, 'purge_embargo_verified') === true
            && data_get($baseline, 'purge_embargo_verified') === true
            && data_get($treatment, 'execution_horizon_overlay_applied') === true
            && data_get($baseline, 'execution_horizon_overlay_applied') === true
            && $powerContractMatched
            && (! $powerContract || (
                data_get($treatment, 'power_quorum_verified') === true
                && data_get($baseline, 'power_quorum_verified') === true
            ))
            && $sameHorizon
            && $treatmentKeys->all() === $baselineKeys->all();
        $deltas = $common->map(function (string $key) use ($treatmentRows, $baselineRows): ?array {
            $left = $this->windowScore((array) $treatmentRows->get($key));
            $right = $this->windowScore((array) $baselineRows->get($key));
            if ($left === null || $right === null) {
                return null;
            }

            return ['window_id' => $key, 'treatment' => $left, 'baseline' => $right, 'delta' => round($left - $right, 8)];
        })->filter()->values();
        $positive = $deltas->filter(fn (array $row): bool => (float) $row['delta'] > 0.00000001)->count();
        $mean = $deltas->isEmpty() ? 0.0 : (float) $deltas->avg('delta');

        return [
            'protocol' => 'paired_disjoint_window_delta_v1',
            'protocol_verified' => $protocolVerified,
            'common_window_count' => $deltas->count(),
            'required_common_windows' => $requiredCommon,
            'required_positive_windows' => $requiredPositive,
            'minimum_trades_per_window' => $powerContract ? $minimumTrades : null,
            'power_contract_applied' => $powerContract,
            'positive_delta_windows' => $positive,
            'mean_delta' => round($mean, 8),
            'window_deltas' => $deltas->all(),
            'passed' => $protocolVerified
                && $deltas->count() >= $requiredCommon
                && $positive >= $requiredPositive
                && $mean > 0.00000001,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function targetMeasurement(string $target, array $result): array
    {
        $target = strtolower(trim($target));
        if (in_array($target, ['drawdown_risk', 'risk_exit'], true)) {
            return $this->compositeTargetMeasurement($target, [
                'drawdown' => $this->metricMeasurement(
                    $result,
                    'drawdown',
                    'lower',
                    ['max_drawdown_percent', 'max_drawdown'],
                ),
                'risk_of_ruin' => $this->metricMeasurement(
                    $result,
                    'risk_of_ruin',
                    'lower',
                    ['monte_carlo.risk_of_ruin_percent', 'risk_of_ruin_percent'],
                ),
            ]);
        }
        if ($target === 'volatility_session_stability') {
            return $this->compositeTargetMeasurement($target, [
                'worst_volatility_pf' => [
                    'metric' => 'worst_volatility_pf',
                    'direction' => 'higher',
                    'value' => $this->minimumGroupedNumber($result, 'pf_attribution.by_volatility'),
                    'source' => 'pf_attribution.by_volatility.*.net_pf',
                ],
                'worst_session_pf' => [
                    'metric' => 'worst_session_pf',
                    'direction' => 'higher',
                    'value' => $this->minimumGroupedNumber($result, 'pf_attribution.by_session'),
                    'source' => 'pf_attribution.by_session.*.net_pf',
                ],
            ]);
        }
        if ($target === 'exit_topology') {
            return $this->compositeTargetMeasurement($target, [
                'profit_factor' => $this->metricMeasurement(
                    $result,
                    'profit_factor',
                    'higher',
                    ['pf_attribution.summary.net_pf', 'profit_factor'],
                ),
                'drawdown' => $this->metricMeasurement(
                    $result,
                    'drawdown',
                    'lower',
                    ['max_drawdown_percent', 'max_drawdown'],
                ),
            ]);
        }
        if ($target === 'transition_firewall') {
            return $this->compositeTargetMeasurement($target, [
                'temporal_survival' => $this->metricMeasurement(
                    $result,
                    'temporal_survival',
                    'higher',
                    ['temporal_survival.temporal_survival_score'],
                ),
                'regime_coverage' => $this->metricMeasurement(
                    $result,
                    'regime_coverage',
                    'higher',
                    ['statistical_evidence.edge_quality.worst_regime_pf'],
                ),
            ]);
        }
        if ($target === 'portfolio_router') {
            return $this->compositeTargetMeasurement($target, [
                'calibration' => $this->metricMeasurement(
                    $result,
                    'calibration',
                    'higher',
                    [
                        'statistical_evidence.edge_quality.confidence_calibration.calibration_score',
                        'statistical_evidence.edge_quality.confidence_calibration.score',
                    ],
                    true,
                ),
                'abstention_quality' => $this->metricMeasurement(
                    $result,
                    'abstention_quality',
                    'higher',
                    ['opportunity_recall.abstention_precision'],
                ),
            ]);
        }
        if ($target === 'stress_cost') {
            $stress = $this->metricMeasurement(
                $result,
                'stress_cost_pf',
                'higher',
                ['pf_attribution.stress_cost.profit_factor', 'screening_survival.stress_cost_pf'],
            );
            if (is_numeric($stress['value'])) {
                return $this->singleTargetMeasurement($target, $stress);
            }

            return $this->singleTargetMeasurement($target, $this->metricMeasurement(
                $result,
                'realized_cost_burden_percent',
                'lower',
                ['pf_attribution.summary.cost_to_gross_profit_percent'],
            ));
        }
        [$metric, $direction, $paths] = match ($target) {
            'profit_factor', 'architecture' => ['profit_factor', 'higher', [
                'pf_attribution.summary.net_pf', 'profit_factor',
            ]],
            'temporal_stability', 'monthly_survival', 'robustness' => ['temporal_stability', 'higher', [
                'statistical_evidence.edge_quality.worst_fold_profit_factor',
                'screening_survival.worst_temporal_chunk_pf', 'screening_survival.worst_window_pf',
            ]],
            'regime_coverage', 'rolling_regime' => ['regime_coverage', 'higher', [
                'statistical_evidence.edge_quality.worst_regime_pf', 'screening_survival.worst_regime_pf',
            ]],
            'drawdown', 'max_drawdown' => ['drawdown', 'lower', [
                'max_drawdown_percent', 'max_drawdown',
            ]],
            'risk' => ['risk_of_ruin', 'lower', [
                'monte_carlo.risk_of_ruin_percent', 'risk_of_ruin_percent',
            ]],
            'trade_frequency' => ['trade_frequency', 'higher', ['total_trades', 'entry_funnel.accepted_entries']],
            'opportunity_recall' => ['opportunity_recall', 'higher', [
                'opportunity_recall.opportunity_recall', 'opportunity_metrics.recall',
            ]],
            'unknown_state_curiosity' => ['opportunity_recall', 'higher', [
                'opportunity_recall.opportunity_recall', 'opportunity_metrics.recall',
            ]],
            default => ['unknown', 'higher', []],
        };

        return $this->singleTargetMeasurement(
            $target,
            $this->metricMeasurement($result, $metric, $direction, $paths),
        );
    }

    /** @return array<string,mixed> */
    private function compareTargetMeasurements(array $candidate, array $baseline): array
    {
        $candidateMeasurement = (array) data_get($candidate, 'target_measurement', []);
        $baselineMeasurement = (array) data_get($baseline, 'target_measurement', []);
        $candidateComponents = (array) data_get($candidateMeasurement, 'components', []);
        $baselineComponents = (array) data_get($baselineMeasurement, 'components', []);
        if ($candidateComponents !== [] || $baselineComponents !== []) {
            $keysMatch = array_keys($candidateComponents) === array_keys($baselineComponents);
            $effects = [];
            $missing = [];
            $regressed = [];
            $improved = [];
            foreach (array_keys($candidateComponents) as $metric) {
                $left = (array) ($candidateComponents[$metric] ?? []);
                $right = (array) ($baselineComponents[$metric] ?? []);
                $leftValue = $left['value'] ?? null;
                $rightValue = $right['value'] ?? null;
                $sameContract = ($left['metric'] ?? null) === ($right['metric'] ?? null)
                    && ($left['direction'] ?? null) === ($right['direction'] ?? null);
                if (! $sameContract || ! is_numeric($leftValue) || ! is_numeric($rightValue)) {
                    $missing[] = (string) $metric;

                    continue;
                }
                $rawDelta = (float) $leftValue - (float) $rightValue;
                $orientedDelta = ($left['direction'] ?? null) === 'lower' ? -$rawDelta : $rawDelta;
                if ($orientedDelta < -0.00000001) {
                    $regressed[] = (string) $metric;
                } elseif ($orientedDelta > 0.00000001) {
                    $improved[] = (string) $metric;
                }
                $effects[$metric] = [
                    'candidate_value' => (float) $leftValue,
                    'baseline_value' => (float) $rightValue,
                    'raw_delta' => round($rawDelta, 8),
                    'oriented_delta' => round($orientedDelta, 8),
                    'direction' => (string) ($left['direction'] ?? ''),
                ];
            }
            $passed = $keysMatch && $missing === [] && $regressed === [] && $improved !== [];

            return [
                'protocol' => 'declared_composite_target_effect_v1',
                'status' => $passed ? 'improved' : ($missing !== [] || ! $keysMatch ? 'incomplete' : 'not_improved'),
                'target' => data_get($candidateMeasurement, 'target'),
                'effects' => $effects,
                'improved_metrics' => $improved,
                'regressed_metrics' => $regressed,
                'missing_or_mismatched_metrics' => $missing,
                'component_keys_match' => $keysMatch,
                'passed' => $passed,
                'promotion_evidence' => false,
            ];
        }
        $candidateValue = data_get($candidateMeasurement, 'value');
        $baselineValue = data_get($baselineMeasurement, 'value');
        $sameMetric = filled(data_get($candidateMeasurement, 'metric'))
            && data_get($candidateMeasurement, 'metric') === data_get($baselineMeasurement, 'metric')
            && data_get($candidateMeasurement, 'direction') === data_get($baselineMeasurement, 'direction');
        if (! $sameMetric || ! is_numeric($candidateValue) || ! is_numeric($baselineValue)) {
            return [
                'protocol' => 'declared_target_effect_v1',
                'status' => 'incomplete',
                'passed' => false,
                'reason_code' => 'DECLARED_TARGET_MEASUREMENT_MISSING_OR_MISMATCHED',
                'candidate' => $candidateMeasurement,
                'baseline' => $baselineMeasurement,
                'promotion_evidence' => false,
            ];
        }

        $rawDelta = (float) $candidateValue - (float) $baselineValue;
        $orientedDelta = data_get($candidateMeasurement, 'direction') === 'lower'
            ? -$rawDelta
            : $rawDelta;

        return [
            'protocol' => 'declared_target_effect_v1',
            'status' => $orientedDelta > 0.00000001 ? 'improved' : 'not_improved',
            'metric' => (string) data_get($candidateMeasurement, 'metric'),
            'direction' => (string) data_get($candidateMeasurement, 'direction'),
            'candidate_value' => (float) $candidateValue,
            'baseline_value' => (float) $baselineValue,
            'raw_delta' => round($rawDelta, 8),
            'oriented_delta' => round($orientedDelta, 8),
            'passed' => $orientedDelta > 0.00000001,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function invariantVector(array $result): array
    {
        $calibration = $this->firstNumber($result, [
            'statistical_evidence.edge_quality.confidence_calibration.calibration_score',
            'confidence_calibration.calibration_score',
        ]);
        if ($calibration !== null && $calibration > 1) {
            $calibration /= 100;
        }

        return [
            'protocol' => 'causal_invariant_vector_v1',
            'edge_quality' => $this->firstNumber($result, ['pf_attribution.summary.net_pf', 'profit_factor']),
            'stress_cost' => $this->firstNumber($result, [
                'pf_attribution.stress_cost.profit_factor', 'screening_survival.stress_cost_pf',
            ]),
            'cost_efficiency' => $this->firstNumber($result, [
                'pf_attribution.summary.cost_to_gross_profit_percent',
            ]),
            'drawdown' => $this->firstNumber($result, ['max_drawdown_percent', 'max_drawdown']),
            'risk_of_ruin' => $this->firstNumber($result, ['monte_carlo.risk_of_ruin_percent', 'risk_of_ruin_percent']),
            'temporal_stability' => $this->firstNumber($result, [
                'statistical_evidence.edge_quality.worst_fold_profit_factor',
                'screening_survival.worst_temporal_chunk_pf', 'screening_survival.worst_window_pf',
            ]),
            'regime_coverage' => $this->firstNumber($result, [
                'statistical_evidence.edge_quality.worst_regime_pf', 'screening_survival.worst_regime_pf',
            ]),
            'volatility_stability' => $this->minimumGroupedNumber($result, 'pf_attribution.by_volatility'),
            'session_stability' => $this->minimumGroupedNumber($result, 'pf_attribution.by_session'),
            'calibration' => $calibration,
            'abstention_quality' => $this->firstNumber($result, ['opportunity_recall.abstention_precision']),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function compareNonTargetInvariants(array $candidate, array $baseline, string $target): array
    {
        $candidateVector = (array) data_get($candidate, 'invariant_vector', []);
        $baselineVector = (array) data_get($baseline, 'invariant_vector', []);
        $excluded = match (strtolower(trim($target))) {
            'profit_factor', 'architecture' => ['edge_quality'],
            'stress_cost' => ['stress_cost', 'cost_efficiency'],
            'volatility_session_stability' => ['volatility_stability', 'session_stability'],
            'exit_topology' => ['edge_quality', 'drawdown'],
            'risk_exit', 'drawdown_risk' => ['drawdown', 'risk_of_ruin'],
            'transition_firewall' => ['temporal_stability', 'regime_coverage'],
            'portfolio_router' => ['calibration', 'abstention_quality'],
            'temporal_stability', 'monthly_survival', 'robustness' => ['temporal_stability'],
            'regime_coverage', 'rolling_regime' => ['regime_coverage'],
            'drawdown', 'max_drawdown' => ['drawdown'],
            'risk' => ['risk_of_ruin'],
            default => [],
        };
        $definitions = [
            'edge_quality' => ['direction' => 'higher', 'tolerance' => .02, 'required' => true],
            'stress_cost' => ['direction' => 'higher', 'tolerance' => .02, 'required' => false],
            'cost_efficiency' => ['direction' => 'lower', 'tolerance' => 1.0, 'required' => true],
            'drawdown' => ['direction' => 'lower', 'tolerance' => 1.0, 'required' => true],
            'risk_of_ruin' => ['direction' => 'lower', 'tolerance' => 1.0, 'required' => true],
            'temporal_stability' => ['direction' => 'higher', 'tolerance' => .02, 'required' => true],
            'regime_coverage' => ['direction' => 'higher', 'tolerance' => .02, 'required' => true],
            'volatility_stability' => ['direction' => 'higher', 'tolerance' => .02, 'required' => true],
            'session_stability' => ['direction' => 'higher', 'tolerance' => .02, 'required' => true],
            'calibration' => ['direction' => 'higher', 'tolerance' => .03, 'required' => true],
            'abstention_quality' => ['direction' => 'higher', 'tolerance' => .03, 'required' => true],
        ];
        $comparisons = [];
        $missingRequired = [];
        $regressed = [];
        foreach ($definitions as $metric => $definition) {
            if (in_array($metric, $excluded, true)) {
                continue;
            }
            $candidateValue = $candidateVector[$metric] ?? null;
            $baselineValue = $baselineVector[$metric] ?? null;
            if (! is_numeric($candidateValue) || ! is_numeric($baselineValue)) {
                if ($definition['required']) {
                    $missingRequired[] = $metric;
                }

                continue;
            }
            $delta = (float) $candidateValue - (float) $baselineValue;
            $failed = $definition['direction'] === 'lower'
                ? $delta > (float) $definition['tolerance']
                : $delta < -(float) $definition['tolerance'];
            if ($failed) {
                $regressed[] = $metric;
            }
            $comparisons[$metric] = [
                'candidate' => (float) $candidateValue,
                'baseline' => (float) $baselineValue,
                'delta' => round($delta, 8),
                'direction' => $definition['direction'],
                'tolerance' => $definition['tolerance'],
                'passed' => ! $failed,
            ];
        }
        $status = $missingRequired !== []
            ? 'incomplete'
            : ($regressed !== [] ? 'failed' : 'passed');

        return [
            'protocol' => 'causal_non_target_invariants_v1',
            'status' => $status,
            'safe' => $status === 'passed',
            'target' => strtolower(trim($target)),
            'excluded_target_metrics' => $excluded,
            'comparisons' => $comparisons,
            'missing_required_metrics' => $missingRequired,
            'regressed_metrics' => $regressed,
            'source' => 'paired_full_replay_invariant_vectors',
            'promotion_evidence' => false,
        ];
    }

    /** @param list<string> $paths */
    private function firstNumber(array $values, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = data_get($values, $path);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /** @return array{metric:string,direction:string,value:?float,source:?string} */
    private function metricMeasurement(
        array $result,
        string $metric,
        string $direction,
        array $paths,
        bool $normalizePercent = false,
    ): array {
        $observed = $this->firstNumberWithPath($result, $paths);
        $value = $observed['value'] ?? null;
        if ($normalizePercent && $value !== null && $value > 1) {
            $value /= 100;
        }

        return [
            'metric' => $metric,
            'direction' => $direction,
            'value' => $value,
            'source' => $observed['path'] ?? null,
        ];
    }

    /** @param array{metric:string,direction:string,value:?float,source:?string} $measurement */
    private function singleTargetMeasurement(string $target, array $measurement): array
    {
        return [
            'protocol' => 'declared_target_measurement_v2',
            'target' => $target,
            ...$measurement,
            'status' => is_numeric($measurement['value'] ?? null) ? 'observed' : 'missing',
            'promotion_evidence' => false,
        ];
    }

    /** @param array<string,array{metric:string,direction:string,value:?float,source:?string}> $components */
    private function compositeTargetMeasurement(string $target, array $components): array
    {
        $missing = collect($components)
            ->filter(fn (array $component): bool => ! is_numeric($component['value'] ?? null))
            ->keys()->values()->all();

        return [
            'protocol' => 'declared_composite_target_measurement_v1',
            'target' => $target,
            'components' => $components,
            'missing_components' => $missing,
            'status' => $missing === [] ? 'observed' : 'missing',
            'promotion_evidence' => false,
        ];
    }

    /** @return array{value:float,path:string}|null */
    private function firstNumberWithPath(array $values, array $paths): ?array
    {
        foreach ($paths as $path) {
            $value = data_get($values, $path);
            if (is_numeric($value)) {
                return ['value' => (float) $value, 'path' => (string) $path];
            }
        }

        return null;
    }

    private function minimumGroupedNumber(
        array $result,
        string $groupPath,
        string $metric = 'net_pf',
        int $minimumTrades = 8,
    ): ?float {
        $values = collect((array) data_get($result, $groupPath, []))
            ->filter(fn ($row): bool => is_array($row)
                && (int) data_get($row, 'trades', 0) >= $minimumTrades
                && is_numeric(data_get($row, $metric)))
            ->map(fn (array $row): float => (float) data_get($row, $metric));

        return $values->isEmpty() ? null : (float) $values->min();
    }

    private function windowScore(array $window): ?float
    {
        if (is_numeric(data_get($window, 'score'))) {
            return (float) data_get($window, 'score');
        }
        if (is_numeric(data_get($window, 'net_profit_percent'))) {
            return (float) data_get($window, 'net_profit_percent');
        }
        if (is_numeric(data_get($window, 'profit_factor'))) {
            return (float) data_get($window, 'profit_factor') - 1.0;
        }

        return null;
    }

    private function nonTargetSafe(?LabLearningLanePair $pair): bool
    {
        if (! $pair) {
            return false;
        }
        $evidence = (array) $pair->non_target_regression;
        $status = (string) data_get($evidence, 'status', 'not_recorded');

        return data_get($evidence, 'safe') === true
            && in_array($status, ['passed', 'confirmed'], true);
    }

    private function guidedRole(AgentLearningCausalExperiment $experiment): string
    {
        return in_array((string) data_get($experiment->evidence, 'experiment_kind'), [
            'causal_repair', 'causal_architecture_escape', 'causal_architecture_interaction',
        ], true)
            ? 'repair_guided'
            : 'memory_guided';
    }

    private function confirmationProtocol(AgentLearningCausalExperiment $experiment): string
    {
        return match ((string) data_get($experiment->evidence, 'experiment_kind')) {
            'causal_architecture_interaction' => 'architecture_interaction_guided_vs_blinded_vs_frozen_control_v1',
            'causal_architecture_escape' => 'architecture_guided_vs_blinded_vs_frozen_control_v1',
            'causal_repair' => 'repair_guided_vs_blinded_vs_frozen_control_v1',
            default => 'memory_guided_vs_blinded_vs_frozen_control_v1',
        };
    }

    private function utility(string $target, array $delta): float
    {
        $value = (float) data_get($delta, 'delta', 0);

        return in_array(strtolower($target), ['drawdown', 'drawdown_risk', 'max_drawdown', 'risk'], true)
            ? -$value
            : $value;
    }
}
