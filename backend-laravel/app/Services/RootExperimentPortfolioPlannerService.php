<?php

namespace App\Services;

use App\Models\AiLaboratory;

/** Owns the frozen root portfolio; construction and admission remain with LabPopulationService. */
class RootExperimentPortfolioPlannerService
{
    public const ROOT_EXPERIMENT_PORTFOLIO_PROTOCOL = LabPopulationService::ROOT_EXPERIMENT_PORTFOLIO_PROTOCOL;

    /**
     * A root data audit has no parent, but it is still an experiment.  This
     * pre-registered portfolio prevents a new foundation cohort from filling
     * its seats with the same cooldown/threshold mutation.
     *
     * @return array{plan: array<int, array<string, mixed>>, contract: array<string, mixed>}
     */
    public function plan(array $plan, AiLaboratory $lab): array
    {
        $historicalAtlas = app(HistoricalResearchAtlasService::class)->summarize($lab);
        // G83 proved that a broad historical replay is not enough when the
        // search space is almost entirely hybrid-router vetoes.  In the
        // current XAUUSD archive every valid challenger is hybrid, so a
        // planner that only mutates that family is unable to discover a
        // genuinely different entry mechanism.  This portfolio deliberately
        // reserves one exact frozen control for each executable family, then
        // tests a small number of falsifiable tactics inside each family.
        // It contains no generic cooldown/session-wait seat: abstention is a
        // result to measure, never the default repair for a weak edge.
        $seats = [
            ['lane' => 'frozen_control', 'family' => 'hybrid', 'control' => true],
            ['lane' => 'frozen_control', 'family' => 'differential_router', 'control' => true],
            ['lane' => 'frozen_control', 'family' => 'trend', 'control' => true],
            ['lane' => 'frozen_control', 'family' => 'mean_reversion', 'control' => true],
            ['lane' => 'frozen_control', 'family' => 'volatility', 'control' => true],

            ['lane' => 'confidence_funnel', 'family' => 'differential_router', 'gene' => 'minimum_confidence', 'value' => .75],
            ['lane' => 'confidence_funnel', 'family' => 'differential_router', 'gene' => 'minimum_confidence', 'value' => .50],

            // The sealed differential router v2 uses the target lane's
            // ROC/EMA envelope, not `trend_down_strength_min`.  Use a v2
            // execution gene so the SELL hypothesis changes decisions
            // instead of producing an expensive no-op replay.
            ['lane' => 'directional_asymmetry', 'family' => 'differential_router', 'gene' => 'trend_down_roc_threshold', 'value' => .30],
            // `range_signal_mode` is executable in the mean-reversion
            // topology. It must not be placed on the default trend-down
            // differential router, which only replaces its declared trend
            // lane and would therefore turn this into a no-op.
            ['lane' => 'directional_asymmetry', 'family' => 'mean_reversion', 'gene' => 'range_signal_mode', 'value' => 'inverse_extreme', 'structural' => true],

            ['lane' => 'trend_tactic', 'family' => 'trend', 'gene' => 'pullback_atr_fraction', 'value' => .50, 'structural' => true],
            ['lane' => 'trend_tactic', 'family' => 'trend', 'gene' => 'trend_strength_min', 'value' => 24],

            ['lane' => 'range_tactic', 'family' => 'mean_reversion', 'gene' => 'deviation', 'value' => 1.5, 'structural' => true],
            ['lane' => 'range_tactic', 'family' => 'mean_reversion', 'gene' => 'adx_max', 'value' => 16],

            ['lane' => 'breakout_tactic', 'family' => 'volatility', 'gene' => 'compression_ratio', 'value' => .65, 'structural' => true],
            ['lane' => 'breakout_tactic', 'family' => 'volatility', 'gene' => 'expansion_multiplier', 'value' => 1.4],

            // A root cohort has not proved economic edge yet. Spending two
            // seats on stop/exit shaping at this point recreates the old
            // failure mode: optimise the loss profile of a losing baseline.
            // Use the same budget for executable confirmation/entry quality
            // ablations; risk and management remain locked behind Edge
            // attribution by DependencyAwareEdgeGenesisFoundryService.
            ['lane' => 'confirmation_entry', 'family' => 'hybrid', 'gene' => 'minimum_signal_confidence', 'value' => .50],
            ['lane' => 'confirmation_entry', 'family' => 'hybrid', 'gene' => 'breakout_atr_threshold', 'value' => 1.0],

            ['lane' => 'regime_topology', 'family' => 'hybrid', 'gene' => 'entry_topology_variant', 'value' => 'trend_regime_confirmation_v1', 'structural' => true],
            ['lane' => 'regime_topology', 'family' => 'hybrid', 'gene' => 'state_machine_variant', 'value' => 'neutral_transition_cooldown_reentry_v1', 'structural' => true],
            ['lane' => 'regime_topology', 'family' => 'differential_router', 'gene' => 'regime_classifier_variant', 'value' => 'ema_slope_consensus_v1', 'structural' => true],
        ];
        foreach ($seats as $index => $seat) {
            if (! isset($plan[$index])) {
                break;
            }
            // `evolution_mode` lives on the outer planner slot. Leaving a
            // provisional frozen-control mode here lets the later pairer
            // re-role an otherwise sealed regime/topology candidate.
            // Root seats own their role explicitly, both inside the niche
            // and at the outer compiler boundary.
            $plan[$index]['evolution_mode'] = (bool) ($seat['control'] ?? false)
                ? 'frozen_control'
                : 'root_portfolio';
            $plan[$index]['family'] = $seat['family'];
            $plan[$index]['target'] = match ($seat['lane']) {
                'confidence_funnel', 'trend_tactic', 'breakout_tactic', 'confirmation_entry' => 'opportunity_recall',
                'stress_exit' => 'stress_cost',
                'regime_topology', 'directional_asymmetry', 'range_tactic' => 'regime_coverage',
                default => 'profit_factor',
            };
            // This is a sealed root experiment, not an adapted continuation
            // of the planner's provisional seat. Do not carry a shadow,
            // role, architecture or historical-novelty flag across: those
            // flags can invoke a second compiler after the exact ablation.
            $niche = [
                'root_experiment_portfolio' => true,
                'experiment_lane' => $seat['lane'],
                'pre_registered_hypothesis' => true,
                'hypothesis_protocol' => self::ROOT_EXPERIMENT_PORTFOLIO_PROTOCOL,
                'hypothesis' => $this->rootHypothesisForSeat($seat),
                'hypothesis_family' => $this->rootHypothesisFamilyForSeat($seat),
                'behavioral_falsification' => $this->rootBehavioralFalsificationForSeat($seat),
                'control_only' => (bool) ($seat['control'] ?? false),
                'structural_research' => (bool) ($seat['structural'] ?? false),
                // This portfolio is a price-history causal experiment. A
                // stale volume-shadow label would manufacture an unpaired
                // execution lane before the volume specialist has evidence.
                'data_lane' => 'price',
                'volume_shadow' => false,
            ];
            $niche['parameters'] = [
                ...((array) data_get($niche, 'parameters', [])),
                'volume_lane' => 'none',
            ];
            if ((bool) ($seat['control'] ?? false)) {
                unset($niche['declared_gene'], $niche['declared_value']);
            } else {
                $niche['declared_gene'] = $seat['gene'];
                $niche['declared_value'] = $seat['value'];
                $niche[$seat['gene']] = $seat['value'];
            }
            $plan[$index]['niche'] = $niche;
        }

        return [
            'plan' => array_values($plan),
            'contract' => [
                'protocol' => self::ROOT_EXPERIMENT_PORTFOLIO_PROTOCOL,
                'controls' => 10,
                'candidates' => 10,
                'confidence_funnel_ablations' => 2,
                'directional_asymmetry_hypotheses' => 2,
                'trend_tactic_hypotheses' => 2,
                'range_tactic_hypotheses' => 2,
                'breakout_tactic_hypotheses' => 2,
                'confirmation_entry_hypotheses' => 2,
                'regime_topology_hypotheses' => 3,
                'maximum_identical_mutation_replicates' => 2,
                'maximum_seats_per_hypothesis_family' => 1,
                'minimum_independent_candidate_hypothesis_families' => 10,
                'exact_same_generation_control_required' => true,
                'pre_pairing_then_exact_candidate_invariant' => true,
                'behavioral_axes' => [
                    'raw_signal_to_accepted_entry_funnel', 'accepted_trade_set', 'exit_state',
                    'regime_coverage', 'stress_cost', 'session_or_transition_veto',
                ],
                'historical_research_atlas' => $historicalAtlas,
                'historical_archive_policy' => [
                    'underexplored_families_forced_into_portfolio' => ['trend', 'mean_reversion', 'volatility'],
                    'archive_records_are_hypothesis_evidence_not_parent_passports' => true,
                ],
                'promotion_evidence' => false,
            ],
        ];
    }

    /** @param array<string, mixed> $seat */
    private function rootHypothesisForSeat(array $seat): string
    {
        if ((bool) ($seat['control'] ?? false)) {
            return 'Frozen executable baseline for exact same-generation causal comparison.';
        }

        return 'Changing only '.$seat['gene'].' to '.json_encode($seat['value'])
            .' must change the declared behavioural mechanism without relaxing any screening gate.';
    }

    /**
     * A lane is too broad to be an experiment budget.  This stable family
     * identifier is intentionally more specific than the changed parameter:
     * two confidence-threshold directions are one opposing ablation family,
     * whereas confidence calibration and the EV veto are separate questions.
     */
    private function rootHypothesisFamilyForSeat(array $seat): string
    {
        if ((bool) ($seat['control'] ?? false)) {
            return 'frozen_baseline:'.(string) ($seat['family'] ?? 'unknown');
        }

        return match ((string) ($seat['gene'] ?? '')) {
            'minimum_confidence' => 'confidence_threshold_curve',
            'confidence_calibration_enabled' => 'confidence_calibration',
            'confidence_ev_lower_bound_enabled' => 'ev_lower_bound_veto',
            'atr_stop_multiplier' => 'stop_width',
            'atr_target_multiplier' => 'target_distance',
            'time_stop_candles' => 'time_exit',
            'high_volatility_risk_multiplier' => 'volatility_risk_sizing',
            'trend_strength_min' => 'trend_strength_admission',
            'pullback_atr_fraction' => 'trend_pullback_timing',
            'deviation' => 'range_extreme_distance',
            'adx_max' => 'range_regime_boundary',
            'compression_ratio' => 'breakout_compression_structure',
            'expansion_multiplier' => 'breakout_expansion_followthrough',
            'range_signal_mode' => 'range_directional_topology',
            'entry_topology_variant' => 'entry_topology',
            'state_machine_variant' => 'entry_state_machine',
            'regime_classifier_variant' => 'regime_classifier',
            'trend_down_strength_min' => 'directional_regime_threshold',
            'trend_down_roc_threshold' => 'directional_regime_momentum_threshold',
            'minimum_signal_confidence' => 'entry_confidence_floor',
            'breakout_atr_threshold' => 'breakout_trigger_threshold',
            'session_filter_enabled' => 'session_veto',
            'high_volatility_wait' => 'volatility_wait_veto',
            'transition_firewall_enabled' => 'transition_veto',
            'dynamic_cooldown_enabled' => 'cooldown_state_machine',
            'adaptive_signal_expiry_enabled' => 'signal_expiry_state_machine',
            default => 'declared_gene:'.(string) ($seat['gene'] ?? 'unknown'),
        };
    }

    /** @param array<string, mixed> $seat @return array<string, mixed> */
    private function rootBehavioralFalsificationForSeat(array $seat): array
    {
        $axes = match ((string) ($seat['lane'] ?? '')) {
            'confidence_funnel' => ['raw_signal_to_accepted_entry_funnel', 'confidence_veto', 'accepted_trade_set'],
            'confirmation_entry' => ['confirmation_breadth', 'entry_location_quality', 'accepted_trade_set'],
            'stress_exit' => ['exit_state', 'holding_time_distribution', 'stress_cost'],
            'regime_topology' => ['entry_topology', 'regime_coverage', 'accepted_trade_set'],
            'calendar_session' => ['session_or_transition_veto', 'accepted_trade_set', 'calendar_distribution'],
            'architecture_novelty' => ['entry_or_exit_state', 'accepted_trade_set', 'stress_or_temporal_behavior'],
            default => ['frozen_baseline'],
        };

        return [
            'protocol' => 'root_behavioral_falsification_v1',
            'axes' => $axes,
            'expected_change' => $this->rootExpectedBehavioralChange($seat),
            'falsified_when' => 'The paired replay has no material change in the declared axes after adequate sample coverage.',
            'required_pair_comparison' => true,
            'promotion_evidence' => false,
        ];
    }

    /** @param array<string, mixed> $seat @return array<string, mixed> */
    private function rootExpectedBehavioralChange(array $seat): array
    {
        return match ((string) ($seat['gene'] ?? '')) {
            'minimum_confidence' => [
                'raw_signal' => 'unchanged', 'veto_channel' => 'confidence',
                'veto_direction' => 'decrease', 'accepted_entries' => 'increase_or_same',
            ],
            'confidence_calibration_enabled' => [
                'raw_signal' => 'unchanged', 'veto_channel' => 'confidence',
                'accepted_entries' => 'change',
            ],
            'confidence_ev_lower_bound_enabled' => [
                'raw_signal' => 'unchanged', 'veto_channel' => 'ev',
                'veto_direction' => 'decrease', 'accepted_entries' => 'increase_or_same',
            ],
            'atr_stop_multiplier', 'atr_target_multiplier', 'time_stop_candles' => [
                'accepted_entries' => 'unchanged_or_near', 'exit_state' => 'change',
                'holding_time_distribution' => 'change', 'stress_cost' => 'change',
            ],
            'high_volatility_risk_multiplier' => [
                'accepted_entries' => 'unchanged_or_near', 'stress_cost' => 'change',
                'trade_loss_profile' => 'change',
            ],
            'trend_strength_min', 'pullback_atr_fraction' => [
                'entry_topology' => 'change', 'accepted_trade_set' => 'change',
                'regime_coverage' => 'change',
            ],
            'deviation', 'adx_max', 'range_signal_mode' => [
                'entry_topology' => 'change', 'accepted_trade_set' => 'change',
                'regime_coverage' => 'change',
            ],
            'compression_ratio', 'expansion_multiplier' => [
                'entry_topology' => 'change', 'accepted_trade_set' => 'change',
                'raw_signal' => 'change',
            ],
            'minimum_signal_confidence' => [
                'confidence_veto' => 'change', 'accepted_trade_set' => 'change',
                'false_entry_rate' => 'change',
            ],
            'breakout_atr_threshold' => [
                'breakout_trigger' => 'change', 'accepted_trade_set' => 'change',
                'opportunity_recall' => 'change',
            ],
            'session_filter_enabled', 'high_volatility_wait', 'transition_firewall_enabled' => [
                'veto_channel' => 'regime', 'accepted_entries' => 'change',
                'calendar_or_transition_distribution' => 'change',
            ],
            'entry_topology_variant', 'state_machine_variant', 'regime_classifier_variant', 'trend_down_strength_min', 'trend_down_roc_threshold' => [
                'regime_coverage' => 'change', 'entry_topology' => 'change',
                'accepted_trade_set' => 'change',
            ],
            'dynamic_cooldown_enabled', 'adaptive_signal_expiry_enabled' => [
                'entry_or_exit_state' => 'change', 'accepted_trade_set' => 'change',
                'stress_or_temporal_behavior' => 'change',
            ],
            default => ['declared_behavior' => 'change'],
        };
    }

    /**
     * One laboratory has one evidence stream.  A new generation may not be
     * created while the previous stream is still producing screening or full
     * validation evidence, even when an operator supplies --force.
     */
}
