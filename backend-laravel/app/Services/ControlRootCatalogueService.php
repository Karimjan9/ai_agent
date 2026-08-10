<?php

namespace App\Services;

/** Explainable research roots; children may change one declared gene only. */
class ControlRootCatalogueService
{
    public const PROTOCOL = 'explainable_control_root_v1';

    /** @return array<string, mixed> */
    public function for(string $family, string $architecture): array
    {
        $root = match (true) {
            in_array($family, ['trend', 'momentum'], true) => 'volatility_scaled_time_series_momentum',
            in_array($family, ['breakout', 'volatility'], true) => 'multi_horizon_atr_breakout',
            in_array($family, ['mean_reversion', 'session'], true) => 'low_volatility_range_mean_reversion',
            in_array($family, ['hybrid', 'regime_ensemble', 'differential_router'], true) => 'regime_router_with_wait',
            default => 'conservative_atr_time_stop_wait',
        };

        $genes = match ($root) {
            'volatility_scaled_time_series_momentum' => ['roc_period', 'roc_threshold', 'ema_period', 'atr_stop_multiplier', 'atr_target_multiplier', 'time_stop_candles'],
            'multi_horizon_atr_breakout' => ['lookback', 'confirmation_candles', 'atr_period', 'atr_multiplier', 'atr_stop_multiplier', 'atr_target_multiplier', 'time_stop_candles'],
            'low_volatility_range_mean_reversion' => ['lookback', 'deviation', 'adx_max', 'low_volatility_only', 'atr_stop_multiplier', 'time_stop_candles'],
            'regime_router_with_wait' => ['trend_weight', 'breakout_weight', 'mean_reversion_weight', 'minimum_confidence', 'high_volatility_wait', 'transition_firewall_enabled'],
            default => ['atr_stop_multiplier', 'atr_target_multiplier', 'time_stop_candles', 'minimum_signal_confidence'],
        };

        return [
            'protocol' => self::PROTOCOL,
            'root_id' => $root,
            'family' => $family,
            'architecture' => $architecture,
            'baseline_components' => [
                'volatility_scaling' => true,
                'multi_horizon_confirmation' => str_contains($root, 'momentum') || str_contains($root, 'breakout'),
                'atr_stop_target' => true,
                'time_stop' => true,
                'low_volatility_filter' => str_contains($root, 'range'),
                'regime_router' => str_contains($root, 'router'),
                'wait_state' => true,
            ],
            'allowed_mutation_genes' => $genes,
            'one_gene_rule' => 'Only one control-root gene may change per repair attempt; signal family and execution contract remain frozen.',
            'rl_signal_authority' => false,
            'llm_gate_authority' => false,
            'rl_allowed_after' => 'paper_only_position_sizing_or_execution',
            'promotion_evidence' => false,
        ];
    }
}
