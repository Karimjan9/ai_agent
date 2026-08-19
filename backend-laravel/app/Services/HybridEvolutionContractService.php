<?php

namespace App\Services;

/**
 * Single source of truth for brave-but-evidence-bounded research.
 *
 * The contract changes search posture only.  Frozen controls, immutable
 * evidence and promotion gates remain authoritative everywhere else.
 */
class HybridEvolutionContractService
{
    public const PROTOCOL = 'hybrid_evolution_contract_v1';

    public const LANES = [
        'directed_repair' => .60,
        'bold_structural' => .25,
        'adversarial_escape' => .15,
    ];

    public const MAX_CHANGED_GENES = [
        'frozen_control' => 0,
        'directed_repair' => 1,
        'bold_structural' => 3,
        'adversarial_escape' => 3,
    ];

    /**
     * Controls are part of the cohort, but not part of the research share.
     * For the 20-seat G62 cohort this produces 2 controls + 11/4/3 research.
     * The percentages therefore remain mathematically honest.
     *
     * @return array<string, mixed>
     */
    public function allocation(int $population, int $controlSeats = 2): array
    {
        $population = max(1, $population);
        $controlSeats = max(0, min($population, $controlSeats));
        $researchSeats = max(0, $population - $controlSeats);
        $shares = [
            'directed_repair' => max(0.0, (float) config('services.lab_selection.hybrid_directed_repair_share', self::LANES['directed_repair'])),
            'bold_structural' => max(0.0, (float) config('services.lab_selection.hybrid_bold_structural_share', self::LANES['bold_structural'])),
            'adversarial_escape' => max(0.0, (float) config('services.lab_selection.hybrid_adversarial_share', self::LANES['adversarial_escape'])),
        ];
        $shareTotal = array_sum($shares);
        if ($shareTotal <= 0) $shares = self::LANES;
        elseif (abs($shareTotal - 1.0) > .000001) $shares = array_map(static fn (float $share): float => $share / $shareTotal, $shares);
        $counts = array_map(
            static fn (float $share): int => (int) floor($researchSeats * $share),
            $shares,
        );
        $remaining = $researchSeats - array_sum($counts);
        $remainders = collect($shares)
            ->mapWithKeys(fn (float $share, string $lane): array => [
                $lane => ($researchSeats * $share) - floor($researchSeats * $share),
            ])
            ->sortDesc()->keys()->values()->all();
        $cursor = 0;
        while ($remaining > 0) {
            $lane = $remainders[$cursor % max(1, count($remainders))] ?? 'directed_repair';
            $counts[$lane]++;
            $remaining--;
            $cursor++;
        }

        return [
            'protocol' => self::PROTOCOL,
            'population' => $population,
            'control_seats' => $controlSeats,
            'research_seats' => $researchSeats,
            'shares' => $shares,
            'counts' => $counts,
            'projection' => $population === 20 && $controlSeats === 2
                ? '20 total = 2 frozen controls + 11 directed repair + 4 bold structural + 3 adversarial escape'
                : null,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function laneFor(array $slot): string
    {
        if ((bool) data_get($slot, 'niche.control_only', false)) return 'frozen_control';
        $explicit = (string) data_get($slot, 'niche.hybrid_evolution_lane', '');
        if (array_key_exists($explicit, self::LANES)) return $explicit;

        return match ((string) data_get($slot, 'research_group', '')) {
            'regime_coverage' => 'bold_structural',
            'portfolio_router' => 'adversarial_escape',
            default => 'directed_repair',
        };
    }

    /**
     * Decorate a planned cohort with an auditable lane and hypothesis contract.
     * The grouping is deterministic so a retry cannot reshuffle the same
     * hypothesis into a different research posture.
     *
     * @return array{plan: array<int, array<string, mixed>>, contract: array<string, mixed>}
     */
    public function decoratePlan(array $plan, string $cohortId = ''): array
    {
        $controls = collect($plan)->filter(fn (array $slot): bool => (bool) data_get($slot, 'niche.control_only', false))->count();
        $allocation = $this->allocation(count($plan), $controls);
        $observed = array_fill_keys(array_keys(self::LANES), 0);
        $hypotheses = [];

        foreach ($plan as $index => &$slot) {
            $lane = $this->laneFor($slot);
            $niche = (array) data_get($slot, 'niche', []);
            $isControl = $lane === 'frozen_control';
            if (! $isControl) $observed[$lane]++;

            $hypothesisId = (string) data_get($niche, 'structural_hypothesis_id', '');
            if ($hypothesisId === '') {
                $hypothesisId = hash('sha256', json_encode([
                    self::PROTOCOL, $cohortId, $index + 1, $slot['family'] ?? null,
                    $slot['target'] ?? null, $lane,
                ], JSON_UNESCAPED_SLASHES));
            }

            $multi = $this->multiGeneSpec($slot, $lane);
            if ($multi !== []) {
                $niche['declared_genes'] = array_keys($multi);
                $niche['declared_values'] = $multi;
                $niche['hybrid_multi_gene'] = true;
            }
            $niche['hybrid_evolution_lane'] = $lane;
            $niche['hybrid_evolution_contract'] = $this->seatContract(
                $slot,
                $lane,
                $hypothesisId,
                $multi,
                $isControl,
            );
            $niche['promotion_evidence'] = false;
            $slot['niche'] = $niche;
            $slot['hybrid_evolution_contract'] = $niche['hybrid_evolution_contract'];

            if (! $isControl) {
                $hypotheses[] = [
                    'slot' => $index + 1,
                    'lane' => $lane,
                    'hypothesis_id' => $hypothesisId,
                    'changed_genes' => (array) data_get($niche, 'declared_genes', array_filter([(string) data_get($niche, 'declared_gene', '')])),
                    'max_changed_genes' => self::MAX_CHANGED_GENES[$lane],
                ];
            }
        }
        unset($slot);

        return [
            'plan' => array_values($plan),
            'contract' => [
                'protocol' => self::PROTOCOL,
                'allocation' => $allocation,
                'observed_research_counts' => $observed,
                'hypotheses' => $hypotheses,
                'control_pair_required' => true,
                'research_only_until_independent_confirmation' => true,
                'promotion_evidence' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function failureAction(string $outcome, array $context = []): array
    {
        $outcome = strtolower(trim($outcome));

        return match ($outcome) {
            'technical_error', 'evidence_error' => [
                'lane' => 'directed_repair', 'action' => 'recover_evidence_only',
                'mutation_allowed' => false, 'memory_action' => 'record_uncertainty',
                'repeat_policy' => 'do_not_replay_strategy_claim',
            ],
            'strategy_failure' => [
                'lane' => 'directed_repair', 'action' => 'one_failure_targeted_gene_mutation',
                'mutation_allowed' => true, 'max_changed_genes' => 1,
                'memory_action' => 'record_negative_or_uncertainty',
                'repeat_policy' => 'close_only_after_repeatable_independent_failure',
            ],
            'repeated_failure' => [
                'lane' => 'adversarial_escape', 'action' => 'close_direction_and_architecture_escape',
                'mutation_allowed' => true, 'max_changed_genes' => 3,
                'memory_action' => 'quarantine_gene_direction',
                'repeat_policy' => 'no_same_direction_reuse',
            ],
            'stagnation', 'diversity_collapse' => [
                'lane' => 'bold_structural', 'action' => 'new_semantic_island_structural_probe',
                'mutation_allowed' => true, 'max_changed_genes' => 3,
                'memory_action' => 'record_stagnation_context',
                'repeat_policy' => 'change_mechanism_not_only_value',
            ],
            'independent_pass' => [
                'lane' => 'directed_repair', 'action' => 'increase_confirmed_gene_step',
                'mutation_allowed' => true, 'max_changed_genes' => 1,
                'memory_action' => 'promote_to_provisional_skill_candidate',
                'repeat_policy' => 'reuse_only_with_same_context_contract',
            ],
            'council_disagreement', 'uncertainty' => [
                'lane' => 'adversarial_escape', 'action' => 'challenger_or_abstention_probe',
                'mutation_allowed' => true, 'max_changed_genes' => 2,
                'memory_action' => 'record_uncertainty',
                'repeat_policy' => 'no_promotion_credit',
            ],
            default => [
                'lane' => 'directed_repair', 'action' => 'classify_failure_before_mutation',
                'mutation_allowed' => false, 'memory_action' => 'record_uncertainty',
                'repeat_policy' => 'hold',
            ],
        } + ['protocol' => self::PROTOCOL, 'context' => $context, 'promotion_evidence' => false];
    }

    /** @return array<string, mixed> */
    public function seatContract(array $slot, string $lane, string $hypothesisId, array $multi = [], bool $control = false): array
    {
        $genes = $control
            ? []
            : ($multi !== [] ? array_keys($multi) : array_values(array_filter([(string) data_get($slot, 'niche.declared_gene', '')])));
        $max = self::MAX_CHANGED_GENES[$lane] ?? 1;

        return [
            'protocol' => self::PROTOCOL,
            'lane' => $lane,
            'hypothesis_id' => $hypothesisId,
            'changed_genes' => $genes,
            'max_changed_genes' => $max,
            'min_changed_genes' => $control ? 0 : (($lane !== 'directed_repair' && count($genes) >= 2) ? 2 : 1),
            'causal_mechanism' => $this->mechanism($slot, $lane),
            'falsification_condition' => [
                'no_behavioral_delta', 'target_margin_not_improved',
                'non_target_regression', 'independent_replay_failure',
            ],
            'control_pair' => [
                'required' => true, 'same_generation' => true,
                'same_symbol_timeframe' => true, 'same_execution_contract' => true,
            ],
            'validation_windows' => ['screening', 'full_replay', 'forward', 'independent_confirmation'],
            'rollback_condition' => 'non_target_regression_or_falsification_condition',
            'kill_condition' => 'repeatable_independent_failure_in_same_direction',
            'research_only_until_independent_confirmation' => true,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function multiGeneSpec(array $slot, string $lane): array
    {
        if ((bool) data_get($slot, 'niche.control_only', false)) return [];
        if ($lane === 'bold_structural') {
            $variant = (string) data_get($slot, 'niche.entry_topology_variant', data_get($slot, 'niche.declared_value', 'regime_consensus_v1'));
            $classifier = match ($variant) {
                'trend_regime_confirmation_v1' => 'ema_slope_consensus_v1',
                'range_reentry_confirmation_v1' => 'volatility_adaptive_v1',
                default => 'adx_hysteresis_v1',
            };

            return [
                'entry_topology_variant' => $variant,
                'regime_classifier_variant' => $classifier,
            ];
        }
        if ($lane === 'adversarial_escape' && (string) data_get($slot, 'family', '') === 'differential_router') {
            return [
                'trend_up_risk_multiplier' => .65,
                'trend_down_risk_multiplier' => .45,
            ];
        }

        return [];
    }

    private function mechanism(array $slot, string $lane): string
    {
        return match ($lane) {
            'bold_structural' => 'Change entry topology, closed-regime classification and transition state together to test a new operating mechanism.',
            'adversarial_escape' => 'Deliberately challenge the dominant regime/directional assumption with an independent risk-routing topology.',
            'directed_repair' => 'Repair the declared failure target while freezing every non-target execution lane.',
            default => 'Frozen executable baseline used only for control comparison.',
        };
    }
}
