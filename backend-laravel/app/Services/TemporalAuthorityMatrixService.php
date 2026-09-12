<?php

namespace App\Services;

/** Enforces which temporal role owns each decision surface. */
class TemporalAuthorityMatrixService
{
    public const PROTOCOL = 'temporal_authority_matrix_v1';

    /** @return array<string,mixed> */
    public function compile(array $roles, string $horizon = 'day_structure'): array
    {
        $management = (array) ($roles['management'] ?? []);
        $owners = [
            'macro_bias_owner' => $roles['macro_bias'] ?? 'H4', 'direction_owner' => $roles['bias'] ?? 'H1',
            'regime_owner' => $roles['regime'] ?? ($roles['bias'] ?? 'H1'), 'setup_owner' => $roles['setup'] ?? 'M15',
            'location_owner' => $roles['location'] ?? ($roles['bias'] ?? 'H1'),
            'confirmation_owner' => $roles['confirmation'] ?? ($roles['setup'] ?? 'M15'),
            'trigger_owner' => $roles['trigger'] ?? 'M5', 'execution_owner' => $roles['execution'] ?? 'M5', 'invalidation_owner' => $roles['invalidation'] ?? 'M5',
            'management_owner' => [$management['after_1R'] ?? 'M15', $management['after_2R'] ?? 'H1'], 'target_owner' => $management['final_target'] ?? ($roles['bias'] ?? 'H1'),
        ];

        return ['protocol' => self::PROTOCOL, 'horizon_mode' => $horizon, 'owners' => $owners, 'validity' => ['setup_valid_for_bars' => 12, 'trigger_valid_for_bars' => 3, 'closed_candle_only' => true], 'laws' => ['lower_timeframe_cannot_reverse_direction' => true, 'execution_cannot_narrow_invalidation_without_structure' => true, 'management_cannot_rewrite_thesis' => true, 'm1_noise_cannot_close_h1_target_without_invalidation' => true], 'promotion_evidence' => false];
    }
}
