<?php

namespace App\Services;

use App\Models\LabFailureDojoRun;

/**
 * Converts a failure curriculum item into a bounded research decision.
 *
 * This is a research planner, not a trading or promotion policy.  It gives
 * the evolution lane a strategic action space, a falsifiable experiment tree,
 * an adversarial review contract and an executable lesson shape.  No method
 * in this class can grant mutation credit, parent eligibility or promotion
 * evidence.
 */
class StrategicResearchDirectorService
{
    public const PROTOCOL = 'strategic_research_director_v1';
    public const PREDICTION_PROTOCOL = 'strategic_prediction_score_v1';
    public const COUNTERFACTUAL_PROTOCOL = 'causal_counterfactual_lab_v1';
    public const COUNCIL_PROTOCOL = 'adversarial_research_council_v1';
    public const WORLD_MODEL_PROTOCOL = 'regime_world_model_contract_v1';
    public const LESSON_PROTOCOL = 'executable_lesson_contract_v1';
    public const BELIEF_PROTOCOL = 'agent_belief_state_v1';
    public const CAUSAL_GRAPH_PROTOCOL = 'causal_decision_graph_v1';
    public const EXPERIMENT_CHAIN_PROTOCOL = 'non_myopic_experiment_chain_v1';
    public const RETIREMENT_PROTOCOL = 'hypothesis_retirement_v1';
    public const ROUTER_PROTOCOL = 'specialist_router_safety_v1';
    public const REQUIRED_WINDOWS = CausalSkillCompilerService::REQUIRED_WINDOWS;
    public const MINIMUM_POSITIVE_WINDOWS = CausalSkillCompilerService::MINIMUM_POSITIVE_WINDOWS;

    /** @return array<string, array<string, mixed>> */
    public function actionSpace(): array
    {
        return [
            'new_signal_construction' => [
                'axis' => 'signal_construction',
                'owner' => 'signal_specialist',
                'requires' => ['signal_ledger', 'decision_ledger', 'same_generation_control'],
            ],
            'regime_classifier' => [
                'axis' => 'regime_classification',
                'owner' => 'regime_specialist',
                'requires' => ['regime_ledger', 'transition_cells', 'same_generation_control'],
            ],
            'entry_topology' => [
                'axis' => 'entry_exit_state',
                'owner' => 'entry_specialist',
                'requires' => ['decision_ledger', 'trade_ledger', 'same_generation_control'],
            ],
            'exit_state_machine' => [
                'axis' => 'entry_exit_state',
                'owner' => 'exit_specialist',
                'requires' => ['trade_ledger', 'exit_ledger', 'same_generation_control'],
            ],
            'abstention_policy' => [
                'axis' => 'abstention',
                'owner' => 'risk_abstention_specialist',
                'requires' => ['abstention_ledger', 'avoided_loss', 'missed_opportunity'],
            ],
            'cost_aware_execution' => [
                'axis' => 'cost_aware_execution',
                'owner' => 'execution_specialist',
                'requires' => ['spread_ledger', 'slippage_ledger', 'same_execution_contract'],
            ],
            'data_acquisition' => [
                'axis' => 'evidence_acquisition',
                'owner' => 'evidence_specialist',
                'requires' => ['new_data_identity_hash', 'non_overlap_attestation'],
            ],
            'counterfactual_replay' => [
                'axis' => 'causal_intervention',
                'owner' => 'causal_specialist',
                'requires' => ['exact_control', 'five_counterfactual_branches'],
            ],
            'do_nothing_and_wait_for_evidence' => [
                'axis' => 'epistemic_abstention',
                'owner' => 'research_director',
                'requires' => ['fresh_evidence_admission'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function planFor(LabFailureDojoRun $run): array
    {
        $evidence = (array) $run->evidence;
        $failure = (array) $run->failure_signature;
        $causal = (array) data_get($evidence, 'causal_skill_compiler', []);
        $target = strtolower((string) ($run->target ?: data_get($causal, 'hypothesis.decision_surface', 'decision_surface')));
        $action = $this->selectAction($target, $failure, $causal);
        $priority = (array) data_get($evidence, 'information_gain_priority', []);
        $value = $this->experimentValue($failure, $causal, $priority, $action);
        $hypothesis = (array) data_get($causal, 'hypothesis', []);
        $prediction = (array) data_get($causal, 'prediction_contract', []);
        $counterfactual = $this->counterfactualLab((array) data_get($causal, 'counterfactual_replay', []));
        $belief = $this->beliefState($run, $causal, $counterfactual);
        $causalGraph = $this->causalDecisionGraph((string) data_get($causal, 'decision_stage', 'decision_surface'), $action);
        $epistemicAction = $this->epistemicAction($belief, $counterfactual, $action);
        $experimentChain = $this->nonMyopicExperimentChain($action, $causal, $value);
        $predictionMarket = $this->predictionMarket($prediction, $causal);
        $retirement = $this->retirementContract($run, $causal);
        $router = $this->routerSafetyContract($belief, $causal);

        return [
            'protocol' => self::PROTOCOL,
            'status' => 'research_only',
            'failure_run_id' => $run->id,
            'failure_signature' => data_get($failure, 'signature', data_get($failure, 'failure_type')),
            'decision_action' => $action,
            'next_action' => $epistemicAction,
            'decision_layer' => (string) data_get($causal, 'decision_stage', $this->actionSpace()[$action]['axis'] ?? 'decision_surface'),
            'belief_state' => $belief,
            'causal_decision_graph' => $causalGraph,
            'proposal' => [
                'failure_cause' => $this->failureCause($failure, $causal),
                'decision_layer' => (string) data_get($causal, 'decision_stage', 'decision_surface'),
                'falsifiable_hypothesis' => data_get($hypothesis, 'falsifiable_statement'),
                'expected_behavioral_delta' => data_get($hypothesis, 'expected_behavioral_delta', data_get($causal, 'behavioral_delta')),
                'exact_control' => data_get($causal, 'exact_control'),
                'disconfirming_result' => $this->disconfirmingResult($action),
                'next_experiment' => 'Run the selected branch only after fresh evidence, exact control and causal replay admission.',
            ],
            'experiment_value' => $value,
            'research_tree' => $this->researchTree($action, $causal, $value),
            'non_myopic_experiment_chain' => $experimentChain,
            'prediction_contract' => [
                ...$prediction,
                'protocol' => self::PREDICTION_PROTOCOL,
                'pre_replay_required' => true,
                'score_after_replay' => true,
                'promotion_evidence' => false,
            ],
            'prediction_market' => $predictionMarket,
            'counterfactual_lab' => $counterfactual,
            'adversarial_council' => $this->adversarialCouncil($action, $causal),
            'specialist_router' => $router,
            'regime_world_model' => $this->regimeWorldModel($failure, $causal),
            'hypothesis_retirement' => $retirement,
            'decision_actions' => [
                'TRADE', 'ABSTAIN', 'REQUEST_NEW_DATA', 'RUN_COUNTERFACTUAL',
                'REQUEST_CONTROL', 'SWITCH_SPECIALIST', 'WAIT_FOR_REGIME_CONFIRMATION',
                'RETIRE_HYPOTHESIS',
            ],
            'executable_lesson' => $this->executableLesson($run, $causal, $action),
            'strategic_credit' => $this->strategicCreditContract(),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function materialize(LabFailureDojoRun $run): array
    {
        $plan = $this->planFor($run);
        $run->update(['evidence' => [
            ...((array) $run->evidence),
            'strategic_research_director' => $plan,
            'promotion_evidence' => false,
        ]]);

        return $plan;
    }

    /** @return array<string, mixed> */
    public function scorePrediction(array $plan, array $observed): array
    {
        $expected = (array) data_get($plan, 'proposal.expected_behavioral_delta', []);
        $actual = (array) data_get($observed, 'mutation_observability.behavioral_delta', data_get($observed, 'behavioral_delta', $observed));
        $comparisons = [];
        foreach (['decision_ledger_changed', 'trade_ledger_changed', 'abstention_ledger_changed'] as $key) {
            if (! array_key_exists($key, $expected)) continue;
            $comparisons[$key] = (bool) $expected[$key] === (bool) data_get($actual, $key, false);
        }
        $matched = count(array_filter($comparisons));
        $accuracy = $comparisons === [] ? null : round($matched / count($comparisons), 4);

        return [
            'protocol' => self::PREDICTION_PROTOCOL,
            'status' => $comparisons === [] ? 'unscored' : 'scored',
            'component_matches' => $comparisons,
            'matched_components' => $matched,
            'component_count' => count($comparisons),
            'accuracy' => $accuracy,
            'cause_prediction' => [
                'declared_stage' => data_get($plan, 'decision_layer'),
                'observed_failure_stage' => data_get($observed, 'failure_stage', data_get($observed, 'decision_stage')),
                'matched' => data_get($observed, 'failure_stage', data_get($observed, 'decision_stage')) === data_get($plan, 'decision_layer'),
            ],
            'brier_score' => $this->brierScore($plan, $observed),
            'calibration_action' => 'Use prediction accuracy for research-budget allocation only; never for promotion bypass.',
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function beliefState(LabFailureDojoRun $run, array $causal, array $counterfactual): array
    {
        $control = (string) data_get($causal, 'exact_control.status', 'missing_or_unverified');
        $windows = (int) data_get($causal, 'independent_windows.observed_windows', 0);
        $behavior = (string) data_get($causal, 'behavioral_delta.status', 'not_observed');
        $repeat = max(0, (int) data_get($run->failure_signature, 'repeat_count', 0));
        $known = [];
        $unknown = [];
        $conflicts = [];

        if ($control === 'available') $known[] = 'same-generation control is available';
        else $unknown[] = 'causal control identity';
        if ($windows >= self::REQUIRED_WINDOWS) $known[] = 'three chronological windows observed';
        else $unknown[] = 'independent chronological window coverage';
        if ($behavior === 'observed') $known[] = 'behavioral delta was observed';
        else $unknown[] = 'mutation changed the decision surface';
        if ((string) data_get($counterfactual, 'status') === 'assessed') $known[] = 'counterfactual branches assessed';
        else $unknown[] = 'counterfactual treatment effect';
        if ($repeat >= 2) $conflicts[] = 'repeated failure may be a structural family problem, not a scalar parameter problem';

        $confidence = $this->bounded(
            ($control === 'available' ? .30 : 0)
            + ($windows >= self::REQUIRED_WINDOWS ? .25 : $windows / max(1, self::REQUIRED_WINDOWS) * .25)
            + ($behavior === 'observed' ? .20 : 0)
            + ((string) data_get($counterfactual, 'status') === 'assessed' ? .15 : 0)
            + ($repeat === 0 ? .10 : 0)
        );

        return [
            'protocol' => self::BELIEF_PROTOCOL,
            'state' => $confidence >= .70 ? 'partially_supported' : ($confidence >= .40 ? 'uncertain' : 'under_observed'),
            'confidence' => round($confidence, 4),
            'known' => $known,
            'unknown' => $unknown,
            'conflicting_evidence' => $conflicts,
            'repeat_failure_count' => $repeat,
            'next_information_needed' => $unknown[0] ?? 'independent forward confirmation',
            'epistemic_boundary' => 'Unknown or conflicting evidence cannot authorize a mutation or promotion.',
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function causalDecisionGraph(string $stage, string $action): array
    {
        $nodes = [
            'market_state', 'regime_classifier', 'signal_construction',
            'entry_topology', 'risk_exit_state', 'execution_cost', 'trade_outcome',
        ];
        $edges = [
            ['from' => 'market_state', 'to' => 'regime_classifier'],
            ['from' => 'regime_classifier', 'to' => 'signal_construction'],
            ['from' => 'signal_construction', 'to' => 'entry_topology'],
            ['from' => 'entry_topology', 'to' => 'risk_exit_state'],
            ['from' => 'risk_exit_state', 'to' => 'execution_cost'],
            ['from' => 'execution_cost', 'to' => 'trade_outcome'],
        ];
        $target = match (true) {
            str_contains($stage, 'transition'), str_contains($stage, 'regime') => 'regime_classifier',
            str_contains($stage, 'entry') => 'entry_topology',
            str_contains($stage, 'exit'), str_contains($stage, 'risk') => 'risk_exit_state',
            str_contains($stage, 'cost') => 'execution_cost',
            default => $action,
        };

        return [
            'protocol' => self::CAUSAL_GRAPH_PROTOCOL,
            'nodes' => $nodes,
            'edges' => $edges,
            'target_node' => $target,
            'intervention' => $action,
            'root_cause_candidates' => [$target, 'upstream_state', 'downstream_execution'],
            'correlation_is_not_causation' => true,
            'promotion_evidence' => false,
        ];
    }

    private function epistemicAction(array $belief, array $counterfactual, string $researchAction): string
    {
        if (in_array('causal control identity', (array) data_get($belief, 'unknown', []), true)) return 'REQUEST_CONTROL';
        if (in_array('independent chronological window coverage', (array) data_get($belief, 'unknown', []), true)) return 'REQUEST_NEW_DATA';
        if ((string) data_get($counterfactual, 'status') !== 'assessed') return 'RUN_COUNTERFACTUAL';
        if ((array) data_get($belief, 'conflicting_evidence', []) !== []) return 'WAIT_FOR_REGIME_CONFIRMATION';

        return match ($researchAction) {
            'data_acquisition' => 'REQUEST_NEW_DATA',
            'do_nothing_and_wait_for_evidence' => 'REQUEST_NEW_DATA',
            default => 'RUN_COUNTERFACTUAL',
        };
    }

    /** @return array<string, mixed> */
    private function nonMyopicExperimentChain(string $selected, array $causal, array $value): array
    {
        $fallback = match ($selected) {
            'regime_classifier' => ['entry_topology', 'exit_state_machine'],
            'entry_topology' => ['regime_classifier', 'exit_state_machine'],
            'exit_state_machine' => ['cost_aware_execution', 'abstention_policy'],
            'abstention_policy' => ['regime_classifier', 'entry_topology'],
            'cost_aware_execution' => ['exit_state_machine', 'abstention_policy'],
            default => ['counterfactual_replay', 'do_nothing_and_wait_for_evidence'],
        };
        $actions = array_values(array_unique([$selected, ...$fallback]));

        return [
            'protocol' => self::EXPERIMENT_CHAIN_PROTOCOL,
            'status' => 'research_plan_only',
            'objective' => 'Maximize information gain over a bounded sequence, not one-step PF.',
            'steps' => collect($actions)->values()->map(function (string $action, int $index) use ($causal, $value): array {
                return [
                    'step' => $index + 1,
                    'action' => $action,
                    'depends_on_previous' => $index > 0,
                    'same_generation_control_required' => true,
                    'expected_behavioral_delta' => data_get($causal, 'hypothesis.expected_behavioral_delta'),
                    'priority_seed' => $index === 0 ? data_get($value, 'score') : null,
                    'stop_if' => ['no_behavioral_delta', 'prediction_accuracy_below_0_5', 'new_evidence_missing'],
                    'promotion_evidence' => false,
                ];
            })->all(),
            'backtrack_rule' => 'A failed step closes its branch; the next step cannot recycle the same dataset/hypothesis identity.',
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function predictionMarket(array $prediction, array $causal): array
    {
        $roles = ['proposer', 'bear', 'control_auditor', 'temporal_auditor', 'execution_auditor'];
        return [
            'protocol' => 'prediction_market_for_research_v1',
            'status' => 'pre_replay_commitment_required',
            'target' => data_get($prediction, 'target', data_get($causal, 'decision_stage')),
            'forecast_fields' => [
                'cause_is_correct_probability',
                'behavioral_delta_probability',
                'target_improvement_probability',
                'non_target_regression_probability',
                'window_survival_probability',
            ],
            'forecasters' => collect($roles)->mapWithKeys(fn (string $role): array => [$role => [
                'probabilities' => null,
                'committed_before_replay' => true,
                'brier_score' => null,
            ]])->all(),
            'aggregation' => 'independent_forecasts_then_calibration_weighted_review',
            'majority_vote_is_not_calibration' => true,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function retirementContract(LabFailureDojoRun $run, array $causal): array
    {
        $signature = (array) $run->failure_signature;
        $history = (array) data_get($run->evidence, 'failure_history', []);
        $repeat = max((int) data_get($signature, 'repeat_count', 0), count($history));
        $behaviorObserved = data_get($causal, 'behavioral_delta.status') === 'observed';
        $status = $repeat >= 3 && ! $behaviorObserved
            ? 'retire_candidate'
            : ($repeat >= 2 ? 'freeze_until_new_evidence' : 'active_research');

        return [
            'protocol' => self::RETIREMENT_PROTOCOL,
            'status' => $status,
            'repeat_count' => $repeat,
            'behavior_observed' => $behaviorObserved,
            'required_new_identity_to_reopen' => true,
            'scalar_rescue_reentry_forbidden' => $repeat >= 2,
            'reopen_conditions' => ['new_market_identity_hash', 'new_chronological_window', 'new_falsifiable_hypothesis'],
            'automatic_retirement' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function routerSafetyContract(array $belief, array $causal): array
    {
        $uncertain = (string) data_get($belief, 'state') !== 'partially_supported';
        return [
            'protocol' => self::ROUTER_PROTOCOL,
            'status' => $uncertain ? 'wait_on_uncertainty' : 'ready_for_specialist_review',
            'specialist_actions' => ['TRADE', 'ABSTAIN', 'REQUEST_NEW_DATA', 'SWITCH_SPECIALIST'],
            'disagreement_action' => 'WAIT',
            'uncertainty_action' => 'WAIT_AND_COLLECT_EVIDENCE',
            'requires_regime_ownership' => true,
            'requires_calibrated_confidence' => true,
            'hypothesis_stage' => data_get($causal, 'decision_stage'),
            'promotion_evidence' => false,
        ];
    }

    private function brierScore(array $plan, array $observed): ?float
    {
        $outcomes = (array) data_get($observed, 'prediction_outcomes', []);
        $forecasts = (array) data_get($plan, 'prediction_market.forecasters.proposer.probabilities', []);
        if ($outcomes === [] || $forecasts === []) return null;
        $scores = [];
        foreach ($forecasts as $key => $probability) {
            if (! array_key_exists($key, $outcomes) || ! is_numeric($probability)) continue;
            $p = max(0.0, min(1.0, (float) $probability));
            $o = (bool) $outcomes[$key] ? 1.0 : 0.0;
            $scores[] = ($p - $o) ** 2;
        }

        return $scores === [] ? null : round(array_sum($scores) / count($scores), 6);
    }

    /** @return array<string, mixed> */
    private function counterfactualLab(array $existing): array
    {
        $branches = ['control', 'mutation', 'veto_off', 'delayed_entry', 'half_risk', 'alternate_exit'];
        $observed = (array) data_get($existing, 'observed_branches', []);

        return [
            'protocol' => self::COUNTERFACTUAL_PROTOCOL,
            'status' => count(array_diff($branches, $observed)) === 0 ? 'ready_for_assessment' : 'incomplete',
            'required_branches' => $branches,
            'missing_branches' => array_values(array_diff($branches, $observed)),
            'metrics' => [
                'avoided_loss' => 'required',
                'missed_profitable_opportunity' => 'required',
                'treatment_effect' => 'required',
                'regime_specific_effect' => 'required',
                'trade_ledger_delta' => 'required',
                'abstention_ledger_delta' => 'required',
            ],
            'abstention_alone_is_not_skill' => true,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function researchTree(string $selected, array $causal, array $value): array
    {
        $branches = [
            'regime_classifier' => ['action' => 'regime_classifier', 'layer' => 'regime_classification'],
            'entry_topology' => ['action' => 'entry_topology', 'layer' => 'entry_exit_state'],
            'exit_lifecycle' => ['action' => 'exit_state_machine', 'layer' => 'entry_exit_state'],
            'abstention' => ['action' => 'abstention_policy', 'layer' => 'abstention'],
            'wait_for_evidence' => ['action' => 'do_nothing_and_wait_for_evidence', 'layer' => 'epistemic_abstention'],
        ];
        foreach ($branches as $key => &$branch) {
            $branch['status'] = $branch['action'] === $selected ? 'selected_research_branch' : 'candidate_branch';
            $branch['control_required'] = true;
            $branch['close_if'] = [
                'prediction_accuracy_below_0_5',
                'no_behavioral_delta',
                'same_dataset_identity',
                'missing_independent_window',
            ];
            $branch['expected_from_current_failure'] = data_get($causal, 'hypothesis.expected_behavioral_delta');
            $branch['experiment_value'] = $branch['action'] === $selected ? $value : null;
            $branch['promotion_evidence'] = false;
        }
        unset($branch);

        return [
            'protocol' => 'research_tree_of_thoughts_v1',
            'selected_branch' => $selected,
            'backtracking_allowed' => true,
            'dead_branch_rule' => 'A failed branch is retired; its scalar mutation is not repeated without new evidence.',
            'branches' => $branches,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function adversarialCouncil(string $action, array $causal): array
    {
        $roles = [
            'proposer' => 'state the mechanism and expected delta',
            'bear' => 'find a disconfirming explanation and likely failure',
            'control_auditor' => 'verify same-generation exact control and execution identity',
            'temporal_auditor' => 'check chronological windows, purge, embargo and leakage',
            'execution_auditor' => 'check spread, slippage, volume and cost regression',
        ];

        return [
            'protocol' => self::COUNCIL_PROTOCOL,
            'status' => 'awaiting_independent_role_reports',
            'selected_action' => $action,
            'hypothesis' => data_get($causal, 'hypothesis.falsifiable_statement'),
            'roles' => collect($roles)->mapWithKeys(fn (string $brief, string $role): array => [$role => [
                'status' => 'pending', 'brief' => $brief, 'verdict' => null,
            ]])->all(),
            'disagreement_action' => 'WAIT_AND_COLLECT_EVIDENCE',
            'majority_vote_is_not_evidence' => true,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function regimeWorldModel(array $failure, array $causal): array
    {
        $states = ['trend', 'transition', 'range', 'breakout', 'volatility_stress'];
        $edges = [
            ['from' => 'trend', 'to' => 'transition'],
            ['from' => 'transition', 'to' => 'range'],
            ['from' => 'transition', 'to' => 'breakout'],
            ['from' => 'breakout', 'to' => 'volatility_stress'],
            ['from' => 'range', 'to' => 'breakout'],
        ];

        return [
            'protocol' => self::WORLD_MODEL_PROTOCOL,
            'status' => 'contract_only_unassessed',
            'states' => $states,
            'transitions' => $edges,
            'target_state' => data_get($failure, 'state.regime', data_get($failure, 'regime')),
            'required_metrics' => [
                'state_persistence', 'transition_hazard', 'signal_half_life',
                'spread_sensitivity', 'follow_through_probability', 'expected_drawdown',
            ],
            'calendar_features_are_diagnostic_only' => true,
            'hypothesis_stage' => data_get($causal, 'decision_stage'),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function executableLesson(LabFailureDojoRun $run, array $causal, string $action): array
    {
        $state = (array) data_get($run->failure_signature, 'state', []);
        $status = data_get($causal, 'reusable_lesson.status') === 'reusable' ? 'shadow_reusable' : 'diagnostic_only';

        return [
            'protocol' => self::LESSON_PROTOCOL,
            'status' => $status,
            'scope' => [
                'symbol' => $run->symbol,
                'timeframe' => $run->timeframe,
                'decision_stage' => data_get($causal, 'decision_stage'),
                'regime' => data_get($state, 'regime', data_get($state, 'transition_state')),
                'family' => $run->family,
            ],
            'validity' => [
                'expiry_rule' => 'Expire after confirmed market drift, new execution contract or contradictory independent evidence.',
                'revalidation_required' => true,
                'revalidation_windows' => self::REQUIRED_WINDOWS,
                'minimum_positive_windows' => self::MINIMUM_POSITIVE_WINDOWS,
                'apply_outside_scope' => false,
            ],
            'when' => [
                'regime' => data_get($state, 'regime', data_get($state, 'transition_state')),
                'spread_state' => data_get($state, 'spread_state'),
                'signal_age_candles' => data_get($state, 'signal_age_candles'),
            ],
            'then' => match ($action) {
                'abstention_policy' => ['ABSTAIN', 'WAIT_FOR_REGIME_CONFIRMATION'],
                'regime_classifier' => ['REQUEST_COUNTERFACTUAL', 'SWITCH_SPECIALIST'],
                'do_nothing_and_wait_for_evidence' => ['REQUEST_NEW_DATA'],
                default => ['RUN_COUNTERFACTUAL', 'REQUEST_CONTROL'],
            },
            'because' => data_get($causal, 'hypothesis.falsifiable_statement'),
            'do_not_apply_if' => ['independent_evidence_missing', 'control_missing', 'non_target_regression'],
            'evidence' => [
                'windows' => (int) data_get($causal, 'independent_windows.observed_windows', 0),
                'positive_windows' => (int) data_get($causal, 'independent_windows.positive_windows', 0),
                'control_status' => data_get($causal, 'exact_control.status'),
            ],
            'mutation_credit_allowed' => false,
            'parent_eligible' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function strategicCreditContract(): array
    {
        return [
            'protocol' => 'strategic_credit_v1',
            'status' => 'not_eligible',
            'components' => [
                'correct_cause_prediction' => null,
                'behavioral_delta_prediction' => null,
                'information_gain' => null,
                'reusable_lesson_quality' => null,
                'cross_window_repeatability' => null,
                'repeated_failure_penalty' => null,
                'unsupported_confidence_penalty' => null,
                'duplicate_mutation_penalty' => null,
            ],
            'pf_is_not_sufficient' => true,
            'mutation_credit_allowed' => false,
            'parent_eligible' => false,
            'promotion_evidence' => false,
        ];
    }

    private function selectAction(string $target, array $failure, array $causal): string
    {
        $text = strtolower(implode('|', [
            $target,
            (string) data_get($failure, 'failure_reason'),
            (string) data_get($failure, 'failure_type'),
            (string) data_get($causal, 'decision_stage'),
        ]));
        if (str_contains($text, 'evidence') || str_contains($text, 'data')) return 'data_acquisition';
        if (str_contains($text, 'temporal') || str_contains($text, 'calendar') || str_contains($text, 'transition')) return 'regime_classifier';
        if (str_contains($text, 'drawdown') || str_contains($text, 'exit') || str_contains($text, 'risk')) return 'exit_state_machine';
        if (str_contains($text, 'spread') || str_contains($text, 'cost') || str_contains($text, 'slippage')) return 'cost_aware_execution';
        if (str_contains($text, 'abstention') || str_contains($text, 'veto')) return 'abstention_policy';
        if (str_contains($text, 'entry') || str_contains($text, 'signal')) return 'entry_topology';
        return 'counterfactual_replay';
    }

    /** @return array<string, mixed> */
    private function experimentValue(array $failure, array $causal, array $priority, string $action): array
    {
        $novelty = $this->bounded(data_get($priority, 'components.novelty', data_get($failure, 'novelty', 0.5)));
        $leverage = $this->bounded(data_get($priority, 'components.causal_leverage', data_get($failure, 'causal_leverage', 0.5)));
        $readiness = $this->bounded(data_get($priority, 'components.replay_readiness', 0.5));
        $behavior = data_get($causal, 'behavioral_delta.status') === 'observed' ? 1.0 : .35;
        $actionNovelty = in_array($action, ['regime_classifier', 'entry_topology', 'exit_state_machine', 'cost_aware_execution'], true) ? 1.0 : .75;
        $score = $novelty * $leverage * $readiness * $behavior * $actionNovelty;

        return [
            'protocol' => 'causal_experiment_value_v1',
            'formula' => 'information_gain * causal_leverage * novelty * behavioral_testability * failure_reduction_potential',
            'components' => [
                'information_gain' => $novelty,
                'causal_leverage' => $leverage,
                'novelty' => $actionNovelty,
                'behavioral_testability' => $readiness,
                'failure_reduction_potential' => $behavior,
            ],
            'score' => round($score, 6),
            'selected_action' => $action,
            'ranking_only' => true,
            'promotion_evidence' => false,
        ];
    }

    private function failureCause(array $failure, array $causal): string
    {
        return (string) (
            data_get($failure, 'failure_reason')
            ?: data_get($failure, 'failure_type')
            ?: data_get($causal, 'decision_stage')
            ?: 'unclassified_failure'
        );
    }

    /** @return array<int, string> */
    private function disconfirmingResult(string $action): array
    {
        return [
            'no_behavioral_delta',
            'prediction_accuracy_below_0_5',
            'control_not_available',
            'independent_windows_below_3',
            'non_target_regression',
            $action === 'do_nothing_and_wait_for_evidence' ? 'no_new_market_identity' : 'same_dataset_identity',
        ];
    }

    private function bounded(mixed $value): float
    {
        return max(0.0, min(1.0, is_numeric($value) ? (float) $value : 0.5));
    }
}
