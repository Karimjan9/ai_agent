<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;

/**
 * Defines the causal, control-paired research cohort used after a rescue
 * lane has been admitted.  This is deliberately a research contract: it can
 * discover skills, but it cannot grant parent, paper, or promotion evidence.
 */
class StructuralResearchCohortService
{
    public const PROTOCOL = 'structural_causal_cohort_v1';
    public const HYPOTHESIS_PROTOCOL = 'regime_conditioned_entry_exit_state_machine_v1';
    public const CONTROL_PAIR_PROTOCOL = 'frozen_control_pair_v1';
    public const CAUSAL_PROBE_PROTOCOL = 'causal_micro_probe_v1';
    public const INDEPENDENT_EVIDENCE_PROTOCOL = 'independent_chronological_evidence_v1';
    public const COHORT_MODE = 'structural_twenty_control_paired_v1';
    public const POPULATION_SIZE = 20;

    /** @var array<string, int> */
    public const FAMILY_SEATS = [
        'regime_entry_exit_topology' => 4,
        'transition_quality_state_machine' => 4,
        'volume_session_m15' => 4,
        'risk_exit_lifecycle' => 4,
        'long_short_asymmetry' => 4,
    ];

    public function isProfile(?array $profile): bool
    {
        return is_array($profile)
            && ((string) data_get($profile, 'cohort_mode') === self::COHORT_MODE
                || (string) data_get($profile, 'structural_research_contract.protocol') === self::PROTOCOL);
    }

    public function isGeneration(?LabGeneration $generation): bool
    {
        if (! $generation) return false;

        $context = (array) $generation->trigger_context;
        if ((string) data_get($context, 'targeted_failure_profile.cohort_mode') === self::COHORT_MODE
            || (string) data_get($context, 'structural_research_contract.protocol') === self::PROTOCOL
            || (string) data_get($context, 'targeted_failure_profile.structural_research_contract.protocol') === self::PROTOCOL) {
            return true;
        }

        foreach ((array) data_get($context, 'generation_plan', []) as $seat) {
            if ((string) data_get($seat, 'niche.structural_cohort_protocol') === self::PROTOCOL) return true;
        }

        return false;
    }

    public function isAgent(object $agent): bool
    {
        $metadata = (array) data_get($agent, 'modelVersion.metadata', []);

        return (string) data_get($metadata, 'portfolio_council_lane.structural_cohort_protocol') === self::PROTOCOL
            || (string) data_get($metadata, 'portfolio_council_lane.structural_cohort_id') !== '';
    }

    /** @return array<string, mixed> */
    public function contract(?array $profile = null): array
    {
        $profile = $profile ?: [];

        return [
            'protocol' => self::PROTOCOL,
            'cohort_mode' => self::COHORT_MODE,
            'hypothesis_protocol' => self::HYPOTHESIS_PROTOCOL,
            'hypothesis' => 'Condition entry/exit topology and risk by closed H1 regime, transition quality, volume/session state, and directional asymmetry; scalar wait/EMA/ROC changes alone are not admissible.',
            'population_size' => self::POPULATION_SIZE,
            'frozen_control_seats' => 2,
            'candidate_seats' => 18,
            'structural_families' => array_keys(self::FAMILY_SEATS),
            'family_seats' => self::FAMILY_SEATS,
            'control_pair' => [
                'protocol' => self::CONTROL_PAIR_PROTOCOL,
                'required_for_every_candidate' => true,
                'same_generation' => true,
                'same_symbol_timeframe' => true,
                'same_execution_contract' => true,
                'missing_control_action' => 'diagnostic_only_no_learning_credit_no_full_replay',
            ],
            'causal_micro_probe' => [
                'protocol' => self::CAUSAL_PROBE_PROTOCOL,
                'required_before_full_replay' => true,
                'checks' => [
                    'trade_set_hash_changed',
                    'accepted_entry_count_changed',
                    'accepted_exit_count_changed',
                    'abstention_removed_real_trade',
                    'target_gate_margin_improved_vs_control',
                ],
                'parameter_hash_alone_is_insufficient' => true,
                'promotion_evidence' => false,
            ],
            'independent_evidence' => [
                'protocol' => self::INDEPENDENT_EVIDENCE_PROTOCOL,
                'non_overlap_required' => true,
                'minimum_fresh_h1_candles' => 24,
                'sealed_holdout_allowed' => true,
                'one_candle_is_insufficient' => true,
                'promotion_evidence' => false,
            ],
            'promotion_evidence' => false,
            'source_generation_id' => data_get($profile, 'source_generation_id'),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function groupPlan(): array
    {
        return [
            'regime_coverage' => [
                'rescue_objective' => 'regime_entry_exit_topology',
                'specialist_role' => 'regime_coverage_specialist',
                'structural_family' => 'regime_entry_exit_topology',
                'targets' => array_fill(0, 4, 'regime_coverage'),
            ],
            'monthly_survival' => [
                'rescue_objective' => 'transition_quality_state_machine',
                'specialist_role' => 'temporal_calendar_specialist',
                'structural_family' => 'transition_quality_state_machine',
                'targets' => array_fill(0, 4, 'temporal_stability'),
            ],
            'volatility_session_stability' => [
                'rescue_objective' => 'volume_session_m15',
                'specialist_role' => 'volume_m15_specialist',
                'structural_family' => 'volume_session_m15',
                'targets' => array_fill(0, 4, 'stress_cost'),
            ],
            'exit_topology' => [
                'rescue_objective' => 'risk_exit_lifecycle',
                'specialist_role' => 'cost_stability_specialist',
                'structural_family' => 'risk_exit_lifecycle',
                'targets' => array_fill(0, 4, 'drawdown_risk'),
            ],
            'portfolio_router' => [
                'rescue_objective' => 'long_short_asymmetry',
                'specialist_role' => 'regime_coverage_specialist',
                'structural_family' => 'long_short_asymmetry',
                'targets' => array_fill(0, 4, 'regime_coverage'),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function plan(AiLaboratory $lab, array $profile = []): array
    {
        $hybrid = in_array('hybrid', (array) $lab->strategy_families, true)
            ? 'hybrid'
            : (((array) $lab->strategy_families)[0] ?? 'hybrid');
        $differential = in_array('differential_router', (array) $lab->strategy_families, true)
            ? 'differential_router'
            : $hybrid;
        $cohortId = hash('sha256', json_encode([
            self::PROTOCOL, $lab->id, data_get($profile, 'source_generation_id'),
            data_get($profile, 'profile_hash'),
        ], JSON_UNESCAPED_SLASHES));
        $plan = [];
        $sequence = 0;

        $add = function (string $group, string $family, string $target, string $role, array $niche) use (&$plan, &$sequence, $cohortId): void {
            $sequence++;
            $plan[] = [
                'origin' => 'targeted_failure_profile',
                'family' => $family,
                'target' => $target,
                'research_group' => $group,
                'group_seat' => count(array_filter($plan, fn (array $seat): bool => data_get($seat, 'research_group') === $group)) + 1,
                'group_axis' => LabPopulationService::POPULATION_GROUPS[$group]['axis'] ?? 'structural_research',
                'group_search_mode' => 'structural_causal',
                'group_search_role' => 'structural_hypothesis',
                'niche' => [
                    'protocol' => LabPopulationService::TARGETED_RESCUE_PROFILE_PROTOCOL,
                    'rescue_protocol' => LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL,
                    'structural_cohort_protocol' => self::PROTOCOL,
                    'structural_cohort_id' => $cohortId,
                    'structural_hypothesis_protocol' => self::HYPOTHESIS_PROTOCOL,
                    'frozen_control_pair_required' => true,
                    'causal_micro_probe_required' => true,
                    'independent_evidence_required' => true,
                    'shadow_only' => true,
                    'promotion_evidence' => false,
                    ...$niche,
                ],
            ];
        };

        // Four executable entry/exit topology variants. They share the
        // family, but each tests a different causal admission mechanism.
        foreach ([
            'regime_consensus_v1', 'transition_hazard_v1',
            'trend_regime_confirmation_v1', 'range_reentry_confirmation_v1',
        ] as $variant) {
            $add('regime_coverage', $hybrid, 'regime_coverage', 'regime_coverage_specialist', [
                'structural_family' => 'regime_entry_exit_topology',
                'structural_operation' => 'regime_conditioned_entry_exit_topology',
                'declared_gene' => 'entry_topology_variant',
                'declared_value' => $variant,
                'entry_topology_variant' => $variant,
                'architecture_experiment' => true,
                'architecture_variant' => null,
            ]);
        }

        // State persistence is a different architecture question from a
        // scalar cooldown. One state-machine seat is executable; the other
        // seats test independent temporal/abstention mechanisms.
        foreach ([
            ['gene' => 'state_machine_variant', 'value' => 'neutral_transition_cooldown_reentry_v1'],
            ['gene' => 'adaptive_signal_expiry_enabled', 'value' => true],
            ['gene' => 'drift_abstention_enabled', 'value' => true],
            ['gene' => 'temporal_survival_enabled', 'value' => true],
        ] as $probe) {
            $add('monthly_survival', $hybrid, 'temporal_stability', 'temporal_calendar_specialist', [
                'structural_family' => 'transition_quality_state_machine',
                'structural_operation' => 'neutral_transition_cooldown_reentry_state_machine',
                'declared_gene' => $probe['gene'],
                'declared_value' => $probe['value'],
                'state_machine_variant' => $probe['gene'] === 'state_machine_variant' ? $probe['value'] : null,
                'architecture_experiment' => $probe['gene'] === 'state_machine_variant',
            ]);
        }

        // Volume/session/M15 is kept shadow-only and uses one causal lane per
        // seat. The fourth lane is a relative-volume confirmation mode.
        foreach ([
            'breakout_volume_confirmation', 'transition_volume_router',
            'low_volume_risk_firewall', 'relative_volume_confirmation_v1',
        ] as $variant) {
            $add('volatility_session_stability', $hybrid, 'stress_cost', 'volume_m15_specialist', [
                'structural_family' => 'volume_session_m15',
                'structural_operation' => 'relative_volume_session_execution_filter',
                'declared_gene' => 'volume_lane',
                'declared_value' => $variant,
                'volume_lane' => $variant,
                'volume_shadow' => true,
            ]);
        }

        // Three exit-lifecycle variants plus one immutable hybrid control.
        foreach ([
            ['gene' => 'atr_stop_multiplier', 'target' => 'drawdown_risk'],
            ['gene' => 'partial_take_profit_fraction', 'target' => 'drawdown_risk'],
            ['gene' => 'time_stop_candles', 'target' => 'drawdown_risk'],
        ] as $probe) {
            $add('exit_topology', $hybrid, $probe['target'], 'cost_stability_specialist', [
                'structural_family' => 'risk_exit_lifecycle',
                'structural_operation' => 'cost_aware_exit_lifecycle',
                'declared_gene' => $probe['gene'],
            ]);
        }
        $add('exit_topology', $hybrid, 'architecture', 'control_specialist', [
            'structural_family' => 'frozen_control',
            'structural_operation' => 'frozen_control',
            'control_only' => true,
            'g98_control_only' => true,
            'shadow_only' => false,
        ]);

        // Directional asymmetry is run through the differential router when
        // that family exists. It receives its own frozen control in the same
        // family, so no cross-family result can earn credit.
        foreach ([
            ['regime' => 'trend_up', 'gene' => 'trend_up_risk_multiplier'],
            ['regime' => 'trend_down', 'gene' => 'trend_down_risk_multiplier'],
            ['regime' => 'trend_up', 'gene' => 'trend_up_strength_min'],
        ] as $probe) {
            $add('portfolio_router', $differential, 'regime_coverage', 'regime_coverage_specialist', [
                'structural_family' => 'long_short_asymmetry',
                'structural_operation' => 'long_short_directional_asymmetry',
                'declared_gene' => $probe['gene'],
                'regime' => $probe['regime'],
            ]);
        }
        $add('portfolio_router', $differential, 'architecture', 'control_specialist', [
            'structural_family' => 'frozen_control',
            'structural_operation' => 'frozen_control',
            'control_only' => true,
            'g98_control_only' => true,
            'shadow_only' => false,
        ]);

        return $plan;
    }

    /** @return array{allowed: bool, reason: string, seats: int, families: array<string, int>} */
    public function validatePlan(array $plan): array
    {
        $families = [];
        $controls = 0;
        foreach ($plan as $seat) {
            $family = (string) data_get($seat, 'niche.structural_family', '');
            if ($family !== '') $families[$family] = ($families[$family] ?? 0) + 1;
            if ((bool) data_get($seat, 'niche.control_only', false)) $controls++;
        }
        $allowed = count($plan) === self::POPULATION_SIZE
            && $controls === 2
            && count(array_filter($families, fn (int $count, string $family): bool => $family !== 'frozen_control' && $count > 0, ARRAY_FILTER_USE_BOTH)) >= 5;

        return [
            'protocol' => self::PROTOCOL,
            'cohort_mode' => self::COHORT_MODE,
            'allowed' => $allowed,
            'reason' => $allowed ? 'STRUCTURAL_COHORT_CONTRACT_VALID' : 'STRUCTURAL_COHORT_CONTRACT_INVALID',
            'seats' => count($plan),
            'controls' => $controls,
            'families' => $families,
            'causal_micro_probe_required_before_full_replay' => true,
            'independent_chronological_evidence_required' => true,
            'promotion_evidence' => false,
        ];
    }
}
