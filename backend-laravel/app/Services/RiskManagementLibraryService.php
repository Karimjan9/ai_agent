<?php

namespace App\Services;

/**
 * Immutable risk profiles that may be tested by the laboratory.  They are
 * hypotheses, never permission to relax the execution or promotion gates.
 */
class RiskManagementLibraryService
{
    public const PROTOCOL = 'composable_risk_management_library_v1';

    /** @return array<int, array<string, mixed>> */
    public function library(): array
    {
        return [
            ['id' => 'atr_risk_envelope', 'label' => 'ATR risk envelope', 'gene' => 'atr_stop_multiplier', 'target' => 'risk_exit', 'owner' => 'risk_sentinel'],
            ['id' => 'cost_firewall', 'label' => 'Cost firewall', 'gene' => 'max_spread_atr_ratio', 'target' => 'volatility_session_stability', 'owner' => 'risk_sentinel'],
            ['id' => 'volatility_firewall', 'label' => 'Volatility firewall', 'gene' => 'high_volatility_risk_multiplier', 'target' => 'drawdown_risk', 'owner' => 'risk_sentinel'],
            ['id' => 'transition_protection', 'label' => 'Transition protection', 'gene' => 'transition_wait_candles', 'target' => 'transition_firewall', 'owner' => 'risk_sentinel'],
            ['id' => 'loss_streak_cooldown', 'label' => 'Loss-streak cooldown', 'gene' => 'loss_cooldown_candles', 'target' => 'monthly_survival', 'owner' => 'risk_sentinel'],
        ];
    }

    /** @return array<string, mixed> */
    public function compile(string $id): array
    {
        $profile = collect($this->library())->firstWhere('id', $id);
        if (! $profile) {
            throw new \InvalidArgumentException("Unknown risk-management profile: {$id}");
        }

        return [
            'protocol' => self::PROTOCOL,
            'profile' => $profile,
            // Strategies may request admission but the governor owns account
            // risk. These are hard constraints, not mutation suggestions.
            'central_risk_governor' => [
                'protocol' => 'xauusd_central_risk_governor_v1',
                'position_size' => 'allowed_account_risk / executable_stop_distance',
                'executable_stop_distance' => ['structural_invalidation', 'volatility_allowance', 'execution_cost_allowance'],
                'high_volatility' => 'wider_stop_smaller_size_same_account_risk_or_wait',
                'hard_bans' => ['fixed_lot', 'martingale', 'loser_add', 'risk_increase_after_loss'],
                'guards' => ['daily_loss_limit', 'loss_streak_circuit_breaker', 'drawdown_scaling', 'news_cost_firewall', 'single_xauusd_net_exposure_ledger'],
                'promotion_evidence' => false,
            ],
            'mutation_contract' => [
                'one_axis_only' => true,
                'allowed_gene' => $profile['gene'],
                'forbidden' => ['position_size_increase_after_loss', 'martingale', 'fixed_lot', 'loser_add', 'execution_contract'],
            ],
            'paired_control_required' => true,
            'promotion_evidence' => false,
        ];
    }
}
