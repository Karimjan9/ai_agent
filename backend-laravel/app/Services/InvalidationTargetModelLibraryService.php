<?php

namespace App\Services;

/**
 * Keeps entry precision, thesis invalidation and broker emergency protection
 * separate. These definitions are causal modules, never generic parameters.
 */
class InvalidationTargetModelLibraryService
{
    public const PROTOCOL = 'invalidation_target_model_library_v1';

    /** @return array<string,mixed> */
    public function compile(string $invalidation = 'structure_stop', string $target = 'H1_liquidity_target', array $owners = []): array
    {
        $invalidations = [
            'structure_stop' => ['trade_idea_invalidation' => 'owner_structure_close_beyond_invalidation', 'emergency_broker_stop' => 'structure_plus_atr_cost_buffer'],
            'sweep_extreme_stop' => ['trade_idea_invalidation' => 'sweep_extreme_reclaimed_against_thesis', 'emergency_broker_stop' => 'sweep_extreme_plus_atr_cost_buffer'],
            'atr_volatility_stop' => ['trade_idea_invalidation' => 'volatility_envelope_failure', 'emergency_broker_stop' => 'atr_envelope_hard_stop'],
            'range_boundary_stop' => ['trade_idea_invalidation' => 'accepted_close_outside_range_boundary', 'emergency_broker_stop' => 'range_boundary_plus_atr_cost_buffer'],
            'emergency_risk_stop' => ['trade_idea_invalidation' => 'risk_sentinel_emergency_veto', 'emergency_broker_stop' => 'broker_hard_stop'],
        ];
        $targets = [
            'H1_liquidity_target' => 'next_confirmed_h1_liquidity', 'M15_opposite_range' => 'opposite_m15_range_boundary',
            'session_high_low' => 'active_session_extreme', 'measured_move' => 'confirmed_impulse_projection',
            'fixed_R_target' => 'declared_r_multiple', 'volatility_adjusted_target' => 'atr_adjusted_objective', 'time_based_exit' => 'horizon_expiry',
        ];
        if (! isset($invalidations[$invalidation])) throw new \InvalidArgumentException("Unknown invalidation model [{$invalidation}].");
        if (! isset($targets[$target])) throw new \InvalidArgumentException("Unknown target model [{$target}].");

        return ['protocol' => self::PROTOCOL, 'entry_precision_level' => $owners['execution_owner'] ?? 'M5', 'invalidation_model' => $invalidation, ...$invalidations[$invalidation], 'target_model' => $target, 'target_definition' => $targets[$target], 'target_owner' => $owners['target_owner'] ?? 'H1', 'partial_1_owner' => $owners['setup_owner'] ?? 'M15', 'runner_owner' => $owners['target_owner'] ?? 'H1', 'execution_cannot_rewrite_stop' => true, 'promotion_evidence' => false];
    }
}
