<?php

namespace App\Services;

/** Independent pessimistic admission layer; it can veto, reduce or WAIT. */
class DualTrackRiskShieldService
{
    public const PROTOCOL = 'dual_track_pessimistic_risk_shield_v1';

    /** @return array<string, mixed> */
    public function assess(array $context, array $champion, array $council, array $evidence = []): array
    {
        $mode = (string) config('services.dual_track.mode', 'shadow');
        $checks = [
            'constitution' => ($evidence['constitution_integrity'] ?? true) === true,
            'snapshot' => ($evidence['snapshot_integrity'] ?? true) === true,
            'catastrophic_regression_absent' => ($evidence['catastrophic_regression'] ?? false) !== true,
            'risk_of_ruin' => (float) ($evidence['risk_of_ruin_percent'] ?? 0) <= (float) config('services.dual_track.max_risk_of_ruin_percent', 10),
            'drawdown' => (float) ($evidence['max_drawdown_percent'] ?? 0) <= (float) config('services.dual_track.max_drawdown_percent', 15),
            'disagreement' => $this->compatible($champion, $council),
        ];
        $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));
        $confidence = max(
            $this->number($champion, 'confidence_lower_bound', $this->number($champion, 'confidence', 0)),
            $this->number($council, 'confidence_lower_bound', $this->number($council, 'confidence', 0)),
        );
        $calibrated = ($evidence['calibration_status'] ?? 'unavailable') === 'calibrated';
        $confidencePass = $confidence >= (float) config('services.dual_track.minimum_confidence_lower_bound', .55);
        if ($mode === 'active' && (bool) config('services.dual_track.require_calibration_for_active', true)) {
            $checks['calibration'] = $calibrated && $confidencePass;
            if (! $checks['calibration']) $failed[] = 'calibration';
        }

        $allowed = $failed === [];
        $transition = (bool) ($context['transition'] ?? false) || (string) ($context['market_regime'] ?? '') === 'transition';
        $multiplier = $transition ? (float) config('services.dual_track.transition_size_multiplier', .5) : 1.0;
        $decision = $allowed ? 'ALLOW' : 'WAIT';
        if ($transition && $allowed) $decision = 'REDUCE_SIZE';

        return [
            'protocol' => self::PROTOCOL, 'mode' => $mode,
            'status' => $mode === 'shadow' ? 'observation_only' : ($allowed ? 'allowed' : 'blocked'),
            'decision' => $decision, 'allowed' => $allowed, 'runtime_allowed' => $allowed && $mode === 'active',
            'position_size_multiplier' => $allowed ? $multiplier : 0.0,
            'confidence_lower_bound' => round($confidence, 6), 'calibrated' => $calibrated,
            'checks' => $checks, 'failed_checks' => array_values(array_unique($failed)),
            'fallback' => 'WAIT', 'promotion_evidence' => false,
        ];
    }

    private function compatible(array $champion, array $council): bool
    {
        $a = strtoupper((string) ($champion['decision'] ?? 'WAIT')); $b = strtoupper((string) ($council['decision'] ?? 'WAIT'));
        return ! (in_array($a, ['BUY', 'SELL'], true) && in_array($b, ['BUY', 'SELL'], true) && $a !== $b);
    }

    private function number(array $payload, string $key, mixed $fallback): float
    {
        return is_numeric($payload[$key] ?? null) ? max(0.0, min(1.0, (float) $payload[$key])) : (float) $fallback;
    }
}
