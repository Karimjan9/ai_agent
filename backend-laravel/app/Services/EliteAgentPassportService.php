<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\File;

/**
 * Immutable, evidence-first manifest for a candidate approaching Forward.
 * It deliberately adds preflight checks; it never relaxes an existing gate.
 */
class EliteAgentPassportService
{
    public function __construct(
        private AgentConstitutionService $constitutions,
        private ParentContributionGraphService $parentGraphService,
    ) {}

    public function build(ModelVersion $model, ?LabAgent $agent, array $result): array
    {
        $parameters = $model->parameters ?? [];
        ksort($parameters);
        $parentGraph = $agent
            ? $agent->loadMissing(['parentLinks', 'inheritanceAudits'])->parentLinks->map(fn ($link): array => [
                'parent_model_version_id' => $link->parent_model_version_id,
                'relation_type' => $link->relation_type,
                'contribution_key' => $link->contribution_key,
                'metadata' => $link->metadata,
            ])->values()->all()
            : [];
        $parentIds = $agent ? $this->parentGraphService->ids($agent) : [];
        $inheritanceAudit = $agent?->inheritanceAudits
            ?->where('protocol', ControlRootInheritanceService::PROTOCOL)
            ?->sortByDesc('id')
            ?->first();
        $manifest = (array) data_get($result, 'data_manifest', []);
        $monthly = (array) data_get($result, 'monthly_passport', []);
        $redTeam = (array) data_get($result, 'red_team', []);
        $news = (array) data_get($redTeam, 'scenarios.news_window', []);
        $quorum = $this->eliteQuorum($result, $monthly, $redTeam);
        $constitution = $this->constitutions->verify($model, $result);
        $metadata = (array) $model->metadata;
        $councilRole = (string) (
            data_get($metadata, 'council_specialist_contract.role')
            ?: data_get($metadata, 'portfolio_council_lane.specialist_role')
            ?: data_get($metadata, 'portfolio_council_lane.role')
        );
        $roleCompleteCouncil = data_get($metadata, 'role_complete_council.protocol') === 'role_complete_council_v1';
        $controlOnly = (bool) data_get($metadata, 'mutation_constructor_invariant.control_only', false)
            || (bool) data_get($metadata, 'g98_council_lane.control_only', false)
            || data_get($metadata, 'role_complete_council.role_control.type') === 'no_change_control';
        $rolePolicy = (array) data_get($metadata, 'role_complete_council.policy', []);
        $rolePolicyIntegrity = ! $roleCompleteCouncil
            || $this->rolePolicyIntegrity($councilRole, $rolePolicy, $parameters, $agent);
        // Legacy unit fixtures and pre-constitution rows remain auditable but
        // are not promoted by this check. Every new sealed model that carries
        // a constitution, and every role-complete council child, must pass
        // the integrity/architecture check below.
        $requiresConstitution = $roleCompleteCouncil || array_key_exists('agent_constitution', $metadata);
        $professional = (array) data_get($result, 'professional_exams', data_get($metadata, 'agent_knowledge.professional_exams', []));
        $hasParent = $parentIds !== [];
        $requiresEpistemicGate = (int) data_get($model->metadata, 'statistical_gate_version', 0) >= 3;
        $requiresPromotionProof = data_get($model->metadata, 'g98_council_lane.protocol') === 'g98_failure_eliminator_v1';
        $requiresRobustnessGate = (int) data_get($model->metadata, 'robustness_gate_version', 0) >= 1;
        $repairLineage = (array) data_get($model->metadata, 'repair_lineage', []);
        $repairAnchorId = (int) data_get($model->metadata, 'repair_anchor.id', 0);
        $repairProtocol = (int) data_get($repairLineage, 'attempt', 0) === 0 || (
            $repairAnchorId > 0
                ? data_get($result, 'repair_anchor_verification.status') === 'confirmed'
                    && data_get($result, 'no_regression_contract.status') === 'passed'
                : count((array) ($agent?->parameter_diff ?? [])) === 1
                    && data_get($result, 'paired_replay.status') === 'confirmed'
                    && data_get($result, 'no_regression_contract.status') === 'passed'
        );
        $forwardWindowIntegrity = ! $requiresRobustnessGate
            || (data_get($result, 'forward_window_protocol.independence_verified') === true
                && data_get($result, 'forward_window_protocol.overlap_detected') !== true);
        $checks = [
            'constitution_integrity' => ! $requiresConstitution
                || (data_get($constitution, 'integrity') === true
                    && data_get($constitution, 'architecture_matches') === true
                    && data_get($constitution, 'status') !== 'invalid'),
            // A constitution can be cryptographically intact while its
            // economic thesis is falsified by the current replay. Keep this
            // separate so operators do not mistake evidence failure for hash
            // corruption; both remain hard promotion gates.
            'constitution_falsification' => ! $requiresConstitution
                || data_get($constitution, 'falsified_by_evidence') !== true,
            'signal_viability' => (int) data_get($result, 'entry_funnel.raw_strategy_signals', 0) > 0
                && (int) data_get($result, 'entry_funnel.accepted_entries', 0) > 0,
            'veto_regret' => array_key_exists('shadow_trade_count', (array) data_get($result, 'veto_regret', [])),
            'monthly_walk_forward' => (int) data_get($monthly, 'rolling_forward_wins', 0) >= 3
                && (int) data_get($monthly, 'failed_months', 0) === 0,
            'regime_coverage' => data_get($result, 'behavioral_diversity.status') === 'diverse'
                && (float) data_get($result, 'statistical_evidence.edge_quality.worst_regime_pf', 0) >= 1.0,
            'red_team_stress' => data_get($redTeam, 'scenarios.double_cost_execution.status') === 'assessed'
                && (bool) data_get($redTeam, 'scenarios.double_cost_execution.pass'),
            // The calendar must be explicitly aligned. "not assessed" is an
            // evidence gap, never silently treated as a pass.
            'calendar_alignment' => data_get($news, 'status') === 'assessed' && (bool) data_get($news, 'pass'),
            'data_manifest' => data_get($manifest, 'status') === 'ready' && filled(data_get($manifest, 'sha256')),
            'next_candle_execution' => str_contains((string) data_get($result, 'market_adaptive_replay.protocol', ''), 'next candle execution'),
            'sealed_holdout' => data_get($result, 'market_adaptive_replay.sealed_holdout.used_for_training') === false
                && data_get($result, 'market_adaptive_replay.sealed_holdout.used_for_evolution') === false,
            'secret_adversarial_arena' => data_get($result, 'secret_adversarial_arena.status') === 'passed',
            'temporal_firewall' => data_get($result, 'temporal_firewall.status') === 'passed'
                && data_get($result, 'permanent_unseen_challenge.status') === 'sealed',
            'elite_quorum' => $quorum['status'] === 'passed',
            'epistemic_boundary' => ! $requiresEpistemicGate || in_array(data_get($result, 'epistemic_boundary.unknown_state_action'), ['WAIT', 'REDUCE_RISK', 'ALLOW_WITH_GUARDS'], true),
            // Version 4 is reserved for a future population after the
            // independent cross-market harness exists. Existing v3 agents
            // remain subject to their unchanged gates, never grandfathered.
            'cross_market_transfer' => (int) data_get($model->metadata, 'statistical_gate_version', 0) < 4
                || data_get($result, 'cross_market_transfer_passport.status') === 'assessed',
            'pass_k_reliability' => (int) data_get($model->metadata, 'statistical_gate_version', 0) < 4
                || data_get($result, 'pass_k_reliability.status') === 'passed',
            'p0_failure_curriculum' => (int) data_get($model->metadata, 'statistical_gate_version', 0) < 4
                || (bool) data_get($result, 'failure_curriculum.p0_safety_passed', false),
            'certified_coverage' => ! $requiresPromotionProof
                || (data_get($result, 'certified_coverage_passport.status') === 'assessed'
                    && (int) data_get($result, 'certified_coverage_passport.certified_cells', 0) >= 1
                    && (int) data_get($result, 'certified_coverage_passport.uncertified_cells', 0) === 0),
            'opportunity_recall' => ! $requiresPromotionProof
                || (data_get($result, 'opportunity_recall.status') === 'assessed'
                    && (int) data_get($result, 'opportunity_recall.accepted_entries', 0) >= 10
                    && (float) data_get($result, 'opportunity_recall.opportunity_recall', 0) >= .20
                    && (float) data_get($result, 'opportunity_recall.abstention_precision', 0) >= .50),
            'proof_carrying_replay' => ! $requiresPromotionProof
                || data_get($result, 'proof_carrying_replay.status') === 'passed',
            'noise_sanity' => ! $requiresRobustnessGate
                || (data_get($result, 'noise_sanity.status') === 'assessed' && (bool) data_get($result, 'noise_sanity.pass', false)),
            'execution_digital_twin' => ! $requiresRobustnessGate
                || (data_get($result, 'execution_digital_twin.status') === 'assessed' && (bool) data_get($result, 'execution_digital_twin.pass', false)),
            'parameter_plateau' => ! $requiresRobustnessGate
                || (data_get($result, 'parameter_plateau.status') === 'assessed'
                    && (bool) data_get($result, 'parameter_plateau.pass', false)),
            'paired_replay' => ! $requiresRobustnessGate || $repairProtocol,
            'data_quality' => ! $requiresRobustnessGate
                || (data_get($result, 'data_quality.status') === 'passed'
                    && (int) data_get($result, 'data_quality.duplicate_timestamp_count', 0) === 0
                    && (int) data_get($result, 'data_quality.non_monotonic_timestamp_pairs', 0) === 0
                    && (int) data_get($result, 'data_quality.invalid_ohlc_rows', 0) === 0),
            'gold_holdout' => ! $requiresRobustnessGate
                || (data_get($result, 'gold_holdout.protocol') === 'gold_holdout_v1'
                    && data_get($result, 'gold_holdout.used_for_training') === false
                    && data_get($result, 'gold_holdout.used_for_evolution') === false
                    && data_get($result, 'gold_holdout.one_time_release') === true),
            'forward_window_integrity' => $forwardWindowIntegrity,
            'challenger_protocol' => ! $requiresRobustnessGate
                || ((int) data_get($result, 'challenger_protocol.observed_forward_windows', 0) >= 3
                    && (int) data_get($result, 'challenger_protocol.positive_forward_windows', 0) >= 3
                    && $forwardWindowIntegrity),
            'professional_hidden_state_challenge' => ! $roleCompleteCouncil
                || data_get($professional, 'hidden_state_challenge.status') === 'passed',
            'professional_drift_recertification' => ! $roleCompleteCouncil
                || data_get($professional, 'drift_recertification.status') === 'active',
            'professional_mutation_budget' => ! $roleCompleteCouncil
                || data_get($professional, 'mutation_budget.status') === 'assessed',
            'professional_teacher_student_shadow' => ! $roleCompleteCouncil
                || ! $hasParent
                || data_get($professional, 'teacher_student_shadow.status') === 'passed',
            'professional_router_calibration' => ! $roleCompleteCouncil
                || $councilRole !== 'transition_risk_router'
                || data_get($professional, 'router_calibration.status') === 'assessed',
            'role_policy_integrity' => $rolePolicyIntegrity,
            // A no-change role control is diagnostic evidence only. It may
            // complete a replay needed to prove that the owner lane is
            // exhausted, but it can never become a specialist or paper member.
            'role_specialist_mutation' => ! $roleCompleteCouncil
                || ! $controlOnly,
            'control_only_lane' => ! $controlOnly,
        ];
        $failed = collect($checks)->filter(fn (bool $pass) => ! $pass)->keys()->map(
            fn (string $check) => 'FAILED_PASSPORT_'.strtoupper($check)
        )->values()->all();
        $parameterHash = hash('sha256', json_encode($parameters, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
        $resultHash = hash('sha256', json_encode($this->canonicalize($result), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));

        return [
            'protocol' => 'elite_agent_passport_v1',
            'status' => $failed === [] ? 'passed' : 'failed',
            'reason_codes' => $failed,
            'agent' => [
                'model_version_id' => $model->id, 'lab_agent_id' => $agent?->id,
                'parameters' => $parameters, 'parameter_hash' => $parameterHash,
                'strategy_architecture' => data_get($model->metadata, 'strategy_architecture'),
                'parent_a_model_version_id' => $agent?->parent_a_model_version_id,
                'parent_b_model_version_id' => $agent?->parent_b_model_version_id,
                'parent_model_version_ids' => $parentIds,
                'parent_contribution_graph' => [
                    'protocol' => 'lab_agent_parent_graph_v1',
                    'links' => $parentGraph,
                    'complete' => $agent === null || $parentGraph !== [] || ! $hasParent,
                    'resolved_parent_model_version_ids' => $parentIds,
                    'promotion_evidence' => false,
                ],
                'control_root_specialist_inheritance' => [
                    'protocol' => ControlRootInheritanceService::PROTOCOL,
                    'contract' => data_get($metadata, 'control_root_specialist_inheritance'),
                    'seed' => data_get($metadata, 'control_root_seed'),
                    'audit_id' => $inheritanceAudit?->id,
                    'audit_decision' => $inheritanceAudit?->decision,
                    'promotion_evidence' => false,
                ],
                'mutation_history' => $agent?->mutationMemories()->latest()->take(12)->get()->map(fn ($memory) => [
                    'parameter' => $memory->parameter_key, 'outcome' => $memory->outcome,
                    'gate_transition' => $memory->gate_transition,
                ])->all() ?? [],
            ],
            'data_manifest' => $manifest,
            'code_version' => $this->codeFingerprint(),
            'execution_assumptions' => data_get($result, 'execution_assumptions', []),
            'regime_performance' => data_get($result, 'regime_performance', []),
            'monthly_walk_forward' => $monthly,
            'veto_regret' => data_get($result, 'veto_regret', []),
            'stress_tests' => $redTeam,
            'secret_adversarial_arena' => data_get($result, 'secret_adversarial_arena', []),
            'temporal_firewall' => data_get($result, 'temporal_firewall', []),
            'elite_quorum' => $quorum,
            'agent_constitution' => $constitution,
            'role_policy' => $rolePolicy,
            'professional_exams' => $professional,
            'calibration_status' => data_get($result, 'statistical_evidence.edge_quality.confidence_calibration', []),
            'epistemic_boundary' => data_get($result, 'epistemic_boundary', []),
            'trial_ledger' => data_get($result, 'trial_ledger', []),
            'cross_market_transfer_passport' => data_get($result, 'cross_market_transfer_passport', []),
            'pass_k_reliability' => data_get($result, 'pass_k_reliability', []),
            'certified_coverage_passport' => data_get($result, 'certified_coverage_passport', []),
            'opportunity_recall' => data_get($result, 'opportunity_recall', []),
            'proof_carrying_replay' => data_get($result, 'proof_carrying_replay', []),
            'noise_sanity' => data_get($result, 'noise_sanity', []),
            'execution_digital_twin' => data_get($result, 'execution_digital_twin', []),
            'parameter_plateau' => data_get($result, 'parameter_plateau', []),
            'paired_replay' => data_get($result, 'paired_replay', []),
            'data_quality' => data_get($result, 'data_quality', []),
            'gold_holdout' => data_get($result, 'gold_holdout', []),
            'forward_window_protocol' => data_get($result, 'forward_window_protocol', []),
            'challenger_protocol' => data_get($result, 'challenger_protocol', []),
            'preflight' => ['checks' => $checks, 'failed_checks' => $failed],
            'final_exam_result_hash' => $resultHash,
            'generated_at' => now()->utc()->toIso8601String(),
        ];
    }

    /** Freeze a near-forward parent; descendants must be separate model versions. */
    public function freezeIfFinalist(ModelVersion $model, array $passport, array $result): array
    {
        $nearForward = (float) data_get($result, 'profit_factor', 0) >= 1.30
            && (int) data_get($result, 'total_trades', 0) >= 24
            && (int) data_get($result, 'rolling_forward_wins', 0) >= 2
            && (float) data_get($result, 'max_drawdown_percent', 100) <= 15
            && (float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 100) <= 10;
        if (! $nearForward) return $passport;

        $freeze = data_get($model->metadata, 'elite_agent_passport.freeze');
        if (! is_array($freeze)) {
            $freeze = [
                'status' => 'frozen', 'frozen_at' => now()->utc()->toIso8601String(),
                'parameter_hash' => data_get($passport, 'agent.parameter_hash'),
                'data_sha256' => data_get($passport, 'data_manifest.sha256'),
                'final_exam_result_hash' => data_get($passport, 'final_exam_result_hash'),
                'rule' => 'Final-exam observations cannot mutate this model; research continues only in a child fork.',
            ];
            $metadata = $model->metadata ?? [];
            data_set($metadata, 'elite_agent_passport.freeze', $freeze);
            $model->update(['metadata' => $metadata]);
        }
        $passport['freeze'] = $freeze;
        return $passport;
    }

    private function codeFingerprint(): array
    {
        $files = [
            base_path('app/Services/MarketChampionService.php'),
            base_path('app/Services/EliteAgentPassportService.php'),
            base_path('../ai-service-python/app/services/backtester.py'),
            base_path('../ai-service-python/app/services/market_adaptive_replay.py'),
        ];
        $hashes = collect($files)->filter(fn (string $file) => File::exists($file))->mapWithKeys(
            fn (string $file) => [basename($file) => hash_file('sha256', $file)]
        )->all();
        return ['protocol_version' => 1, 'files' => $hashes, 'sha256' => hash('sha256', json_encode($hashes))];
    }

    /** All independent final-exam lanes must retain the same edge claim. */
    private function eliteQuorum(array $result, array $monthly, array $redTeam): array
    {
        $checks = [
            'chronological_walk_forward' => (int) data_get($monthly, 'rolling_forward_wins', 0) >= 3
                && (int) data_get($monthly, 'failed_months', 0) === 0,
            'secret_adversarial_arena' => data_get($result, 'secret_adversarial_arena.status') === 'passed',
            'execution_cost_perturbation' => (float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 0) >= 1.05
                && (bool) data_get($redTeam, 'scenarios.double_cost_execution.pass'),
            'temporal_firewall' => data_get($result, 'temporal_firewall.status') === 'passed',
        ];
        return ['status' => collect($checks)->every(fn (bool $pass) => $pass) ? 'passed' : 'failed', 'checks' => $checks,
            'rule' => 'Paper eligibility requires an independent chronological, execution, adversarial and leakage quorum.'];
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    private function rolePolicyIntegrity(string $role, array $policy, array $parameters, ?LabAgent $agent): bool
    {
        if (data_get($policy, 'protocol') !== 'council_role_policy_v1') return false;
        if (! in_array((string) data_get($policy, 'role'), [
            'trend_up_specialist', 'trend_down_specialist', 'range_specialist', 'transition_risk_router',
        ], true) || (string) data_get($policy, 'role') !== $role) return false;
        if (($parameters['transition_firewall_enabled'] ?? null) !== true) return false;

        $changedGene = array_key_first((array) ($agent?->parameter_diff ?? []));
        $allowlist = (array) data_get($policy, 'mutation_allowlist', []);
        if ($changedGene !== null && ! in_array((string) $changedGene, $allowlist, true)) return false;
        if ($role === 'range_specialist'
            && (($parameters['range_low_volatility_only'] ?? null) !== true
                || ($parameters['range_reentry_required'] ?? null) !== true)) return false;
        if ($role === 'transition_risk_router' && data_get($policy, 'routing_only') !== true) return false;

        return true;
    }
}
