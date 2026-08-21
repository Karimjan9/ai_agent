<?php

namespace App\Services;

/**
 * Tactic brain: translates a strategy thesis into a typed execution recipe.
 * The recipe is consumed by the execution/risk pipeline; it does not place an order.
 */
class TacticExecutorService
{
    public const PROTOCOL = 'tactic_executor_brain_v1';

    /** @return array<string,mixed> */
    public function __construct(private TradeManagementLibraryService $management) {}

    public function compile(array $route, array $strategy, array $context = []): array
    {
        $key = (string) ($route['playbook']?->playbook_key ?? '');
        $abstention = ($route['decision'] ?? 'ABSTAIN') !== 'TRADE';
        $recipe = $this->recipe($key);

        return [
            'protocol' => self::PROTOCOL,
            'action' => $abstention ? 'WAIT' : 'EXECUTE_IF_CONFIRMED',
            'tactic_id' => $recipe['tactic_id'],
            'entry' => $recipe['entry'],
            'confirmation_sequence' => $recipe['confirmation_sequence'],
            'invalidation' => $recipe['invalidation'],
            'exit' => $recipe['exit'],
            'execution_guards' => [
                'closed_candle_only' => true,
                'next_candle_execution' => true,
                'spread_aware' => true,
                'slippage_aware' => true,
                'no_chasing' => true,
                'no_averaging_down' => true,
                'no_martingale' => true,
            ],
            'telemetry' => ['mae', 'mfe', 'time_to_favorable_excursion', 'stop_efficiency', 'target_capture', 'slippage', 'cost_percent', 'follow_through'],
            'risk_authority' => 'execution_risk_sentinel',
            'trade_management' => $this->management->compile(str_contains($key, 'range') ? 'range_fixed_target' : 'balanced_professional', (string) data_get($route, 'state.regime', 'trend')),
            'strategy_proposal_hash' => hash('sha256', json_encode($strategy, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
            'promotion_evidence' => false,
        ];
    }

    private function recipe(string $key): array
    {
        if (str_contains($key, 'high_volatility_wait') || str_contains($key, 'transition_wait') || str_contains($key, 'cost_wait') || str_contains($key, 'loss_streak_wait')) {
            return ['tactic_id' => 'protective_abstention', 'entry' => 'none', 'confirmation_sequence' => [], 'invalidation' => ['wait_until' => 'state_returns_to_eligible_envelope'], 'exit' => ['action' => 'none']];
        }
        if (str_contains($key, 'fibonacci')) {
            return ['tactic_id' => 'dynamic_fibonacci_structure_pullback', 'entry' => 'retracement_zone_rejection_after_confirmed_swing', 'confirmation_sequence' => ['confirmed_swing', 'dynamic_zone', 'liquidity_response', 'closed_candle_rejection'], 'invalidation' => ['structure_break', 'zone_failure', 'spread_breach'], 'exit' => ['stop' => 'structure_plus_atr_buffer', 'target' => 'measured_leg_or_next_liquidity', 'management' => 'partial_then_atr_trail', 'time_stop' => true]];
        }
        if (str_contains($key, 'bos_retest') || str_contains($key, 'breakout')) {
            return ['tactic_id' => 'breakout_retest_continuation', 'entry' => 'displacement_break_then_retest_hold', 'confirmation_sequence' => ['closed_candle_break', 'displacement_quality', 'retest_hold', 'cost_check'], 'invalidation' => ['false_break', 'retest_failure', 'spread_breach'], 'exit' => ['stop' => 'retest_or_atr_buffer', 'target' => 'measured_move_or_liquidity', 'management' => 'partial_then_atr_trail', 'time_stop' => true]];
        }
        if (str_contains($key, 'choch')) {
            return ['tactic_id' => 'transition_reversal_probe', 'entry' => 'choch_plus_liquidity_sweep_confirmation', 'confirmation_sequence' => ['transition_detected', 'choch_closed_candle', 'sweep_failure', 'rejection'], 'invalidation' => ['transition_unresolved', 'sweep_continuation', 'spread_breach'], 'exit' => ['stop' => 'sweep_extreme_plus_atr_buffer', 'target' => 'opposite_liquidity', 'management' => 'reduced_risk_probe', 'time_stop' => true]];
        }
        if (str_contains($key, 'range')) {
            return ['tactic_id' => 'range_reentry', 'entry' => 'extreme_rejection_back_inside_value_area', 'confirmation_sequence' => ['range_confirmed', 'extreme_touch', 'reentry_close', 'cost_check'], 'invalidation' => ['range_expansion', 'trend_strength_rise', 'spread_breach'], 'exit' => ['stop' => 'range_extreme_plus_atr_buffer', 'target' => 'range_mid_or_opposite_edge', 'management' => 'partial_at_mid', 'time_stop' => true]];
        }

        return ['tactic_id' => 'trend_pullback', 'entry' => 'regime_aligned_pullback_rejection', 'confirmation_sequence' => ['h1_regime', 'm15_pullback', 'structure_hold', 'closed_candle_trigger'], 'invalidation' => ['regime_flip', 'structure_break', 'spread_breach'], 'exit' => ['stop' => 'structure_plus_atr_buffer', 'target' => 'next_liquidity_or_atr_multiple', 'management' => 'partial_then_atr_trail', 'time_stop' => true]];
    }
}
