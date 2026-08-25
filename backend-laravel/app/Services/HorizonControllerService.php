<?php

namespace App\Services;

/** Binds each composition to one declared holding horizon before selection. */
class HorizonControllerService
{
    public const PROTOCOL = 'xauusd_horizon_controller_v1';

    /** @return array<string,mixed> */
    public function compile(string $mode = 'day_structure', array $override = []): array
    {
        $catalogue = [
            'fast_intraday' => ['roles' => ['bias' => 'M30', 'setup' => 'M5', 'trigger' => 'M1'], 'expected_duration' => '15m_to_4h', 'max_duration' => '8h', 'overnight_allowed' => false, 'weekend_allowed' => false, 'session_close_policy' => 'close'],
            'day_structure' => ['roles' => ['bias' => 'H1', 'setup' => 'M15', 'trigger' => 'M5', 'execution' => 'M1'], 'expected_duration' => '1h_to_session', 'max_duration' => '24h', 'overnight_allowed' => false, 'weekend_allowed' => false, 'session_close_policy' => 'reduce_or_close'],
            'mini_swing' => ['roles' => ['bias' => 'D1', 'setup' => 'H4', 'trigger' => 'H1', 'execution' => 'M15'], 'expected_duration' => '1_to_3d', 'max_duration' => '5d', 'overnight_allowed' => true, 'weekend_allowed' => false, 'session_close_policy' => 'hold_if_thesis_valid'],
        ];
        if (! isset($catalogue[$mode])) throw new \InvalidArgumentException("Unknown horizon mode [{$mode}].");
        return ['protocol' => self::PROTOCOL, 'horizon_mode' => $mode, ...array_replace($catalogue[$mode], array_intersect_key($override, array_flip(['expected_duration', 'max_duration', 'overnight_allowed', 'weekend_allowed', 'session_close_policy']))), 'research_prior_only' => true, 'promotion_evidence' => false];
    }
}
