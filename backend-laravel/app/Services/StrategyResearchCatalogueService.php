<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * A research-only catalogue of multi-timeframe market hypotheses.
 *
 * It deliberately separates complete playbooks from reusable modules. This
 * prevents a library of practitioner terminology from becoming an untestable
 * mega-strategy or a shortcut around the normal laboratory/paper gates.
 */
class StrategyResearchCatalogueService
{
    public const PROTOCOL = 'mtf_strategy_research_catalogue_v1';

    /** @var array<int,array<string,mixed>> */
    private const MODELS = [
        [
            'id' => 'liquidity_trap_mtf',
            'label' => 'Liquidity Trap MTF',
            'priority' => 1,
            'class' => 'core_playbook',
            'provenance_class' => 'practitioner_education_hypothesis',
            'roles' => ['direction' => 'H4_bias', 'structure' => 'H1_poi', 'setup' => 'M15_sweep_reclaim', 'confirmation' => 'M5_mss_displacement', 'execution' => 'M5_retest'],
            'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'no_trade' => ['h4_h1_conflict', 'poi_missing', 'trap_not_reclaimed', 'm5_trigger_missing'],
        ],
        [
            'id' => 'ict_2022_raid_mss_fvg',
            'label' => 'Raid -> MSS -> FVG retracement',
            'priority' => 2,
            'class' => 'core_playbook',
            'provenance_class' => 'practitioner_education_hypothesis',
            'roles' => ['direction' => 'D1_H4_bias', 'structure' => 'H1_draw_on_liquidity', 'setup' => 'M15_liquidity_raid', 'confirmation' => 'M5_mss_displacement', 'execution' => 'M5_FVG_retest'],
            'required_streams' => ['D1', 'H4', 'H1', 'M15', 'M5'],
            'no_trade' => ['htf_bias_missing', 'raid_missing', 'displacement_below_minimum', 'fvg_not_retested'],
        ],
        [
            'id' => 'po3_amd_session',
            'label' => 'PO3 / AMD session model',
            'priority' => 3,
            'class' => 'session_playbook',
            'provenance_class' => 'practitioner_education_hypothesis',
            'roles' => ['direction' => 'H4_bias', 'structure' => 'H1_session_narrative', 'setup' => 'M15_accumulation_then_manipulation', 'confirmation' => 'M5_distribution_shift', 'execution' => 'M5_or_M1_retest'],
            'required_streams' => ['H4', 'H1', 'M15', 'M5', 'session_calendar'],
            'no_trade' => ['session_range_missing', 'manipulation_missing', 'distribution_not_confirmed'],
        ],
        [
            'id' => 'london_judas_swing',
            'label' => 'London Judas Swing',
            'priority' => 4,
            'class' => 'session_playbook',
            'provenance_class' => 'practitioner_education_hypothesis',
            'roles' => ['direction' => 'H4_bias', 'structure' => 'H1_target', 'setup' => 'M15_asia_range_sweep', 'confirmation' => 'M5_reversal_mss', 'execution' => 'M5_retest'],
            'required_streams' => ['H4', 'H1', 'M15', 'M5', 'asia_london_sessions'],
            'no_trade' => ['asia_range_missing', 'outside_london_window', 'sweep_not_reclaimed'],
        ],
        [
            'id' => 'turtle_soup_mtf',
            'label' => 'Turtle Soup MTF',
            'priority' => 5,
            'class' => 'reversal_playbook',
            'provenance_class' => 'historical_practitioner_framework',
            'roles' => ['direction' => 'H4_major_level', 'structure' => 'H1_range_or_structure', 'setup' => 'M15_false_break_reclaim', 'confirmation' => 'M5_reversal_mss', 'execution' => 'M5_retest'],
            'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'no_trade' => ['major_level_missing', 'false_break_not_closed_back_inside', 'm5_confirmation_missing'],
        ],
        [
            'id' => 'silver_bullet_window',
            'label' => 'Time-window liquidity model',
            'priority' => 6,
            'class' => 'session_playbook',
            'provenance_class' => 'practitioner_education_hypothesis',
            'roles' => ['direction' => 'H4_H1_bias', 'structure' => 'M15_liquidity_location', 'setup' => 'declared_time_window_sweep', 'confirmation' => 'M5_mss_displacement', 'execution' => 'M5_FVG_retest'],
            'required_streams' => ['H4', 'H1', 'M15', 'M5', 'named_timezone_window'],
            'no_trade' => ['outside_declared_window', 'location_missing', 'sweep_or_mss_missing'],
        ],
        [
            'id' => 'smt_sweep_mss',
            'label' => 'SMT + Sweep + MSS',
            'priority' => 7,
            'class' => 'confirmation_playbook',
            'provenance_class' => 'cross_market_confirmation_hypothesis',
            'roles' => ['direction' => 'H4_H1_bias', 'structure' => 'M15_liquidity_location', 'setup' => 'M15_sweep_plus_related_market_divergence', 'confirmation' => 'M5_mss', 'execution' => 'M5_FVG_retest'],
            'required_streams' => ['H4', 'H1', 'M15', 'M5', 'related_market'],
            'no_trade' => ['related_market_stale', 'correlation_not_declared', 'smt_missing', 'mss_missing'],
        ],
        [
            'id' => 'wyckoff_spring_utad',
            'label' => 'Wyckoff Spring / UTAD',
            'priority' => 8,
            'class' => 'phase_reversal_playbook',
            'provenance_class' => 'historical_market_structure_framework',
            'roles' => ['direction' => 'H4_accumulation_or_distribution', 'structure' => 'H1_range_phase', 'setup' => 'M15_spring_or_utad', 'confirmation' => 'M5_sign_of_strength_or_weakness', 'execution' => 'M5_last_point_of_support_or_supply'],
            'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'no_trade' => ['range_phase_unresolved', 'spring_or_utad_missing', 'test_failure'],
        ],
        [
            'id' => 'elder_triple_screen_liquidity',
            'label' => 'Elder Triple Screen + liquidity',
            'priority' => 9,
            'class' => 'continuation_playbook',
            'provenance_class' => 'historical_practitioner_framework',
            'roles' => ['direction' => 'H4_trend', 'structure' => 'H1_countertrend_correction', 'setup' => 'M15_liquidity_sweep', 'confirmation' => 'M5_trend_resumption', 'execution' => 'M5_retest'],
            'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'no_trade' => ['h4_trend_unclear', 'h1_not_countertrend', 'resumption_missing'],
        ],
        [
            'id' => 'orb_htf_bias',
            'label' => 'Opening Range Breakout + HTF bias',
            'priority' => 10,
            'class' => 'session_playbook',
            'provenance_class' => 'historical_practitioner_framework',
            'roles' => ['direction' => 'H4_H1_trend', 'structure' => 'M15_or_M30_opening_range', 'setup' => 'M5_range_break', 'confirmation' => 'M5_retest_hold', 'execution' => 'next_M5_open'],
            'required_streams' => ['H4', 'H1', 'M15_or_M30', 'M5', 'market_open_calendar'],
            'no_trade' => ['opening_range_incomplete', 'htf_conflict', 'retest_failure'],
        ],
        [
            'id' => 'orb_vwap_reclaim',
            'label' => 'Opening Range + VWAP reclaim',
            'priority' => 11,
            'class' => 'session_reversal_playbook',
            'provenance_class' => 'market_structure_hypothesis',
            'roles' => ['direction' => 'session_context', 'structure' => 'opening_range_and_vwap', 'setup' => 'range_false_break', 'confirmation' => 'VWAP_reclaim_then_opposite_break', 'execution' => 'M5_retest'],
            'required_streams' => ['M15_or_M30', 'M5', 'session_vwap', 'market_open_calendar'],
            'no_trade' => ['opening_range_incomplete', 'vwap_flat', 'reclaim_missing'],
        ],
        [
            'id' => 'adaptive_timeframe_confirmation',
            'label' => 'Adaptive timeframe confirmation',
            'priority' => 12,
            'class' => 'policy_overlay',
            'provenance_class' => 'research_policy_hypothesis',
            'roles' => ['direction' => 'H4_H1_agreement', 'structure' => 'conflict_state', 'setup' => 'base_playbook_event', 'confirmation' => 'M5_when_aligned_or_M15_then_M5_when_conflicted', 'execution' => 'base_playbook_execution'],
            'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'no_trade' => ['base_playbook_missing', 'htf_conflict_without_M15_confirmation'],
        ],
        [
            'id' => 'confirmation_trend_continuation',
            'label' => 'Confirmation Matrix: Trend Continuation',
            'priority' => 13,
            'class' => 'entry_contract_playbook',
            'provenance_class' => 'confirmation_entry_framework_hypothesis',
            'roles' => ['direction' => 'H4_trend', 'location' => 'H1_pullback_POI', 'setup' => 'M15_sweep_reclaim', 'confirmation' => 'M5_MSS_displacement', 'execution' => 'declared_mode_trigger'],
            'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'no_trade' => ['htf_conflict', 'outside_pullback_location', 'confirmation_missing', 'reward_space_insufficient', 'chasing_veto'],
        ],
        [
            'id' => 'confirmation_breakout_retest',
            'label' => 'Confirmation Matrix: Breakout Retest',
            'priority' => 14,
            'class' => 'entry_contract_playbook',
            'provenance_class' => 'confirmation_entry_framework_hypothesis',
            'roles' => ['direction' => 'H4_H1_alignment', 'location' => 'closed_H1_boundary', 'setup' => 'close_outside_level', 'confirmation' => 'displacement_and_hold', 'execution' => 'retest_reaction'],
            'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'no_trade' => ['wick_only_break', 'break_not_held', 'retest_missing', 'reward_space_insufficient', 'chasing_veto'],
        ],
        [
            'id' => 'confirmation_false_break_reversal',
            'label' => 'Confirmation Matrix: False-Break Reversal',
            'priority' => 15,
            'class' => 'entry_contract_playbook',
            'provenance_class' => 'confirmation_entry_framework_hypothesis',
            'roles' => ['direction' => 'failed_continuation', 'location' => 'H1_range_edge', 'setup' => 'M15_sweep_reclaim', 'confirmation' => 'M5_MSS_displacement', 'execution' => 'retest_rejection'],
            'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'no_trade' => ['major_level_missing', 'reclaim_missing', 'MSS_missing', 'logical_invalidation_missing'],
        ],
        [
            'id' => 'confirmation_range_sweep',
            'label' => 'Confirmation Matrix: Range Sweep',
            'priority' => 16,
            'class' => 'entry_contract_playbook',
            'provenance_class' => 'confirmation_entry_framework_hypothesis',
            'roles' => ['direction' => 'range_edge_reversion', 'location' => 'H1_range_edge_not_middle', 'setup' => 'M15_sweep_reclaim', 'confirmation' => 'M5_shift', 'execution' => 'retest_reaction'],
            'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'no_trade' => ['range_unresolved', 'range_middle', 'sweep_missing', 'target_space_below_minimum'],
        ],
        [
            'id' => 'confirmation_htf_reversal',
            'label' => 'Confirmation Matrix: HTF Reversal',
            'priority' => 17,
            'class' => 'entry_contract_playbook',
            'provenance_class' => 'confirmation_entry_framework_hypothesis',
            'roles' => ['direction' => 'opposite_HTF_after_failure', 'location' => 'H1_major_edge', 'setup' => 'M15_sweep_reclaim', 'confirmation' => 'M5_structural_shift_and_displacement', 'execution' => 'retest_or_second_confirmation'],
            'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'no_trade' => ['strong_location_missing', 'failure_to_continue_missing', 'independent_confirmation_below_three', 'late_entry'],
        ],
    ];

    /** @return array<string,mixed> */
    public function catalogue(): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'models' => self::MODELS,
            'reusable_modules' => [
                'liquidity_sweep_reclaim', 'mss_or_choch', 'displacement', 'fvg_retest',
                'breaker_fvg_overlap_unicorn', 'crt_reference_range', 'session_window',
                'smt_divergence', 'vwap_reclaim', 'anchored_vwap', 'ote_retracement_zone',
                'ifvg_retest', 'wyckoff_phase', 'nested_execution',
                'close_outside_level', 'break_hold', 'rejection_or_engulfing_trigger',
                'independent_confirmation_count', 'logical_invalidation',
                'reward_space_gate', 'chasing_veto',
            ],
            'research_rules' => [
                'models_are_hypotheses_not_execution_authority',
                'one_playbook_plus_declared_modules_per_trial',
                'closed_candle_and_available_at_required',
                'same_manifest_and_costs_for_frozen_control',
                'shadow_only_until_existing_promotion_gates_pass',
                'setup_confirmation_and_trigger_are_distinct_events',
                'maximum_confirmation_is_not_the_objective_expectancy_is',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function model(string $id): array
    {
        foreach (self::MODELS as $model) {
            if ($model['id'] === $id) return $model;
        }

        throw new InvalidArgumentException("Unknown multi-timeframe research model: {$id}");
    }
}
