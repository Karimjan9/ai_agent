<?php

namespace App\Services;

use App\Models\RiskHysteresisState;
use Illuminate\Support\Facades\Schema;

/** Prevents risk from jumping back to NORMAL after one lucky winner. */
class RiskHysteresisControllerService
{
    public const PROTOCOL = 'risk_hysteresis_controller_v1';
    private const MULTIPLIERS = ['NORMAL' => 1.00, 'CAUTION' => .65, 'DEFENSE' => .25, 'RECOVERY' => .50];

    /** @return array<string,mixed> */
    public function transition(string $current, array $metrics): array
    {
        $current = array_key_exists($current, self::MULTIPLIERS) ? $current : 'NORMAL';
        $losses = max(0, (int) ($metrics['consecutive_losses'] ?? 0));
        $drawdownVelocity = (float) ($metrics['drawdown_velocity'] ?? 0);
        $healthyRecovery = (int) ($metrics['settled_trades'] ?? 0) >= 3 && (float) ($metrics['after_cost_expectancy'] ?? 0) > 0 && (bool) ($metrics['regime_aligned'] ?? false) && (bool) ($metrics['execution_quality_normal'] ?? false) && $drawdownVelocity <= 0;
        [$next, $reason] = match (true) {
            $losses >= 4 || $drawdownVelocity > 0.03 => ['DEFENSE', 'loss_streak_or_drawdown_acceleration'],
            $losses >= 2 => ['CAUTION', 'consecutive_loss_caution'],
            $current === 'DEFENSE' && $healthyRecovery => ['RECOVERY', 'settled_recovery_requirements_met'],
            $current === 'RECOVERY' && $healthyRecovery && $losses === 0 => ['NORMAL', 'recovery_confirmed'],
            default => [$current, 'hysteresis_hold'],
        };
        return ['protocol' => self::PROTOCOL, 'state' => $next, 'risk_multiplier' => self::MULTIPLIERS[$next], 'transition_reason' => $reason, 'recovery_requirements' => ['minimum_settled_trades' => 3, 'positive_after_cost_expectancy' => true, 'regime_alignment' => true, 'drawdown_velocity_nonpositive' => true, 'execution_quality_normal' => true], 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function persist(string $symbol, string $timeframe, array $metrics): array
    {
        $current = Schema::hasTable('risk_hysteresis_states') ? (string) (RiskHysteresisState::query()->where('state_key', strtoupper($symbol).'|'.strtoupper($timeframe))->value('state') ?? 'NORMAL') : 'NORMAL';
        $contract = $this->transition($current, $metrics);
        if (Schema::hasTable('risk_hysteresis_states')) RiskHysteresisState::query()->updateOrCreate(['state_key' => strtoupper($symbol).'|'.strtoupper($timeframe)], ['symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'state' => $contract['state'], 'risk_multiplier' => $contract['risk_multiplier'], 'transition_reason' => $contract['transition_reason'], 'metrics' => $metrics, 'changed_at' => now()]);
        return $contract;
    }
}
