<?php

namespace App\Services;

use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use App\Models\PaperConfidenceCalibration;

class CandidateGateDecisionService
{
    public function __construct(
        private PaperEvidenceReadinessService $paperEvidence,
        private ExecutionContractService $executionContracts,
        private GateMarginService $gateMargins,
        private MutationObservabilityService $mutationObservability,
        private CausalFunnelAttributionService $causalFunnel,
        private EvolutionArchiveService $evolutionArchive,
    ) {}

    public function recordScreening(LabAgent $agent, array $result): CandidateGateDecision
    {
        $survival = (array) data_get($result, 'screening_survival', []);
        if (data_get($survival, 'status') === 'insufficient_evidence') {
            return $this->store(null, $agent, 'screening', 'insufficient_evidence', ['INSUFFICIENT_SCREENING_EVIDENCE'], $result);
        }
        $reasons = $this->economicReasons($result, 10, 1.0, 100.0, 100.0, 0);
        // A high global PF from one short slice is not a survivor claim. The
        // incremental replay attaches frozen cost/window/perturbation checks;
        // failures enter rescue, never the scarce promotion lane.
        if ($survival !== [] && data_get($survival, 'status') !== 'survivor') {
            $reasons = [...$reasons, ...(array) data_get($survival, 'reason_codes', ['FAILED_SCREENING_SURVIVAL'])];
        }
        if (data_get($result, 'differential_no_regression.status') !== null
            && data_get($result, 'differential_no_regression.status') !== 'passed') {
            $reasons[] = 'FAILED_NON_TARGET_REGRESSION';
        }
        $reasons = [...$reasons, ...$this->causalCooldownRescueReasons($agent, $result)];
        $reasons = array_values(array_unique($reasons));
        $woundSet = app(FailureWoundSetService::class)->evaluateForScreening($agent, $result);
        if (($woundSet['blocking_failure_count'] ?? 0) > 0) {
            $reasons[] = 'FAILED_WOUND_SET_REGRESSION';
            foreach ((array) data_get($woundSet, 'blocking_failures', []) as $failure) {
                $reasons[] = match ((string) data_get($failure, 'target_key')) {
                    'temporal_chunk' => 'FAILED_WOUND_TEMPORAL_CHUNK',
                    'calendar_month' => 'FAILED_WOUND_CALENDAR_MONTH',
                    'train_forward_gap' => 'FAILED_WOUND_TRAIN_FORWARD_GAP',
                    'cost_exit_stress' => 'FAILED_WOUND_COST_EXIT_STRESS',
                    default => 'FAILED_WOUND_SET_REGRESSION',
                };
            }
        }
        $reasons = array_values(array_unique($reasons));
        // A parameter change is not learning evidence by itself. Compare the
        // child with its immutable anchor/parent before storing the gate row;
        // a non-observable child stays failed and is routed away from reuse,
        // while the economic thresholds themselves remain unchanged.
        $observability = $this->mutationObservability->assess($agent, [...$result, 'reason_codes' => $reasons]);
        if (data_get($observability, 'classification') === 'mutation_no_observable_effect') {
            $reasons[] = 'MUTATION_NON_OBSERVABLE';
        }
        // Shadow mutation contracts are admitted for research only when the
        // executable decision/event/trade plane actually moved against a
        // same-generation frozen control or immutable anchor. A changed
        // parameter, a new hash, or an absent control is never enough.
        $mutationContract = (array) data_get($observability, 'mutation_contract', []);
        if ((bool) data_get($mutationContract, 'required', false)
            && data_get($mutationContract, 'status') !== 'passed') {
            $reasons[] = data_get($mutationContract, 'status') === 'failed_evidence_incomplete'
                ? 'FAILED_BEHAVIORAL_MUTATION_EVIDENCE'
                : 'FAILED_BEHAVIORAL_MUTATION_CONTRACT';
        }
        $reasons = array_values(array_unique($reasons));
        // Gate margins are a research-ranking signal. They are attached to
        // the same immutable evidence payload as the binary decision, but do
        // not alter the strict reason list or open a later gate.
        $result = [
            ...$result,
            'gate_margin' => $this->gateMargins->screening($result, $reasons),
            'mutation_observability' => $observability,
            'wound_set' => $woundSet,
            // Causal attribution makes the following generation choose the
            // narrowest falsifiable lane; it cannot loosen this decision.
            'causal_funnel_attribution' => $this->causalFunnel->assess([...$result, 'reason_codes' => $reasons]),
        ];
        $this->mutationObservability->record($agent, $observability);
        $result['behavioral_map_elites'] = $this->evolutionArchive->recordScreeningBehavior($agent, $result);
        $decision = $reasons === [] ? 'passed' : 'failed';
        if ($decision === 'failed') {
            $result['wound_set']['sealed'] = app(FailureWoundSetService::class)
                ->sealFromScreening($agent, $result, $reasons);
        }
        $decisionRow = $this->store(null, $agent, 'screening', $decision, $reasons, $result);

        // A complete strategy failure becomes an immutable repair anchor. It
        // is deliberately written after the gate projection and never turns
        // the failed model into a genetic parent. Incomplete/technical
        // evidence is rejected by the anchor service itself.
        $anchors = app(FailureRepairAnchorService::class);
        if ((int) data_get($agent->modelVersion?->metadata, 'repair_anchor.id', 0) > 0) {
            // Repair children need a paired-screen observation on both pass
            // and fail; a pass is what qualifies them for the next gate.
            $anchors->recordRepairScreeningOutcome($agent, $result);
            // A bounded sibling failure remains an observation against the
            // original immutable anchor. Do not fork a new anchor from every
            // failed sibling: that would reset the cohort budget, destroy the
            // three-clean-attempt escape rule and turn local repair into an
            // unbounded cold restart. The existing anchor service records the
            // sibling/cohort outcome above; controls and architecture escapes
            // are likewise kept research-only.
        } elseif ($decision === 'failed') {
            $anchors->recordFromScreeningDecision($agent, $decisionRow, $result);
        }

        // Screening failures do not disappear into a generic rejection. They
        // explicitly enter the directed-mutation queue for the next replay.
        $funnel = (array) data_get($result, 'entry_funnel', []);
        $hasDiagnosticSignal = (int) data_get($funnel, 'raw_strategy_signals', 0) > 0
            || (int) data_get($funnel, 'flat_signal_opportunities', 0) > 0;
        $generationRescues = CandidateGateDecision::where('stage', 'diagnostic_rescue_replay')
            ->whereHas('labAgent', fn ($query) => $query->where('lab_generation_id', $agent->lab_generation_id))->count();
        $familyRescues = CandidateGateDecision::where('stage', 'diagnostic_rescue_replay')
            ->whereHas('labAgent', fn ($query) => $query->where('lab_generation_id', $agent->lab_generation_id)->where('strategy_family', $agent->strategy_family))->count();
        // Maximum 4/20 population (20%) and two per family. This preserves a
        // diagnostic lane without allowing it to compete with promotion work.
        if ($decision === 'failed' && $hasDiagnosticSignal && $generationRescues < 4 && $familyRescues < 2) {
            $this->store(null, $agent, 'diagnostic_rescue_replay', 'waiting', [...$reasons, 'WAITING_FOR_EVIDENCE'], [
                'recommended_mutation_target' => data_get($agent->modelVersion?->metadata, 'generation_target'),
                'screening_metrics' => $result,
                'diagnostic_telemetry' => data_get($result, 'diagnostic_telemetry', []),
                'promotion_evidence' => false,
            ]);
        }
        return $decisionRow;
    }

    public function recordForward(ModelMarketPerformance $performance, array $result): CandidateGateDecision
    {
        // A forward decision without its laboratory identity is not auditable
        // evidence. Resolve only the deterministic same-version/symbol/scope
        // relationship; an absent relation stays explicitly unattributed.
        $agent = LabAgent::query()->where('model_version_id', $performance->model_version_id)
            ->where('symbol', $performance->symbol)->where('timeframe', $performance->timeframe)
            ->latest('id')->first();
        $reasons = $this->economicReasons($result, 30, 1.3, 15.0, 10.0, 3);
        if (data_get($agent?->modelVersion?->metadata, 'learning_lane.protocol') === LearningLaneService::PROTOCOL) {
            // This gate row is retained for auditability, but a research-only
            // learning replay can never be a forward/paper candidate.
            $reasons[] = 'LEARNING_LANE_RESEARCH_ONLY';
        }
        $hybridLane = (string) data_get($agent?->modelVersion?->metadata, 'hybrid_evolution.lane', '');
        if (in_array($hybridLane, ['bold_structural', 'adversarial_escape'], true)
            && ! $this->hybridIndependentConfirmationPassed($result)) {
            $reasons[] = 'HYBRID_RESEARCH_ONLY_UNTIL_INDEPENDENT_CONFIRMATION';
        }
        $reasons = [...$reasons, ...$this->freshReplayGateReasons($agent, $result, $performance->symbol, $performance->timeframe)];
        if ((bool) data_get($agent?->modelVersion?->metadata, 'repair_anchor.control_only', false)
            || in_array((string) data_get($agent?->modelVersion?->metadata, 'repair_anchor.sibling_kind', ''), ['frozen_control', 'architecture_escape'], true)
            || in_array((string) data_get($agent?->modelVersion?->metadata, 'repair_anchor_sibling.kind', ''), ['frozen_control', 'architecture_escape'], true)) {
            $reasons[] = 'CONTROL_ONLY_RESEARCH_MEMBER';
        }
        $parentBenefit = $this->parentBenefitContract($agent, $result);
        $reasons = [...$reasons, ...(array) data_get($parentBenefit, 'reason_codes', [])];
        // Drift expiry is a safety stop, not a promotion shortcut. A stale
        // skill must re-certify on fresh state evidence before it can enter
        // forward/paper, even if the economic replay still looks strong.
        $knowledgeCard = $agent?->knowledgeCard;
        if ($knowledgeCard && ! app(AgentProfessionalExamService::class)->skillUsable($knowledgeCard)) {
            $reasons[] = 'SKILL_RECERTIFICATION_REQUIRED_AFTER_DRIFT';
        }
        $robustnessGate = (int) data_get($agent?->modelVersion?->metadata, 'robustness_gate_version', 0) >= 1;
        if ($robustnessGate) {
            if (data_get($result, 'noise_sanity.status') !== 'assessed' || ! (bool) data_get($result, 'noise_sanity.pass', false)) {
                $reasons[] = data_get($result, 'noise_sanity.status') === 'assessed' ? 'FAILED_NOISE_SANITY' : 'FAILED_NOISE_SANITY_EVIDENCE';
            }
            if (data_get($result, 'execution_digital_twin.status') !== 'assessed' || ! (bool) data_get($result, 'execution_digital_twin.pass', false)) {
                $reasons[] = data_get($result, 'execution_digital_twin.status') === 'assessed' ? 'FAILED_EXECUTION_STRESS_GATE' : 'FAILED_EXECUTION_STRESS_EVIDENCE';
            }
            if (data_get($result, 'parameter_plateau.status') !== 'assessed'
                || ! (bool) data_get($result, 'parameter_plateau.pass', false)) {
                $reasons[] = data_get($result, 'parameter_plateau.status') === 'assessed'
                    ? 'FAILED_PARAMETER_PLATEAU' : 'FAILED_PARAMETER_PLATEAU_EVIDENCE';
            }
            $quality = (array) data_get($result, 'data_quality', []);
            if (data_get($quality, 'status') !== 'passed'
                || (int) data_get($quality, 'duplicate_timestamp_count', 0) > 0
                || (int) data_get($quality, 'non_monotonic_timestamp_pairs', 0) > 0
                || (int) data_get($quality, 'invalid_ohlc_rows', 0) > 0) {
                $reasons[] = 'FAILED_DATA_QUALITY';
            }
            $challenger = (array) data_get($result, 'challenger_protocol', []);
            if ((int) data_get($challenger, 'observed_forward_windows', 0) < 3
                || (int) data_get($challenger, 'positive_forward_windows', 0) < 3) {
                $reasons[] = 'FAILED_INDEPENDENT_FORWARD_WINDOWS';
            }
            $windowProtocol = (array) data_get($result, 'forward_window_protocol', []);
            if (data_get($windowProtocol, 'independence_verified') !== true
                || (bool) data_get($windowProtocol, 'overlap_detected', true)) {
                $reasons[] = data_get($windowProtocol, 'source') === 'not_available'
                    ? 'FAILED_INDEPENDENT_FORWARD_WINDOW_EVIDENCE'
                    : 'FAILED_OVERLAPPING_FORWARD_WINDOWS';
            }
            $repairLineage = (array) data_get($agent?->modelVersion?->metadata, 'repair_lineage', []);
            if ((int) data_get($repairLineage, 'attempt', 0) > 0) {
                if (count((array) ($agent?->parameter_diff ?? [])) !== 1) {
                    $reasons[] = 'FAILED_SINGLE_GENE_CONTRACT';
                }
                $repairAnchorId = (int) data_get($agent?->modelVersion?->metadata, 'repair_anchor.id', 0);
                if ($repairAnchorId > 0) {
                    if (data_get($result, 'repair_anchor_verification.status') !== 'confirmed') {
                        $reasons[] = 'FAILED_REPAIR_ANCHOR_CONFIRMATION';
                    }
                } elseif (data_get($result, 'paired_replay.status') !== 'confirmed') {
                    $reasons[] = 'FAILED_PAIRED_REPLAY_EVIDENCE';
                }
                if (data_get($result, 'no_regression_contract.status') !== 'passed') {
                    $reasons[] = 'FAILED_NON_TARGET_REGRESSION';
                }
            }
        }
        if ((bool) data_get($result, 'is_overfit', false)) $reasons[] = 'FAILED_OVERFIT';
        if (data_get($result, 'pf_attribution.method') === 'identical_replay_execution_profiles'
            && (float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 0) < 1.05) $reasons[] = 'FAILED_STRESS_COST';
        $edge = data_get($result, 'statistical_evidence.edge_quality', []);
        if (data_get($edge, 'worst_regime_sampled', false) && (float) data_get($edge, 'worst_regime_pf', 0) < 1.0) $reasons[] = 'FAILED_REGIME_COVERAGE';
        $survival = data_get($result, 'window_survival', []);
        if ((int) data_get($survival, 'positive_windows', 0) > 0
            && ((int) data_get($survival, 'positive_windows', 0) < 3 || (int) data_get($survival, 'catastrophic_windows', 0) > 0)) $reasons[] = 'FAILED_CALENDAR_MONTH_SURVIVAL';
        // A failed chronological month is a monthly-survival defect, not a
        // regime-PF defect. Keep the reason specific so historical learning
        // routes the next mutation to the monthly lane.
        if (data_get($result, 'monthly_passport.status') === 'seasonal_or_luck') $reasons[] = 'FAILED_CALENDAR_MONTH_SURVIVAL';
        if (data_get($result, 'selection_validation.status') === 'assessed'
            && (float) data_get($result, 'selection_validation.probability_of_backtest_overfitting', 1) > .5) $reasons[] = 'FAILED_OVERFIT';
        if (data_get($result, 'statistical_evidence.deflated_sharpe.status') === 'assessed'
            && (float) data_get($result, 'statistical_evidence.deflated_sharpe.deflated_sharpe_probability', 0) < .95) $reasons[] = 'FAILED_OVERFIT';
        if (data_get($result, 'elite_agent_passport.status') !== 'passed') {
            $reasons[] = 'FAILED_ELITE_PASSPORT';
            $reasons = [...$reasons, ...(array) data_get($result, 'elite_agent_passport.reason_codes', [])];
        }
        if (data_get($result, 'proof_carrying_replay.status') === 'mismatch') {
            $reasons[] = 'QUARANTINED_PROOF_REPLAY_MISMATCH';
        }
        $result = [
            ...$result,
            'gate_margin' => $this->gateMargins->forward($result, $reasons),
        ];
        $identity = [
            'lab_agent_id' => $agent?->id, 'generation_id' => $agent?->lab_generation_id,
            'model_market_performance_id' => $performance->id, 'model_version_id' => $performance->model_version_id,
            'symbol' => $performance->symbol, 'timeframe' => $performance->timeframe,
            'data_manifest_hash' => data_get($result, 'data_manifest.sha256'),
            'execution_hash' => data_get($result, 'execution_contract.execution_hash', data_get($result, 'execution_hash')),
            'code_version' => data_get($result, 'execution_contract.code_version', data_get($result, 'code_version')),
            'result_hash' => hash('sha256', json_encode($result, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
            'attribution_status' => $agent ? 'deterministic' : 'ATTRIBUTION_MISSING',
        ];
        if (! $agent) {
            $reasons[] = 'ATTRIBUTION_MISSING';
        }
        $decision = $this->store($performance, $agent, 'statistical_forward_gate', $reasons === [] ? 'passed' : 'failed', array_values(array_unique($reasons)), [
            ...$result,
            'parent_benefit_contract' => $parentBenefit,
            'forward_identity' => $identity,
        ]);
        if (in_array('QUARANTINED_PROOF_REPLAY_MISMATCH', $reasons, true)) {
            $decision->update(['quarantined_at' => now(), 'quarantine_reason' => 'primary_and_independent_ledger_verifier_disagree']);
            $performance->update(['status' => 'rejected', 'paper_status' => 'failed']);
        }
        return $decision;
    }

    /**
     * Forward promotion is only valid when the result carries the identity of
     * the sealed full replay that produced it. A score copied from an old
     * projection, a screening payload, or a hand-built test payload must stay
     * outside the promotion lane.
     */
    public function freshReplayGateReasons(?LabAgent $agent, array $result, ?string $symbol = null, ?string $timeframe = null): array
    {
        $reasons = [];
        if (! filled(data_get($result, 'evidence_run_id'))) {
            $reasons[] = 'FRESH_REPLAY_EVIDENCE_MISSING';
        }

        $manifest = (array) data_get($result, 'data_manifest', []);
        $manifestHash = (string) data_get($manifest, 'snapshot_sha256', data_get($manifest, 'sha256', ''));
        if ($manifestHash === '') {
            $reasons[] = 'FRESH_REPLAY_DATA_MANIFEST_MISSING';
        }

        if (data_get($result, 'full_replay_runtime_policy.protocol') !== 'full_replay_runtime_budget_v1') {
            $reasons[] = 'FRESH_REPLAY_RUNTIME_POLICY_MISSING';
        }

        $replaySymbol = $symbol ?: $agent?->symbol;
        $replayTimeframe = $timeframe ?: $agent?->timeframe;
        $contract = (array) data_get($result, 'execution_contract', []);
        if ($replaySymbol === null || $replayTimeframe === null
            || ! $this->executionContracts->matches($contract, $replaySymbol, $replayTimeframe)) {
            $reasons[] = 'FRESH_REPLAY_EXECUTION_CONTRACT_MISSING_OR_MISMATCH';
        }

        if ($agent && $manifestHash !== '') {
            $generation = $agent->generation;
            $expectedHash = (string) data_get(
                $generation?->trigger_context,
                'canonical_dataset_snapshots.price.sha256',
                data_get($generation?->trigger_context, 'canonical_dataset_snapshots.volume.sha256', ''),
            );
            if ($expectedHash !== '' && ! hash_equals($expectedHash, $manifestHash)) {
                $reasons[] = 'FRESH_REPLAY_DATASET_SNAPSHOT_MISMATCH';
            }
            $manifestGeneration = data_get($manifest, 'snapshot_generation_id');
            if ($manifestGeneration !== null && (int) $manifestGeneration !== (int) $agent->lab_generation_id) {
                $reasons[] = 'FRESH_REPLAY_GENERATION_SNAPSHOT_MISMATCH';
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * An attached parent is a genetic claim, not merely a ranking baseline.
     * It can open the next gate only after the child beats the parent in the
     * same replay and preserves every gate the parent had already passed.
     */
    public function parentBenefitGateReasons(?LabAgent $agent, array $result): array
    {
        return (array) data_get($this->parentBenefitContract($agent, $result), 'reason_codes', []);
    }

    public function parentBenefitContract(?LabAgent $agent, array $result): array
    {
        $parentIds = array_values(array_filter([
            (int) ($agent?->parent_a_model_version_id ?: 0),
            (int) ($agent?->parent_b_model_version_id ?: 0),
        ]));
        if ($agent === null || $parentIds === []) {
            return [
                'protocol' => 'parent_benefit_contract_v1',
                'status' => 'no_parent',
                'parent_model_version_ids' => [],
                'reason_codes' => [],
                'promotion_evidence' => false,
                'rule' => 'No parent is available; this root seed must prove itself through fresh replay gates.',
            ];
        }

        $reasons = [];
        if (data_get($result, 'paired_replay.status') !== 'confirmed') {
            $reasons[] = 'PARENT_BENEFIT_PAIRED_REPLAY_NOT_CONFIRMED';
        }
        if (data_get($result, 'no_regression_contract.status') !== 'passed') {
            $reasons[] = 'PARENT_BENEFIT_NO_REGRESSION_NOT_PASSED';
        }
        $broker = (array) data_get($agent?->modelVersion?->metadata, 'parent_mentor_broker', []);
        $mentorLane = in_array((string) data_get($broker, 'lane', ''), ['mentor_assisted', 'cross_skill_composition'], true);
        if ($mentorLane) {
            $counterfactualStatus = (string) data_get(
                $agent?->modelVersion?->metadata,
                'parent_counterfactual.status',
                data_get($result, 'parent_aware_credit.counterfactual.status', 'awaiting_branches'),
            );
            if (! in_array($counterfactualStatus, ['parent_helpful', 'child_independent'], true)) {
                $reasons[] = 'PARENT_MENTOR_COUNTERFACTUAL_NOT_CONFIRMED';
            }
        }

        return [
            'protocol' => 'parent_benefit_contract_v1',
            'status' => $reasons === [] ? 'confirmed' : 'not_confirmed',
            'parent_model_version_ids' => $parentIds,
            'paired_replay_status' => data_get($result, 'paired_replay.status', 'missing'),
            'no_regression_status' => data_get($result, 'no_regression_contract.status', 'missing'),
            'mentor_lane' => $mentorLane,
            'parent_counterfactual_status' => $mentorLane
                ? data_get($agent?->modelVersion?->metadata, 'parent_counterfactual.status', data_get($result, 'parent_aware_credit.counterfactual.status', 'missing'))
                : null,
            'reason_codes' => $reasons,
            'promotion_evidence' => $reasons === [],
            'rule' => 'A genetic child must beat its attached parent in the same replay and preserve every gate the parent had already passed.',
        ];
    }

    /**
     * Record the forward handoff for a sealed multi-agent portfolio.
     *
     * A portfolio has no LabAgent owner of its own, so the ordinary
     * recordForward() path would correctly mark it as unattributed.  That is
     * the wrong result for a combined replay: the portfolio itself is the
     * immutable owner, provided its membership/passport identity is present.
     * This keeps the proxy on the same statistical_forward_gate ledger that
     * paper admission already consumes, while making the attribution explicit.
     */
    public function recordPortfolioForward(
        ModelMarketPerformance $performance,
        array $result,
        array $passport,
    ): CandidateGateDecision {
        $reasons = [];
        if ($performance->evidence_status !== 'valid'
            || $performance->modelVersion?->evidence_status !== 'valid') {
            $reasons[] = 'INVALID_PORTFOLIO_EVIDENCE_IDENTITY';
        }
        if (data_get($passport, 'protocol') !== 'portfolio_elite_passport_v1') {
            $reasons[] = 'FAILED_PORTFOLIO_PASSPORT_PROTOCOL';
        }
        if (data_get($passport, 'status') !== 'passed') {
            $reasons[] = 'FAILED_PORTFOLIO_PASSPORT';
            $reasons = [...$reasons, ...(array) data_get($passport, 'reason_codes', [])];
        }
        foreach (['portfolio_id', 'membership_hash', 'parameter_hash', 'final_exam_result_hash'] as $field) {
            if (! filled(data_get($passport, $field))) {
                $reasons[] = 'PORTFOLIO_FORWARD_IDENTITY_MISSING';
                break;
            }
        }

        $identity = [
            'portfolio_id' => data_get($passport, 'portfolio_id'),
            'membership_hash' => data_get($passport, 'membership_hash'),
            'parameter_hash' => data_get($passport, 'parameter_hash'),
            'final_exam_result_hash' => data_get($passport, 'final_exam_result_hash'),
            'model_market_performance_id' => $performance->id,
            'model_version_id' => $performance->model_version_id,
            'symbol' => $performance->symbol,
            'timeframe' => $performance->timeframe,
            'attribution_status' => 'portfolio_sealed',
            'promotion_evidence' => true,
        ];

        return $this->store(
            $performance,
            null,
            'statistical_forward_gate',
            $reasons === [] ? 'passed' : 'failed',
            array_values(array_unique($reasons)),
            [
                ...$result,
                'elite_agent_passport' => $passport,
                'portfolio_forward_identity' => $identity,
                'promotion_evidence' => true,
            ],
            'portfolio_sealed',
        );
    }

    public function recordDiagnosticReplay(LabAgent $agent, array $result): CandidateGateDecision
    {
        $reasons = $this->economicReasons($result, 10, 1.0, 100.0, 100.0, 0);
        return $this->store(null, $agent, 'diagnostic_rescue_replay', 'failed', $reasons, [
            'diagnostic_telemetry' => data_get($result, 'diagnostic_telemetry', []),
            'entry_funnel' => data_get($result, 'entry_funnel', []),
            'gate_deficits' => app(ForwardGateProgressService::class)->deficits($result),
            'promotion_evidence' => false,
        ]);
    }

    /** Records why screening did or did not enter scarce full replay capacity. */
    public function recordFullReplaySelection(LabAgent $agent, bool $selected, ?string $reason = null): CandidateGateDecision
    {
        $screen = (array) data_get($agent->modelVersion?->metadata, 'last_screen_result', []);
        if ($selected) {
            $probe = in_array($reason, ['CAUSAL_PROBE_ONLY', 'CAUSAL_PROBE_ALTERNATIVE'], true);
            $portfolio = $reason === 'PORTFOLIO_MEMBER_REPLAY';
            $targetedResearch = $reason === 'TARGETED_RESEARCH_ONLY';
            return $this->store(null, $agent, 'full_replay_selection', 'waiting', [$probe ? $reason : 'FULL_REPLAY_ELIGIBLE', 'WAITING_FOR_FULL_REPLAY'], [
                'screening_metrics' => $screen, 'promotion_evidence' => false,
                'replay_purpose' => $portfolio
                    ? 'portfolio_member_validation'
                    : ($targetedResearch ? 'g98_targeted_research_validation'
                    : ($probe ? ($reason === 'CAUSAL_PROBE_ALTERNATIVE' ? 'causal_probe_control' : 'causal_probe') : 'candidate_validation')),
                'rule' => $portfolio
                    ? 'Portfolio-member replay may create combined evidence; the member can never bypass its standalone forward gate.'
                    : ($targetedResearch ? 'Targeted G98 replay may create learning evidence for its declared lane but can never bypass promotion gates.'
                    : ($probe ? 'Probe full replay may create learning evidence but can never bypass promotion gates.' : 'Screening-to-full replay selection.')),
            ]);
        }
        $reason ??= (int) $agent->sample_count < 10 ? 'FAILED_LOW_SCREEN_TRADES'
            : ((float) $agent->forward_score <= 0 ? 'FAILED_NON_POSITIVE_SCORE'
            : ((int) data_get($screen, 'entry_funnel.flat_signal_opportunities', 0) === 0 ? 'FAILED_NO_OPPORTUNITY' : 'DOMINATED_BY_OTHER_AGENT'));
        return $this->store(null, $agent, 'full_replay_selection', 'failed', [$reason], [
            'screening_metrics' => $screen, 'promotion_evidence' => false,
        ]);
    }

    public function recordPaper(ModelMarketPerformance $performance, array $metrics): CandidateGateDecision
    {
        $minimum = max(50, (int) config('services.promotion.paper_min_samples', 50));
        $reasons = [];
        $agent = LabAgent::query()->where('model_version_id', $performance->model_version_id)
            ->where('symbol', $performance->symbol)->where('timeframe', $performance->timeframe)
            ->latest('id')->first();
        $hybridLane = (string) data_get($agent?->modelVersion?->metadata, 'hybrid_evolution.lane', '');
        if (in_array($hybridLane, ['bold_structural', 'adversarial_escape'], true)
            && ! $this->hybridIndependentConfirmationPassed($metrics)) {
            $reasons[] = 'HYBRID_RESEARCH_ONLY_UNTIL_INDEPENDENT_CONFIRMATION';
        }
        if ((int) data_get($metrics, 'sample_count', 0) < $minimum) $reasons[] = 'WAITING_FOR_SAMPLE';
        if ((int) data_get($metrics, 'sample_count', 0) >= $minimum) {
            if ((float) data_get($metrics, 'profit_factor', 0) < 1.3) $reasons[] = 'FAILED_PROFIT_FACTOR';
            if ((float) data_get($metrics, 'max_drawdown', 100) > 15) $reasons[] = 'FAILED_DRAWDOWN';
        }
        $calibration = PaperConfidenceCalibration::query()->where('model_market_performance_id', $performance->id)->orderByDesc('sample_count')->first();
        if (! $calibration || $calibration->sample_count < (int) config('services.paper_calibration.minimum_samples', 20)) $reasons[] = 'FAILED_CALIBRATION';
        $readiness = $this->paperEvidence->inspect();
        if (! data_get($readiness, 'gates.feed_uptime', false)) $reasons[] = 'FAILED_FEED_UPTIME';
        $decision = in_array('WAITING_FOR_SAMPLE', $reasons, true) ? 'waiting' : ($reasons === [] ? 'passed' : 'failed');
        return $this->store($performance, null, 'paper_observation', $decision, $reasons, [...$metrics, 'global_paper_readiness' => $readiness]);
    }

    private function hybridIndependentConfirmationPassed(array $evidence): bool
    {
        return data_get($evidence, 'hybrid_evolution_confirmation.status') === 'confirmed'
            || data_get($evidence, 'forward_confirmation.status') === 'confirmed'
            || data_get($evidence, 'verified_mutation_skill.status') === 'confirmed'
            || (int) data_get($evidence, 'independent_confirmation_count', 0) >= 2;
    }

    /** Operational trace only: this never participates in promotion decisions. */
    public function recordPaperCapture(ModelMarketPerformance $performance, string $reason, array $metrics = []): CandidateGateDecision
    {
        return $this->store($performance, null, 'paper_signal_capture', 'waiting', [$reason, 'WAITING_FOR_SAMPLE'], [
            ...$metrics,
            'promotion_evidence' => false,
            'observability_only' => true,
        ]);
    }

    /** Automated forward→paper handoff; missing activity remains observable. */
    public function recordPaperAdmissionHandshake(ModelMarketPerformance $performance): CandidateGateDecision
    {
        $forward = CandidateGateDecision::query()->where('model_market_performance_id', $performance->id)
            ->where('stage', 'statistical_forward_gate')->latest('evaluated_at')->first();
        $passport = (array) data_get($forward?->metrics, 'elite_agent_passport', data_get($performance->metrics, 'elite_agent_passport', []));
        $firstSignal = \App\Models\PaperSignal::query()->where('model_market_performance_id', $performance->id)->oldest('created_at')->first();
        $reasons = [];
        if ($forward?->decision !== 'passed') $reasons[] = 'FORWARD_GATE_NOT_PASSED';
        if (data_get($passport, 'status') !== 'passed') $reasons[] = 'SEALED_PASSPORT_MISSING';
        if (! filled(data_get($passport, 'agent.parameter_hash')) || ! filled(data_get($passport, 'final_exam_result_hash'))) $reasons[] = 'IMMUTABLE_IDENTITY_MISSING';
        if (! $firstSignal && $performance->updated_at?->lte(now()->subDay())) $reasons[] = 'PAPER_ARMED_NO_SIGNAL_24H';
        return $this->store($performance, null, 'paper_admission_handshake', $reasons === [] ? 'armed' : 'waiting', $reasons ?: ['PAPER_REGISTERED_WAITING_FOR_FIRST_SIGNAL'], [
            'sealed_strategy_passport' => $passport,
            'immutable_config_hash' => data_get($passport, 'agent.parameter_hash'),
            'final_exam_result_hash' => data_get($passport, 'final_exam_result_hash'),
            'first_signal_health' => $firstSignal ? 'captured' : 'waiting',
            'signal_ledger_heartbeat_at' => $firstSignal?->created_at?->toIso8601String(),
            'outcome_reconciliation' => $firstSignal?->outcome ? 'started' : 'waiting',
            'rule' => 'Forward promotion is armed automatically; a missing signal after 24h is a diagnosable state, not a silent backend gap.',
        ]);
    }

    public function recordHoldout(ModelMarketPerformance $performance, array $holdout): CandidateGateDecision
    {
        $result = (array) data_get($holdout, 'result', []);
        $reasons = $this->economicReasons($result, 30, 1.3, 15.0, 10.0, 0);
        if ((float) data_get($holdout, 'score', 0) < 50) $reasons[] = 'FAILED_FORWARD_SCORE';
        return $this->store($performance, null, 'sealed_holdout', $reasons === [] ? 'passed' : 'failed', array_values(array_unique($reasons)), $holdout);
    }

    private function economicReasons(array $metrics, int $minimumTrades, float $minimumPf, float $maxDrawdown, float $maxRuin, int $minimumRollingWins): array
    {
        $reasons = [];
        if ((int) data_get($metrics, 'total_trades', data_get($metrics, 'sample_count', 0)) < $minimumTrades) $reasons[] = 'FAILED_TRADE_COUNT';
        if ((float) data_get($metrics, 'profit_factor', 0) < $minimumPf) $reasons[] = 'FAILED_PROFIT_FACTOR';
        if ((float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100)) > $maxDrawdown) $reasons[] = 'FAILED_DRAWDOWN';
        if ((float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 0) > $maxRuin) $reasons[] = 'FAILED_RUIN_RISK';
        // Forward rolling wins are the chronological monthly-survival
        // requirement. Do not mislabel it as regime coverage.
        if ($minimumRollingWins > 0 && (int) data_get($metrics, 'rolling_forward_wins', 0) < $minimumRollingWins) $reasons[] = 'FAILED_CALENDAR_MONTH_SURVIVAL';
        return $reasons;
    }

    /** A cooldown rescue is a falsifiable single-gene experiment, never a
     * route around the normal promotion gates. */
    private function causalCooldownRescueReasons(LabAgent $agent, array $result): array
    {
        $contract = (array) data_get($agent->modelVersion?->metadata, 'causal_rescue_contract', []);
        if (data_get($contract, 'kind') !== 'loss_cooldown_single_gene') return [];

        $reasons = [];
        $diff = (array) ($agent->parameter_diff ?? []);
        $expected = (int) data_get($contract, 'variant.loss_cooldown_candles');
        if (array_keys($diff) !== ['loss_cooldown_candles']
            || (int) data_get($diff, 'loss_cooldown_candles.old') !== 4
            || (int) data_get($diff, 'loss_cooldown_candles.new') !== $expected
            || ! in_array($expected, [2, 3], true)) $reasons[] = 'FAILED_RESCUE_SINGLE_GENE_CONTRACT';
        if ((int) data_get($result, 'total_trades', 0) < 10) $reasons[] = 'FAILED_RESCUE_TRADE_COUNT';
        if ((float) data_get($result, 'profit_factor', 0) < 1.30) $reasons[] = 'FAILED_RESCUE_PROFIT_FACTOR';
        if ((float) data_get($result, 'screening_survival.stress_cost_pf', data_get($result, 'pf_attribution.stress_cost.profit_factor', 0)) < 1.05) $reasons[] = 'FAILED_RESCUE_STRESS_COST';
        $worstRegime = data_get($result, 'screening_survival.worst_regime_pf');
        if ($worstRegime === null || (float) $worstRegime < 1.0) $reasons[] = 'FAILED_RESCUE_REGIME_COVERAGE';
        $worstTemporal = (float) data_get($result, 'screening_survival.worst_temporal_chunk_pf', data_get($result, 'screening_survival.worst_window_pf', 0));
        if ($worstTemporal < 1.0) $reasons[] = 'FAILED_RESCUE_TEMPORAL_SURVIVAL';
        if ((float) data_get($result, 'screening_survival.train_forward_gap', PHP_FLOAT_MAX) > 25.0) $reasons[] = 'FAILED_RESCUE_TEMPORAL_GAP';
        if ((float) data_get($result, 'screening_survival.parameter_perturbation_ratio', 0) < .80) $reasons[] = 'FAILED_RESCUE_PARAMETER_STABILITY';
        return $reasons;
    }

    private function store(
        ?ModelMarketPerformance $performance,
        ?LabAgent $agent,
        string $stage,
        string $decision,
        array $reasons,
        array $metrics,
        ?string $attributionOverride = null,
    ): CandidateGateDecision
    {
        $attribution = $attributionOverride ?? match (true) {
            $stage === 'statistical_forward_gate' && $agent !== null => 'deterministic',
            $stage === 'statistical_forward_gate' => 'ATTRIBUTION_MISSING',
            $agent !== null => 'agent_scoped',
            $performance !== null => 'performance_only',
            default => 'not_applicable',
        };

        // Gate rows are mutable selectors, not the canonical response plane.
        // Keep scalar/equity/gate summaries available to existing selectors,
        // while moving trace/ledger/trade arrays to immutable artifacts.
        $compactMetrics = app(LabImmutableEvidenceService::class)->projectionPayload($metrics);
        $decisionRow = CandidateGateDecision::updateOrCreate(
            ['model_market_performance_id' => $performance?->id, 'lab_agent_id' => $agent?->id, 'stage' => $stage],
            ['decision' => $decision, 'reason_codes' => array_values(array_unique($reasons)), 'metrics' => $compactMetrics,
                'attribution_status' => $attribution, 'evaluated_at' => now()],
        );

        // This row is a fast current-state projection. Every retry and
        // re-check is preserved separately in the immutable evidence plane.
        app(LabImmutableEvidenceService::class)->recordGateDecision($decisionRow, [
            'projection' => 'candidate_gate_decisions',
            'projection_write' => 'updateOrCreate',
            'reason_count' => count($reasons),
        ], data_get($metrics, 'evidence_run_id'));

        return $decisionRow;
    }
}
