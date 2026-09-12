<?php

namespace App\Services;

/** Binds strategy capabilities to timeframes without allowing false M1 precision. */
class TemporalRoleBinderService
{
    public const PROTOCOL = 'xauusd_temporal_role_binder_v1';

    public function __construct(private TemporalExecutionDataContractService $dataPlane) {}

    /** @return array<string, mixed> */
    public function bind(array $requirements = [], array $dataContract = []): array
    {
        $certified = $this->dataPlane->certify($dataContract);
        $m5Available = (bool) $certified['m5_canonical'];
        $decisionRoles = (array) config('services.xauusd_organism.decision_roles', []);
        $roles = [
            'macro_bias' => strtoupper((string) ($decisionRoles['macro_bias'] ?? 'H4')),
            'bias' => strtoupper((string) ($decisionRoles['bias'] ?? 'H1')),
            'regime' => strtoupper((string) ($decisionRoles['regime'] ?? 'H1')),
            'location' => strtoupper((string) ($decisionRoles['location'] ?? 'H1')),
            'setup' => strtoupper((string) ($decisionRoles['setup'] ?? 'M15')),
            'confirmation' => strtoupper((string) ($decisionRoles['confirmation'] ?? 'M15')),
            'trigger' => strtoupper((string) ($decisionRoles['trigger'] ?? 'M5')),
            'execution' => strtoupper((string) ($decisionRoles['execution'] ?? 'M5')),
            'invalidation' => strtoupper((string) ($decisionRoles['invalidation'] ?? 'M5')),
        ];
        $roles['management'] = [
            'initial' => $roles['execution'],
            'after_1R' => $roles['execution'],
            'after_2R' => $roles['setup'],
            'final_target' => $roles['bias'],
        ];

        return [
            'protocol' => self::PROTOCOL,
            'required_capabilities' => $requirements,
            'roles' => $roles,
            'data_contract' => [
                'm5_canonical' => $m5Available,
                'execution_ready' => $m5Available && $roles['execution'] === 'M5',
                'execution_block_reason' => $m5Available ? null : 'M5_CANONICAL_DATA_REQUIRED',
                'm1_available_as_sensor' => (bool) $certified['m1_execution'],
                'm1_execution' => false,
                'm1_false_precision_blocked' => true,
                'data_plane' => $certified,
                'lookahead_safe' => true,
                'closed_candle_only' => true,
            ],
            'execution_timeframe_differs_from_invalidation_timeframe' => $roles['execution'] !== $roles['invalidation'],
            'promotion_evidence' => false,
        ];
    }
}
