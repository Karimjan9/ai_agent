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
        $m1Executable = (bool) $certified['m1_execution'];

        $roles = $m5Available
            ? ['bias' => 'H1', 'setup' => 'M15', 'trigger' => 'M5', 'execution' => $m1Executable ? 'M1' : 'M5', 'invalidation' => 'M5', 'management' => ['initial' => $m1Executable ? 'M1' : 'M5', 'after_1R' => 'M5', 'after_2R' => 'M15', 'final_target' => 'H1']]
            : ['bias' => 'H1', 'setup' => 'M15', 'trigger' => 'M15', 'execution' => 'M15', 'invalidation' => 'M15', 'management' => ['initial' => 'M15', 'after_1R' => 'M15', 'after_2R' => 'H1', 'final_target' => 'H1']];

        return [
            'protocol' => self::PROTOCOL,
            'required_capabilities' => $requirements,
            'roles' => $roles,
            'data_contract' => [
                'm5_canonical' => $m5Available,
                'm1_execution' => $m1Executable,
                'm1_false_precision_blocked' => ! $m1Executable,
                'data_plane' => $certified,
                'lookahead_safe' => true,
                'closed_candle_only' => true,
            ],
            'execution_timeframe_differs_from_invalidation_timeframe' => $roles['execution'] !== $roles['invalidation'],
            'promotion_evidence' => false,
        ];
    }
}
