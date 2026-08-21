<?php

namespace App\Services;

/** Canonical, risk-owned tactic profiles for paper execution. */
class TradeManagementLibraryService
{
    public const PROTOCOL = 'trade_management_library_v1';

    /** @return array<string,mixed> */
    public function compile(string $profile = 'balanced_professional', string $regime = 'trend'): array
    {
        $profiles = [
            'balanced_professional' => ['entry' => ['confirmation_entry' => .5, 'retest_confirmation_add' => .3, 'structure_confirmation_add' => .2], 'profit' => ['tp_ladder_r' => [['r' => 1, 'close_fraction' => .4], ['r' => 2, 'close_fraction' => .3]], 'runner_fraction' => .3], 'stop' => ['breakeven_after' => 'tp1_or_structure', 'trail' => 'atr_or_structure'], 'exit' => ['time_stop' => true, 'news_exit' => true]],
            'range_fixed_target' => ['entry' => ['single_confirmation_entry' => 1], 'profit' => ['fixed_target_r' => 1.25, 'close_fraction' => 1], 'stop' => ['breakeven_after' => 'none'], 'exit' => ['time_stop' => true, 'session_exit' => true]],
        ];
        $plan = $profiles[$profile] ?? $profiles['balanced_professional'];
        if ($regime === 'range') {
            $plan = $profiles['range_fixed_target'];
        }

        return ['protocol' => self::PROTOCOL, 'profile' => $profile, 'state' => 'NEW', 'plan' => $plan, 'basket' => ['total_risk_must_not_increase' => true, 'weighted_average_entry' => true, 'max_open_heat_owned_by' => 'risk_sentinel'], 'state_machine' => ['NEW', 'ARMED', 'LOCATE', 'TRIGGERED', 'CONFIRMED', 'RISK_APPROVED', 'OPEN', 'MANAGE', 'REDUCE_ONLY', 'CLOSED', 'REVIEW'], 'forbidden' => ['averaging_down', 'grid', 'martingale', 'soft_martingale', 'capped_martingale'], 'promotion_contract' => ['paired_control' => true, 'same_execution_hash' => true, 'independent_windows' => 3], 'promotion_evidence' => false];
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
