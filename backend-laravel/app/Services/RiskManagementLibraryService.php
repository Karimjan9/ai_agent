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
            'mutation_contract' => [
                'one_axis_only' => true,
                'allowed_gene' => $profile['gene'],
                'forbidden' => ['position_size_increase_after_loss', 'martingale', 'execution_contract'],
            ],
            'paired_control_required' => true,
            'promotion_evidence' => false,
        ];
    }
}
