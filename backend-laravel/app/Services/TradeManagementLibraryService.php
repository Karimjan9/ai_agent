<?php

namespace App\Services;

/** Canonical, risk-owned tactic profiles for paper execution. */
class TradeManagementLibraryService
{
    public const PROTOCOL = 'trade_management_library_v1';

    /** @return array<string,array<string,mixed>> */
    public function library(): array
    {
        return [
            'parameter_preserving_research' => ['entry' => ['single_confirmation_entry' => 1],
                'profit' => ['owner' => 'sealed_runtime_parameters'],
                'stop' => ['owner' => 'sealed_runtime_parameters'],
                'exit' => ['owner' => 'sealed_runtime_parameters'], 'research_only' => true],
            'balanced_professional' => ['entry' => ['confirmation_entry' => .5, 'retest_confirmation_add' => .3, 'structure_confirmation_add' => .2], 'profit' => ['tp_ladder_r' => [['r' => 1, 'close_fraction' => .4], ['r' => 2, 'close_fraction' => .3]], 'runner_fraction' => .3], 'stop' => ['breakeven_after' => 'tp1_or_structure', 'trail' => 'atr_or_structure'], 'exit' => ['time_stop' => true, 'news_exit' => true]],
            'range_fixed_target' => ['entry' => ['single_confirmation_entry' => 1], 'profit' => ['fixed_target_r' => 1.25, 'close_fraction' => 1], 'stop' => ['breakeven_after' => 'none'], 'exit' => ['time_stop' => true, 'session_exit' => true]],
            'structure_runner' => ['profit' => ['tp_ladder_r' => [['r' => 1, 'close_fraction' => .25]], 'runner_fraction' => .75], 'stop' => ['breakeven_after' => 'confirmed_m5_structure', 'trail' => 'm5_then_m15_structure'], 'exit' => ['time_stop' => true, 'cost_aware_exit' => true]],
            'breakout_measured_move' => ['profit' => ['tp_ladder_r' => [['r' => 1, 'close_fraction' => .4]], 'target' => 'measured_move', 'runner_fraction' => .6], 'stop' => ['breakeven_after' => 'retest_hold', 'trail' => 'm5_structure'], 'exit' => ['time_stop' => true]],
            'reversal_reduced_risk' => ['profit' => ['tp_ladder_r' => [['r' => 1, 'close_fraction' => .5]], 'runner_fraction' => .5], 'stop' => ['breakeven_after' => 'opposite_liquidity_reaction', 'trail' => 'm5_structure'], 'exit' => ['volatility_exit' => true, 'time_stop' => true]],
            'session_orb' => ['profit' => ['target' => 'opening_range_multiple', 'runner_fraction' => .4], 'stop' => ['trail' => 'm5_structure'], 'exit' => ['session_exit' => true, 'news_exit' => true, 'time_stop' => true]],
        ];
    }

    /** @return array<string,mixed> */
    public function compile(string $profile = 'balanced_professional', string $regime = 'trend'): array
    {
        $profiles = $this->library();
        if (! array_key_exists($profile, $profiles)) {
            throw new \InvalidArgumentException("Unknown trade-management profile: {$profile}");
        }
        $plan = $profiles[$profile];

        return ['protocol' => self::PROTOCOL, 'profile' => $profile, 'requested_regime' => $regime, 'state' => 'NEW', 'plan' => $plan, 'runtime_adapter' => $this->runtimeAdapter($profile), 'management_timeframe_escalation' => ['initial' => 'execution_timeframe', 'after_1R' => 'invalidation_timeframe', 'after_2R' => 'setup_timeframe', 'final_target' => 'bias_timeframe'], 'basket' => ['total_risk_must_not_increase' => true, 'weighted_average_entry' => true, 'max_open_heat_owned_by' => 'risk_sentinel', 'winner_only_pyramiding_shadow' => true], 'state_machine' => ['NEW', 'ARMED', 'LOCATE', 'TRIGGERED', 'CONFIRMED', 'RISK_APPROVED', 'OPEN', 'MANAGE', 'REDUCE_ONLY', 'CLOSED', 'REVIEW'], 'forbidden' => ['averaging_down', 'grid', 'martingale', 'soft_martingale', 'capped_martingale', 'loser_add'], 'promotion_contract' => ['paired_control' => true, 'same_execution_hash' => true, 'independent_windows' => 3], 'promotion_evidence' => false];
    }

    /**
     * Concrete replay adapter for the abstract management library. Values are
     * expressed in initial-risk (R) and replay candles, so Python can bind
     * the frozen profile without guessing from its label or changing the risk
     * governor's parameter gene.
     *
     * @return array<string,mixed>|null
     */
    public function runtimeAdapter(string $profile): ?array
    {
        if ($profile === 'parameter_preserving_research') {
            return [
                'protocol' => 'trade_management_runtime_adapter_v1',
                'profile' => $profile,
                'unit' => 'sealed_runtime_parameters',
                'engine' => 'parameter_preserving_replay_v1',
                'implementation_status' => 'existing_parameter_owned_position_lifecycle',
                'parameter_owners' => ['atr_stop_multiplier', 'atr_target_multiplier',
                    'partial_take_profit_fraction', 'partial_target_atr_multiplier',
                    'trailing_atr_multiplier', 'time_stop_candles'],
                'overrides' => [],
                'paper_execution_authority' => false,
                'promotion_evidence' => false,
            ];
        }
        $spec = match ($profile) {
            'balanced_professional' => [
                'partial_close_fraction' => .4, 'partial_target_r' => 1.0,
                'final_target_r' => 2.0, 'trailing_atr_multiplier' => 1.5,
                'time_stop_replay_candles' => 24,
            ],
            'range_fixed_target' => [
                'partial_close_fraction' => 0.0, 'partial_target_r' => null,
                'final_target_r' => 1.25, 'trailing_atr_multiplier' => 0.0,
                'time_stop_replay_candles' => 12,
            ],
            'structure_runner' => [
                'partial_close_fraction' => .25, 'partial_target_r' => 1.0,
                'final_target_r' => 3.0, 'trailing_atr_multiplier' => 1.25,
                'time_stop_replay_candles' => 48,
            ],
            'breakout_measured_move' => [
                'partial_close_fraction' => .4, 'partial_target_r' => 1.0,
                'final_target_r' => 2.0, 'trailing_atr_multiplier' => 1.0,
                'time_stop_replay_candles' => 24,
            ],
            'reversal_reduced_risk' => [
                'partial_close_fraction' => .5, 'partial_target_r' => 1.0,
                'final_target_r' => 2.0, 'trailing_atr_multiplier' => 1.0,
                'time_stop_replay_candles' => 24,
            ],
            'session_orb' => [
                'partial_close_fraction' => .6, 'partial_target_r' => 1.0,
                'final_target_r' => 2.0, 'trailing_atr_multiplier' => 1.0,
                'time_stop_replay_candles' => 12,
            ],
            default => null,
        };
        if ($spec === null) {
            return null;
        }

        return [
            'protocol' => 'trade_management_runtime_adapter_v1',
            'profile' => $profile,
            'unit' => 'initial_risk_and_replay_candles',
            'engine' => 'single_partial_runner_v1',
            'implementation_status' => 'simplified_profile_adapter',
            'implemented_features' => ['single_entry', 'single_partial_close', 'fixed_R_target', 'ATR_trailing', 'time_stop'],
            'not_implemented_by_this_adapter' => ['winner_pyramiding', 'multi_leg_entry', 'multi_rung_profit_ladder',
                'structure_timeframe_escalation', 'observed_news_exit', 'measured_move_or_liquidity_target'],
            ...$spec,
            'paper_execution_authority' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function basket(array $legs, float $stop, string $direction): array
    {
        $units = array_sum(array_map(fn (array $leg): float => (float) ($leg['units'] ?? 0), $legs));
        $average = $units > 0 ? array_sum(array_map(fn (array $leg): float => (float) ($leg['units'] ?? 0) * (float) ($leg['entry'] ?? 0), $legs)) / $units : 0;
        $risk = array_sum(array_map(fn (array $leg): float => abs((float) ($leg['entry'] ?? 0) - $stop) * (float) ($leg['units'] ?? 0), $legs));

        return ['weighted_average_entry' => $average, 'total_units' => $units, 'open_risk_price_units' => $risk, 'direction' => $direction, 'adds_are_winners_only' => true];
    }
}
