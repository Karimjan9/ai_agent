<?php

namespace App\Services;

/**
 * A small, auditable catalogue of strategy hypotheses used by the lab.
 *
 * The catalogue is deliberately descriptive.  A tactic is a research
 * contract, not a promotion shortcut: the same cost, holdout, forward,
 * statistical and paper gates still decide whether the hypothesis survives.
 */
class TacticCatalogueService
{
    public const PROTOCOL = 'audited_tactic_catalogue_v1';

    private const CATALOGUE = [
        'trend_pullback' => [
            'tactic_id' => 'trend_following_pullback',
            'label' => 'Trend following + ATR pullback',
            'hypothesis' => 'Follow persistent direction only after price returns near the fast trend average.',
            'target_regimes' => ['trend_up', 'trend_down'],
            'entry_topology' => 'ema_alignment_adx_pullback_rsi',
            'allowed_genes' => ['ema_fast', 'ema_slow', 'rsi_period', 'trend_strength_min', 'pullback_atr_fraction'],
        ],
        'trend_breakout_retest' => [
            'tactic_id' => 'trend_breakout_retest',
            'label' => 'Trend breakout retest',
            'hypothesis' => 'Trade continuation only when a closed candle breaks a prior range and the next candle retests it.',
            'target_regimes' => ['trend_up', 'trend_down'],
            'entry_topology' => 'ema_alignment_donchian_retest',
            'allowed_genes' => ['lookback', 'ema_fast', 'ema_slow', 'trend_strength_min'],
        ],
        'breakout_retest' => [
            'tactic_id' => 'donchian_atr_breakout_retest',
            'label' => 'Donchian/ATR breakout with retest',
            'hypothesis' => 'Require a range break with an ATR displacement and a retest before entry.',
            'target_regimes' => ['trend_up', 'trend_down', 'high_volatility'],
            'entry_topology' => 'donchian_atr_retest',
            'allowed_genes' => ['lookback', 'atr_period', 'atr_multiplier', 'retest_required', 'trend_strength_min'],
        ],
        'breakout_continuation' => [
            'tactic_id' => 'donchian_atr_breakout_continuation',
            'label' => 'Donchian/ATR breakout continuation',
            'hypothesis' => 'Trade confirmed range expansion while keeping the entry and risk envelope frozen.',
            'target_regimes' => ['trend_up', 'trend_down', 'high_volatility'],
            'entry_topology' => 'donchian_atr_confirmation',
            'allowed_genes' => ['lookback', 'atr_period', 'atr_multiplier', 'confirmation_candles', 'trend_strength_min'],
        ],
        'volatility_compression_expansion' => [
            'tactic_id' => 'atr_squeeze_expansion',
            'label' => 'ATR compression to expansion',
            'hypothesis' => 'Wait for a compressed volatility state and enter only on measured expansion.',
            'target_regimes' => ['trend_up', 'trend_down', 'high_volatility'],
            'entry_topology' => 'atr_baseline_compression_expansion',
            'allowed_genes' => ['atr_period', 'atr_threshold', 'lookback', 'compression_ratio', 'expansion_multiplier'],
        ],
        'volatility_breakout' => [
            'tactic_id' => 'atr_squeeze_donchian_expansion',
            'label' => 'ATR squeeze + Donchian expansion',
            'hypothesis' => 'Filter volatility expansion with a prior-range break to reduce false expansion candles.',
            'target_regimes' => ['trend_up', 'trend_down', 'high_volatility'],
            'entry_topology' => 'atr_compression_donchian_breakout',
            'allowed_genes' => ['atr_period', 'atr_threshold', 'lookback', 'compression_ratio', 'expansion_multiplier'],
        ],
        'range_mean_reversion' => [
            'tactic_id' => 'bollinger_zscore_reentry',
            'label' => 'Bollinger/z-score range re-entry',
            'hypothesis' => 'Fade a statistically stretched range move only after price re-enters the band.',
            'target_regimes' => ['range', 'low_volatility'],
            'entry_topology' => 'rolling_mean_std_reentry',
            'allowed_genes' => ['lookback', 'deviation', 'adx_max', 'low_volatility_only'],
        ],
        'range_rsi_reversion' => [
            'tactic_id' => 'bollinger_rsi_reentry',
            'label' => 'Bollinger/z-score + RSI re-entry',
            'hypothesis' => 'Require both range re-entry and an independent RSI exhaustion confirmation.',
            'target_regimes' => ['range', 'low_volatility'],
            'entry_topology' => 'rolling_mean_std_rsi_reentry',
            'allowed_genes' => ['lookback', 'deviation', 'rsi_period', 'adx_max', 'low_volatility_only'],
        ],
        'session_breakout' => [
            'tactic_id' => 'session_range_breakout',
            'label' => 'Session range breakout',
            'hypothesis' => 'Trade a closed prior range break only inside the declared session window.',
            'target_regimes' => ['trend_up', 'trend_down', 'high_volatility'],
            'entry_topology' => 'session_donchian_breakout',
            'allowed_genes' => ['session_start', 'session_end', 'lookback'],
        ],
        'session_mean_reversion' => [
            'tactic_id' => 'session_range_reversion',
            'label' => 'Session range reversion',
            'hypothesis' => 'Take only band re-entry signals inside a fixed liquid session window.',
            'target_regimes' => ['range', 'low_volatility'],
            'entry_topology' => 'session_mean_std_reentry',
            'allowed_genes' => ['session_start', 'session_end', 'lookback'],
        ],
        'momentum_continuation' => [
            'tactic_id' => 'time_series_momentum',
            'label' => 'Time-series momentum continuation',
            'hypothesis' => 'Use signed recent return and an EMA direction filter to follow persistence.',
            'target_regimes' => ['trend_up', 'trend_down'],
            'entry_topology' => 'roc_ema_continuation',
            'allowed_genes' => ['roc_period', 'roc_threshold', 'ema_period'],
        ],
        'momentum_pullback' => [
            'tactic_id' => 'time_series_momentum_pullback',
            'label' => 'Time-series momentum + ATR pullback',
            'hypothesis' => 'Keep the momentum direction but avoid entering after excessive extension from the EMA.',
            'target_regimes' => ['trend_up', 'trend_down'],
            'entry_topology' => 'roc_ema_atr_pullback',
            'allowed_genes' => ['roc_period', 'roc_threshold', 'ema_period'],
        ],
        'regime_router' => [
            'tactic_id' => 'regime_adaptive_router',
            'label' => 'Regime-adaptive specialist router',
            'hypothesis' => 'Assign trend, breakout and range tactics to the current closed regime.',
            'target_regimes' => ['trend_up', 'trend_down', 'range', 'high_volatility'],
            'entry_topology' => 'regime_owned_specialists',
            'allowed_genes' => ['trend_weight', 'breakout_weight', 'mean_reversion_weight', 'minimum_confidence', 'high_volatility_wait'],
        ],
        'regime_consensus' => [
            'tactic_id' => 'regime_consensus_router',
            'label' => 'Regime router with independent consensus',
            'hypothesis' => 'Require agreement between independent signal families when the regime is uncertain.',
            'target_regimes' => ['unknown', 'transition'],
            'entry_topology' => 'regime_owned_consensus',
            'allowed_genes' => ['trend_weight', 'breakout_weight', 'mean_reversion_weight', 'minimum_confidence'],
        ],
        'frozen_regime_specialist_ensemble' => [
            'tactic_id' => 'frozen_regime_specialist_ensemble',
            'label' => 'Frozen regime specialist ensemble',
            'hypothesis' => 'Give each regime and volatility state one pre-declared specialist owner.',
            'target_regimes' => ['trend_up', 'trend_down', 'range', 'high_volatility'],
            'entry_topology' => 'exclusive_regime_specialist_ownership',
            'allowed_genes' => ['atr_period', 'lookback', 'trend_strength_min', 'pullback_atr_fraction'],
        ],
        'frozen_parent_differential_router' => [
            'tactic_id' => 'differential_regime_specialist',
            'label' => 'Frozen-parent differential specialist',
            'hypothesis' => 'Change one declared regime lane while copying the parent everywhere else.',
            'target_regimes' => ['trend_up', 'trend_down', 'range'],
            'entry_topology' => 'paired_non_target_parent_freeze',
            // The shadow architecture lane changes the executable entry
            // topology as one causal gene.  Keep it explicit here so the
            // tactic contract records structural mutations as legal research
            // hypotheses rather than rejecting them as undeclared repairs.
            'allowed_genes' => ['differential_target_min_signal_confidence', 'trend_up_strength_min', 'trend_down_strength_min', 'trend_up_roc_threshold', 'trend_down_roc_threshold', 'range_deviation', 'entry_topology_variant'],
        ],
    ];

    private const FAILURE_REPAIR_GENES = [
        // Temporal survival is a first-class failure lane.  The finite
        // state-machine escape is deliberately listed beside the bounded
        // scalar abstention genes so its single causal change is admitted by
        // the tactic contract instead of being mistaken for an undeclared
        // architecture mutation.
        'temporal_stability' => [
            'max_loss_streak_before_wait', 'loss_cooldown_candles', 'loss_streak_wait_candles',
            'weak_regime_wait_candles', 'state_machine_variant', 'signal_max_age_candles',
            'signal_decay_half_life_candles', 'temporal_drift_zscore_max',
        ],
        'monthly_survival' => ['session_filter_enabled', 'session_start', 'session_end', 'transition_firewall_enabled', 'transition_wait_candles', 'time_stop_candles'],
        'regime_coverage' => [
            'trend_strength_min', 'lookback', 'high_volatility_risk_multiplier',
            'minimum_signal_confidence', 'volume_lane', 'max_spread_atr_ratio',
        ],
        'robustness' => [
            'confidence_calibration_min_samples', 'weak_regime_min_samples',
            'meta_label_min_history', 'cooldown_shadow_min_samples',
            'weak_regime_wait_candles',
        ],
        'volatility_session_stability' => ['session_filter_enabled', 'session_start', 'session_end', 'high_volatility_risk_multiplier', 'avoid_high_volatility'],
        'exit_topology' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction'],
        'transition_firewall' => ['transition_firewall_enabled', 'transition_wait_candles', 'high_volatility_risk_multiplier'],
        'opportunity_recall' => ['minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles', 'transition_wait_candles'],
        'stress_cost' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'max_spread_atr_ratio'],
        'portfolio_router' => ['differential_target_regime', 'differential_target_min_signal_confidence', 'minimum_signal_confidence'],
        'unknown_state_curiosity' => ['minimum_signal_confidence', 'minimum_confidence', 'transition_firewall_enabled', 'transition_wait_candles'],
    ];

    public function for(string $family, string $architecture, ?string $target = null): array
    {
        $canonicalArchitecture = match (true) {
            $family === 'differential_router' && in_array($architecture, ['regime_router', 'regime_consensus'], true) => 'frozen_parent_differential_router',
            in_array($architecture, ['frozen_parent_differential_trend_down_v1', 'frozen_parent_differential_range_v2'], true) => 'frozen_parent_differential_router',
            $architecture === 'frozen_regime_specialist_ensemble_v2' => 'frozen_regime_specialist_ensemble',
            default => $architecture,
        };
        $entry = self::CATALOGUE[$canonicalArchitecture] ?? [
            'tactic_id' => $architecture,
            'label' => ucfirst(str_replace('_', ' ', $architecture)),
            'hypothesis' => 'Bounded strategy-family hypothesis; implementation must be validated by the full evidence protocol.',
            'target_regimes' => [],
            'entry_topology' => 'declared_family_runtime',
            'allowed_genes' => [],
        ];
        $failureLanes = array_keys(self::FAILURE_REPAIR_GENES);
        if ($target !== null && $target !== '' && ! in_array($target, $failureLanes, true)) {
            $failureLanes[] = $target;
        }

        return [
            'protocol' => self::PROTOCOL,
            'tactic_id' => (string) $entry['tactic_id'],
            'label' => (string) $entry['label'],
            'family' => $family,
            'architecture' => $architecture,
            'control_root' => app(ControlRootCatalogueService::class)->for($family, $architecture),
            'hypothesis' => (string) $entry['hypothesis'],
            'target_regimes' => array_values((array) $entry['target_regimes']),
            'entry_topology' => (string) $entry['entry_topology'],
            'allowed_genes' => array_values(array_unique(array_merge(
                (array) $entry['allowed_genes'],
                [
                    'atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier',
                    'time_stop_candles', 'minimum_signal_confidence',
                    'entry_topology_variant', 'state_machine_variant', 'regime_classifier_variant',
                ],
            ))),
            'repair_lanes' => $failureLanes,
            'repair_genes_by_lane' => self::FAILURE_REPAIR_GENES,
            'one_gene_rule' => 'A repair may change one declared gene; all other signal and execution lanes remain frozen.',
            'evidence_required' => ['same_data_manifest', 'same_execution_contract', 'paired_replay', 'no_regression_contract', 'parameter_plateau', 'independent_forward_windows'],
            'promotion_evidence' => false,
            'research_only_until_passed' => true,
        ];
    }

    public function alignment(array $contract, ?string $target, ?string $changedGene, bool $controlOnly = false): array
    {
        if ($changedGene === null || $changedGene === '') {
            return [
                'status' => $controlOnly ? 'passed' : 'failed',
                'target' => $target,
                'changed_gene' => null,
                'gene_allowed' => $controlOnly,
                'reason' => $controlOnly ? 'explicit_no_change_control' : 'no_single_changed_gene',
            ];
        }
        if ($changedGene === '__architecture') {
            return [
                'status' => 'passed',
                'target' => $target,
                'changed_gene' => $changedGene,
                'gene_allowed' => true,
                'reason' => 'declared_structural_architecture_hypothesis',
            ];
        }
        $allowed = (array) data_get($contract, 'allowed_genes', []);
        $laneGenes = (array) data_get($contract, "repair_genes_by_lane.{$target}", []);
        $geneAllowed = in_array($changedGene, $allowed, true)
            || in_array($changedGene, $laneGenes, true);

        return [
            'status' => $geneAllowed ? 'passed' : 'failed',
            'target' => $target,
            'changed_gene' => $changedGene,
            'gene_allowed' => $geneAllowed,
            'reason' => $geneAllowed ? 'declared_tactic_gene' : 'gene_not_declared_for_tactic',
        ];
    }
}
