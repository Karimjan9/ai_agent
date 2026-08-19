<?php

namespace App\Services;

/**
 * Controls the handoff from one incumbent champion to a specialist council.
 *
 * This service is intentionally pure and read-only. It produces a transition
 * decision from frozen evidence; it never changes runtime ownership itself.
 */
class ChampionCouncilTransitionService
{
    public const PROTOCOL = 'champion_to_council_transition_v1';

    /** @return array<string, mixed> */
    public function policy(): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'stages' => [
                'incumbent_protected',
                'shadow_council',
                'hybrid_handoff',
                'council_challenge',
                'anchor_ablation',
                'council_active',
                'rollback',
            ],
            'minimum_shadow_windows' => $this->setting('services.lab_selection.transition_min_shadow_windows', 3),
            'minimum_hybrid_windows' => $this->setting('services.lab_selection.transition_min_hybrid_windows', 3),
            'minimum_council_windows' => $this->setting('services.lab_selection.transition_min_council_windows', 3),
            'minimum_anchor_ablation_windows' => $this->setting('services.lab_selection.transition_min_anchor_ablation_windows', 2),
            'baseline_relative_tolerance' => (float) $this->setting('services.lab_selection.transition_baseline_tolerance', .03),
            'maximum_worst_window_regression' => (float) $this->setting('services.lab_selection.transition_max_worst_window_regression', .05),
            'maximum_router_switch_rate' => (float) $this->setting('services.lab_selection.transition_max_router_switch_rate', .25),
            'maximum_anchor_dependency' => (float) $this->setting('services.lab_selection.transition_max_anchor_dependency', .20),
            'canary_shares' => [
                'shadow_council' => 0.0,
                'hybrid_handoff' => .25,
                'council_challenge' => .50,
                'council_active' => 1.0,
            ],
            'rule' => 'incumbent champion remains the fallback until council proves parity, complementarity, stability and anchor independence',
        ];
    }

    /** @return array<string, mixed> */
    public function evaluate(array $incumbent, array $council, array $evidence = []): array
    {
        $policy = $this->policy();
        $incumbentScore = $this->number($evidence, 'incumbent_score', data_get($incumbent, 'score'));
        $councilScore = $this->number($evidence, 'council_score', data_get($council, 'score'));
        $hybridScore = $this->number($evidence, 'hybrid_score');
        $hasBaseline = $incumbentScore !== null;
        $compatibility = (string) data_get($evidence, 'council_compatibility_status', 'unknown');
        $allPassports = data_get($evidence, 'all_council_members_passed') === true;
        $leaveOneOut = data_get($evidence, 'leave_one_out_passed') === true;
        $anchorAblation = data_get($evidence, 'anchor_ablation_passed') === true;
        $worstRegression = (float) data_get($evidence, 'worst_window_regression', 1.0);
        $switchRate = (float) data_get($evidence, 'router_switch_rate', 1.0);
        $synergyDelta = $this->number($evidence, 'council_synergy_delta');
        $anchorDependency = (float) data_get($evidence, 'anchor_dependency', 1.0);

        $checks = [
            'incumbent_baseline_frozen' => $hasBaseline,
            'council_compatible' => $compatibility === 'compatible',
            'individual_passports' => $allPassports,
            'shadow_evidence' => (int) data_get($evidence, 'shadow_windows', 0) >= (int) $policy['minimum_shadow_windows'],
            'hybrid_evidence' => (int) data_get($evidence, 'hybrid_windows', 0) >= (int) $policy['minimum_hybrid_windows'],
            'council_evidence' => (int) data_get($evidence, 'council_windows', 0) >= (int) $policy['minimum_council_windows'],
            'council_not_below_baseline' => $this->parity($councilScore, $incumbentScore, (float) $policy['baseline_relative_tolerance']),
            'hybrid_not_below_baseline' => $this->parity($hybridScore, $incumbentScore, (float) $policy['baseline_relative_tolerance']),
            'worst_window_regression' => $worstRegression <= (float) $policy['maximum_worst_window_regression'],
            'router_stability' => $switchRate <= (float) $policy['maximum_router_switch_rate'],
            'council_synergy' => $synergyDelta !== null && $synergyDelta >= 0,
            'leave_one_out' => $leaveOneOut,
            'anchor_ablation' => $anchorAblation
                && (int) data_get($evidence, 'anchor_ablation_windows', 0) >= (int) $policy['minimum_anchor_ablation_windows']
                && $anchorDependency <= (float) $policy['maximum_anchor_dependency'],
        ];

        $rollback = (bool) data_get($evidence, 'rollback_requested', false)
            || (bool) data_get($evidence, 'drift_detected', false)
            || (bool) data_get($evidence, 'catastrophic_regression', false);
        $stage = $this->stage($checks, $rollback, $evidence);
        $decision = match ($stage) {
            'rollback' => 'ROLLBACK_TO_INCUMBENT',
            'council_active' => 'PROMOTE_COUNCIL',
            'council_challenge' => 'COUNCIL_CANARY',
            'hybrid_handoff' => 'HYBRID_CANARY',
            'shadow_council' => 'SHADOW_ONLY',
            default => 'KEEP_INCUMBENT',
        };

        return [
            'protocol' => self::PROTOCOL,
            'status' => $stage === 'council_active' ? 'ready' : ($stage === 'rollback' ? 'rollback' : 'protected'),
            'stage' => $stage,
            'decision' => $decision,
            'routing_mode' => $stage === 'council_active' ? 'council' : ($stage === 'hybrid_handoff' || $stage === 'council_challenge' ? 'hybrid' : 'incumbent'),
            'council_canary_share' => (float) data_get($policy, "canary_shares.{$stage}", 0),
            'checks' => $checks,
            'failed_checks' => collect($checks)->filter(fn (bool $passed): bool => ! $passed)->keys()->values()->all(),
            'scores' => [
                'incumbent' => $incumbentScore,
                'hybrid' => $hybridScore,
                'council' => $councilScore,
                'synergy_delta' => $synergyDelta,
                'worst_window_regression' => $worstRegression,
                'router_switch_rate' => $switchRate,
                'anchor_dependency' => $anchorDependency,
            ],
            'fallback' => 'incumbent_champion',
            'promotion_evidence' => false,
            'rule' => 'no council activation without baseline parity, shadow/hybrid evidence, stable routing, synergy and anchor ablation',
        ];
    }

    private function stage(array $checks, bool $rollback, array $evidence): string
    {
        if ($rollback) return 'rollback';
        if (! $checks['incumbent_baseline_frozen'] || ! $checks['council_compatible'] || ! $checks['individual_passports']) {
            return 'incumbent_protected';
        }
        if (! $checks['shadow_evidence']) return 'shadow_council';
        if (! $checks['hybrid_evidence'] || ! $checks['hybrid_not_below_baseline']) return 'hybrid_handoff';
        if (! $checks['council_evidence'] || ! $checks['council_not_below_baseline'] || ! $checks['worst_window_regression'] || ! $checks['router_stability']) {
            return 'council_challenge';
        }
        if (! $checks['council_synergy'] || ! $checks['leave_one_out'] || ! $checks['anchor_ablation']) {
            return 'anchor_ablation';
        }
        return 'council_active';
    }

    private function parity(?float $candidate, ?float $baseline, float $tolerance): bool
    {
        if ($candidate === null || $baseline === null) return false;
        $scale = max(abs($baseline), 1.0);
        return $candidate >= $baseline - ($scale * $tolerance);
    }

    private function number(array $payload, string $key, mixed $fallback = null): ?float
    {
        $value = $payload[$key] ?? $fallback;
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function setting(string $key, mixed $default): mixed
    {
        try {
            return function_exists('app') && app()->bound('config') ? config($key, $default) : $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
