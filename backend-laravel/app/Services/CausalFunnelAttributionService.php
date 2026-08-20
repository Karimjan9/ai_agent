<?php

namespace App\Services;

/**
 * Turns entry-funnel telemetry into a bounded next-experiment diagnosis.
 *
 * This is deliberately advisory: a low conversion funnel is not a reason to
 * waive the economic gates.  It prevents the planner from repeatedly changing
 * cooldowns when the evidence says that confidence, regime or EV vetoes are
 * actually removing the signals.
 */
class CausalFunnelAttributionService
{
    public const PROTOCOL = 'causal_entry_funnel_attribution_v1';

    /** @return array<string, mixed> */
    public function assess(array $result): array
    {
        $funnel = (array) data_get($result, 'entry_funnel', []);
        $raw = max(0, (int) data_get($funnel, 'raw_strategy_signals', data_get($funnel, 'flat_signal_opportunities', 0)));
        $accepted = max(0, (int) data_get($funnel, 'accepted_entries', data_get($result, 'total_trades', 0)));
        $rejected = (array) data_get($funnel, 'rejected', []);
        $dominant = (string) data_get($funnel, 'dominant_rejection', '');

        $namedVetoes = [
            'regime' => $this->sumMatching($rejected, ['regime', 'topology', 'transition', 'volatility']),
            'confidence' => $this->sumMatching($rejected, ['confidence']),
            'ev' => $this->sumMatching($rejected, ['ev', 'expectancy']),
            'risk_cooldown' => $this->sumMatching($rejected, ['cooldown', 'loss_streak', 'wait']),
        ];
        if ($dominant !== '') {
            foreach (array_keys($namedVetoes) as $lane) {
                if ($namedVetoes[$lane] === 0 && str_contains($dominant, str_replace('_', '', $lane))) {
                    $namedVetoes[$lane] = 1;
                }
            }
        }
        arsort($namedVetoes);
        $bottleneck = (string) array_key_first($namedVetoes);
        if ($raw === 0) $bottleneck = 'signal_generation';
        elseif ($accepted >= $raw) $bottleneck = 'none';
        elseif (($namedVetoes[$bottleneck] ?? 0) === 0) $bottleneck = $dominant !== '' ? $dominant : 'unclassified_veto';

        $recommendedLane = match ($bottleneck) {
            'confidence' => 'confidence_funnel_ablation',
            'ev' => 'confidence_funnel_ablation',
            'regime' => 'regime_topology_ablation',
            'risk_cooldown' => 'stress_exit_ablation',
            'signal_generation' => 'entry_topology_ablation',
            default => 'hold_or_replicate',
        };

        $assessment = [
            'protocol' => self::PROTOCOL,
            'raw_signals' => $raw,
            'accepted_entries' => $accepted,
            'acceptance_rate' => $raw > 0 ? round($accepted / $raw, 4) : null,
            'veto_counts' => $namedVetoes,
            'dominant_rejection' => $dominant !== '' ? $dominant : null,
            'bottleneck' => $bottleneck,
            'recommended_experiment_lane' => $recommendedLane,
            'promotion_evidence' => false,
        ];
        $assessment['failure_decomposition'] = $this->failureDecomposition($result, $assessment);
        // This supersedes a generic "temporal/calendar/stress/regime fail"
        // label as the bounded research routing input.  It is diagnostic only
        // and does not change a gate outcome.
        $assessment['recommended_experiment_lane'] = data_get(
            $assessment,
            'failure_decomposition.recommended_experiment_lane',
            $recommendedLane,
        );

        return $assessment;
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $funnel */
    private function failureDecomposition(array $result, array $funnel): array
    {
        $reasons = array_map('strtoupper', array_map('strval', (array) data_get($result, 'reason_codes', [])));
        $normalPf = (float) data_get($result, 'profit_factor', 0.0);
        $stressPf = (float) data_get($result, 'stress_test.profit_factor', data_get($result, 'stress_cost_exit.profit_factor', 0.0));
        $raw = (int) data_get($funnel, 'raw_signals', 0);
        $accepted = (int) data_get($funnel, 'accepted_entries', 0);
        $has = static fn (string $needle): bool => collect($reasons)->contains(fn (string $reason): bool => str_contains($reason, $needle));
        $primary = match (true) {
            ($stressPf > 0 && $stressPf < 1.0 && ($normalPf >= 1.0 || $stressPf + .10 < $normalPf)) || $has('STRESS') || $has('COST_EXIT')
                => 'exit_cost_execution',
            $has('CALENDAR') || $has('MONTH')
                => 'calendar_session_topology',
            $has('REGIME') || $has('TEMPORAL_CHUNK')
                => 'regime_or_topology_coverage',
            $raw > 0 && $accepted * 5 < $raw
                => 'entry_funnel_veto',
            $accepted > 0 && $stressPf > 0 && $stressPf < $normalPf
                => 'execution_or_exit',
            $raw === 0
                => 'signal_generation',
            default => 'mixed_or_insufficient',
        };
        $lane = match ($primary) {
            'exit_cost_execution', 'execution_or_exit' => 'stress_exit_ablation',
            'calendar_session_topology' => 'calendar_session_ablation',
            'regime_or_topology_coverage' => 'regime_topology_ablation',
            'entry_funnel_veto' => (string) data_get($funnel, 'recommended_experiment_lane', 'confidence_funnel_ablation'),
            'signal_generation' => 'entry_topology_ablation',
            default => 'hold_or_replicate',
        };

        return [
            'protocol' => 'failure_decomposition_v1',
            'primary_failure_mode' => $primary,
            'recommended_experiment_lane' => $lane,
            'signals' => [
                'normal_profit_factor' => $normalPf,
                'stress_profit_factor' => $stressPf,
                'raw_signals' => $raw,
                'accepted_entries' => $accepted,
                'reason_codes' => $reasons,
            ],
            'rule' => 'Route one causal failure mode per seat; never ask one mutation to repair every failed gate.',
            'promotion_evidence' => false,
        ];
    }

    /** @param array<string, mixed> $rejected @param array<int, string> $needles */
    private function sumMatching(array $rejected, array $needles): int
    {
        $total = 0;
        foreach ($rejected as $name => $count) {
            $key = strtolower((string) $name);
            if (collect($needles)->contains(fn (string $needle): bool => str_contains($key, $needle))) {
                $total += max(0, (int) $count);
            }
        }
        return $total;
    }
}
