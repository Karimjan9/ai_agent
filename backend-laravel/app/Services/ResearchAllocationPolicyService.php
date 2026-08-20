<?php

namespace App\Services;

/**
 * Makes the exploration/rescue budget explicit in every normal plan.
 * Allocation labels are search metadata only; gates and promotion remain
 * independent. A tripped rescue breaker removes the targeted-rescue lane and
 * redistributes its share across fresh structural/regime/control research.
 */
class ResearchAllocationPolicyService
{
    public const PROTOCOL = 'research_allocation_budget_v1';
    public const SHADOW_ALLOCATION_PROTOCOL = 'smart_courage_allocation_v1';
    public const CONTROL_PAIR_PROTOCOL = 'frozen_control_pair_v1';

    /** Authoritative normal shadow allocation. */
    public const SHADOW_SMART_SHARES = [
        'frozen_control' => .05,
        'targeted_repair' => .35,
        'proven_gene_refinement' => .20,
        'architecture_explorer' => .15,
        'robustness_split_specialist' => .10,
        'volume_m15_specialist' => .10,
        'bounded_random_adversarial' => .05,
    ];

    /** Authoritative rescue-blocked escape allocation. */
    public const SHADOW_ESCAPE_SHARES = [
        'frozen_control' => .05,
        'architecture_explorer' => .30,
        'robustness_split_specialist' => .25,
        'volume_m15_specialist' => .20,
        'regime_abstention_specialist' => .15,
        'bounded_random_adversarial' => .05,
    ];

    /**
     * The shadow governor and the generation audit must read the same budget.
     * This method is the single source of truth for both role counts and
     * shares; it never changes a gate or creates promotion evidence.
     *
     * @return array<string, mixed>
     */
    public function shadowAllocation(int $population, bool $targetedRescueBlocked = false): array
    {
        $population = max(1, $population);
        $shares = $targetedRescueBlocked ? self::SHADOW_ESCAPE_SHARES : self::SHADOW_SMART_SHARES;
        $counts = array_map(
            static fn (float $share): int => (int) floor($population * $share),
            $shares,
        );
        foreach (array_keys($shares) as $lane) $counts[$lane] = max(0, $counts[$lane]);
        $counts['frozen_control'] = max(1, $counts['frozen_control']);

        $remaining = $population - array_sum($counts);
        $remainders = collect($shares)
            ->mapWithKeys(fn (float $share, string $lane): array => [
                $lane => ($population * $share) - floor($population * $share),
            ])
            ->sortDesc()
            ->keys()
            ->values()
            ->all();
        $cursor = 0;
        while ($remaining > 0) {
            $lane = $remainders[$cursor % max(1, count($remainders))] ?? 'architecture_explorer';
            $counts[$lane]++;
            $remaining--;
            $cursor++;
        }
        while ($remaining < 0) {
            $lane = collect($counts)
                ->sortDesc()
                ->keys()
                ->first(fn (string $candidate): bool => $candidate !== 'frozen_control' && $counts[$candidate] > 0);
            if ($lane === null) break;
            $counts[$lane]--;
            $remaining++;
        }

        return [
            'protocol' => self::SHADOW_ALLOCATION_PROTOCOL,
            'population' => $population,
            'shares' => $shares,
            'counts' => $counts,
            'targeted_rescue_blocked' => $targetedRescueBlocked,
            'evidence_driven_share' => $targetedRescueBlocked ? .05 : .55,
            'controlled_exploration_share' => $targetedRescueBlocked ? .90 : .40,
            'control_share' => .05,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function shadowContract(bool $targetedRescueBlocked = false, int $plannedSeats = 0): array
    {
        $allocation = $this->shadowAllocation(max(1, $plannedSeats), $targetedRescueBlocked);

        return [
            'protocol' => self::PROTOCOL,
            'mode' => 'shadow_research',
            'allocation_protocol' => self::SHADOW_ALLOCATION_PROTOCOL,
            'requested_shares' => $allocation['shares'],
            'effective_shares' => $allocation['shares'],
            'counts' => $allocation['counts'],
            'targeted_rescue_blocked' => $targetedRescueBlocked,
            'evidence_driven_share' => $allocation['evidence_driven_share'],
            'controlled_exploration_share' => $allocation['controlled_exploration_share'],
            'control_share' => $allocation['control_share'],
            'control_pairing_contract' => [
                'protocol' => 'frozen_control_pair_v1',
                'scope' => 'same_generation_same_strategy_family_same_price_or_volume_lane',
                'minimum_control_per_execution_lane' => true,
                'materialize_missing_control_from_seat' => true,
                'missing_control_action' => 'diagnostic_only_no_learning_credit_no_full_replay',
                'promotion_evidence' => false,
            ],
            'lane_rule' => $targetedRescueBlocked
                ? 'Only frozen control, architecture/state, robustness/holdout, volume/session/M15, regime/abstention and bounded adversarial research are admitted.'
                : 'Targeted/proven lanes exploit evidence while architecture, robustness, volume and bounded adversarial lanes explore within the sealed snapshot.',
            'mutation_diversity_contract' => [
                'protocol' => 'shadow_mutation_diversity_v1',
                'structural_entry_variants' => [
                    'regime_consensus_v1',
                    'transition_hazard_v1',
                    'breakout_retest_v1',
                    'trend_regime_confirmation_v1',
                    'range_reentry_confirmation_v1',
                    'volatility_persistence_v1',
                ],
                'same_role_duplicate_gene_forbidden' => true,
                'same_generation_exact_diff_duplicate_forbidden' => true,
                'behavioral_delta_required' => true,
                'trade_ledger_delta_required' => true,
                'control_pair_required' => true,
                'structural_escape_mode' => [
                    'protocol' => 'structural_escape_mode_v1',
                    'repeated_scalar_failure_threshold' => 2,
                    'freeze_scalar_wait_threshold_search' => true,
                    'required_axes' => ['signal_construction', 'entry_exit_state', 'regime_classification'],
                    'promotion_evidence' => false,
                ],
                'promotion_evidence' => false,
            ],
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function contract(bool $targetedRescueBlocked = false, int $plannedSeats = 0): array
    {
        $requested = [
            'targeted_rescue' => .30,
            'architecture_signal' => .30,
            'regime_abstention' => .20,
            'frozen_control_replication' => .20,
        ];
        $effective = $requested;
        if ($targetedRescueBlocked) {
            $effective['targeted_rescue'] = 0.0;
            $remaining = 1.0;
            $nonTargetTotal = array_sum(array_slice($effective, 1));
            foreach (array_keys($effective) as $lane) {
                if ($lane === 'targeted_rescue') continue;
                $effective[$lane] = $nonTargetTotal > 0
                    ? round($effective[$lane] / $nonTargetTotal * $remaining, 4)
                    : 0.0;
            }
        }

        return [
            'protocol' => self::PROTOCOL,
            'mode' => 'normal_research',
            'requested_shares' => $requested,
            'effective_shares' => $effective,
            'targeted_rescue_blocked' => $targetedRescueBlocked,
            'planned_seats' => $plannedSeats,
            'targeted_rescue_rule' => $targetedRescueBlocked
                ? '0% until new chronological market evidence or sealed independent holdout is admitted.'
                : 'At most 30% of a normal population may be spent on targeted rescue; each rescue still needs circuit admission.',
            'lane_rule' => '30% targeted rescue, 30% new architecture/signal, 20% regime/abstention, 20% frozen control/replication; blocked rescue share is redistributed without changing gates.',
            'mutation_diversity_contract' => [
                'protocol' => 'mutation_diversity_contract_v1',
                'minimum_structural_candidate_share' => .25,
                'maximum_scalar_wait_or_threshold_share' => .75,
                'required_behavioral_axes' => ['signal_construction', 'entry_exit_state', 'regime_classification'],
                'same_generation_control_required' => true,
                'structural_escape_mode' => [
                    'protocol' => 'structural_escape_mode_v1',
                    'repeated_scalar_failure_threshold' => 2,
                    'freeze_scalar_wait_threshold_search' => true,
                    'required_axes' => ['signal_construction', 'entry_exit_state', 'regime_classification'],
                    'promotion_evidence' => false,
                ],
                'promotion_evidence' => false,
            ],
            'promotion_evidence' => false,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function annotatePlan(array $plan, bool $targetedRescueBlocked = false, ?array $shadowAllocation = null): array
    {
        $shadowMode = $shadowAllocation !== null;
        $contract = $shadowMode
            ? $this->shadowContract($targetedRescueBlocked, count($plan))
            : $this->contract($targetedRescueBlocked, count($plan));
        foreach ($plan as &$slot) {
            if (! is_array($slot)) continue;
            $slot['allocation_lane'] = $this->laneFor($slot, $shadowMode);
            $slot['allocation_budget_protocol'] = (string) ($shadowMode
                ? data_get($shadowAllocation, 'protocol', self::SHADOW_ALLOCATION_PROTOCOL)
                : self::PROTOCOL);
            $slot['allocation_mode'] = $shadowMode ? 'shadow_research' : 'normal_research';
            $slot['targeted_rescue_blocked'] = $targetedRescueBlocked;
            $slot['promotion_evidence'] = false;
        }
        unset($slot);

        return array_values($plan);
    }

    /**
     * Materialize the normal-research control floor before any agent model is
     * constructed. Shadow cohorts already use ShadowResearchGovernorService;
     * this companion path covers audited/normal generations so an allocation
     * label can never be mistaken for an actual causal control. One exact
     * frozen control is reserved per executable family and price/volume lane;
     * every other seat receives the same-generation pair key.
     *
     * @return array{plan: array<int, array<string, mixed>>, contract: array<string, mixed>}
     */
    public function materializeNormalControlPairing(
        array $plan,
        string $symbol,
        string $timeframe,
        int $generationId,
    ): array {
        // A volume/MTF lane is a real research lane only when it has both a
        // frozen no-volume baseline and at least one executable volume
        // candidate.  The governor historically reserved one volume-shadow
        // seat, which the control materializer correctly converted into the
        // control and thereby left the lane without a candidate.  Convert one
        // otherwise ordinary same-family price seat into the explicit volume
        // probe before pairing; this keeps the lane observable and preserves
        // the all-gates fail-closed rule.
        $volumePairRepair = $this->ensureNormalVolumeCandidate($plan);
        $plan = (array) data_get($volumePairRepair, 'plan', $plan);
        $required = [];
        $controls = [];
        foreach ($plan as $index => $slot) {
            $family = (string) data_get($slot, 'family', '');
            if ($family === '') continue;
            $lane = $this->executionLane($slot);
            $key = $lane.'|'.$family;
            $required[$key] = ['lane' => $lane, 'family' => $family, 'key' => $key];
            $role = (string) data_get($slot, 'niche.role', data_get($slot, 'niche.specialist_role', ''));
            if ((bool) data_get($slot, 'niche.control_only', false)
                || $role === 'frozen_control'
                || data_get($slot, 'evolution_mode') === 'frozen_control') {
                $controls[$key] = (int) $index;
            }
        }

        foreach ($required as $key => $contract) {
            if (array_key_exists($key, $controls)) continue;
            foreach ($plan as $index => $slot) {
                if (($controls[$key] ?? null) === (int) $index) continue;
                $family = (string) data_get($slot, 'family', '');
                if ($family !== $contract['family'] || $this->executionLane($slot) !== $contract['lane']) continue;
                $role = (string) data_get($slot, 'niche.role', data_get($slot, 'niche.specialist_role', ''));
                if ($role === 'frozen_control' || (bool) data_get($slot, 'niche.control_only', false)) continue;
                // Keep a structural hypothesis interpretable whenever a
                // non-structural seat is available.  The control is a
                // frozen baseline, not a converted topology experiment.
                if ((bool) data_get($slot, 'niche.structural_research', false)) continue;
                $controls[$key] = (int) $index;
                break;
            }
            if (! array_key_exists($key, $controls)) {
                foreach ($plan as $index => $slot) {
                    if (($controls[$key] ?? null) === (int) $index) continue;
                    $family = (string) data_get($slot, 'family', '');
                    if ($family !== $contract['family'] || $this->executionLane($slot) !== $contract['lane']) continue;
                    $role = (string) data_get($slot, 'niche.role', data_get($slot, 'niche.specialist_role', ''));
                    if ($role === 'frozen_control' || (bool) data_get($slot, 'niche.control_only', false)) continue;
                    $controls[$key] = (int) $index;
                    break;
                }
            }
        }

        $assignments = [];
        $candidateCounts = [];
        foreach ($plan as $index => &$slot) {
            $family = (string) data_get($slot, 'family', '');
            $lane = $this->executionLane($slot);
            $key = $lane.'|'.$family;
            if ($family === '' || ! isset($required[$key])) continue;
            $pairKey = hash('sha256', json_encode([
                'protocol' => 'frozen_control_pair_v1',
                'generation_id' => $generationId,
                'symbol' => strtoupper($symbol),
                'timeframe' => strtoupper($timeframe),
                'family' => $family,
                'execution_lane' => $lane,
            ], JSON_UNESCAPED_SLASHES));
            $isPrimaryControl = (int) ($controls[$key] ?? -1) === (int) $index;
            // A root portfolio deliberately has three frozen seats, two of
            // them in the same execution family.  The normal pair contract
            // only needs one primary control per family, but must not turn a
            // declared extra frozen root control into an undeclared candidate.
            $isControl = $isPrimaryControl || (
                (bool) data_get($slot, 'niche.root_experiment_portfolio', false)
                && (bool) data_get($slot, 'niche.control_only', false)
            );
            $slot['niche'] = [
                ...((array) ($slot['niche'] ?? [])),
                'control_pair_contract' => [
                    'protocol' => 'frozen_control_pair_v1',
                    'pair_key' => $pairKey,
                    'required_for_candidate' => ! $isControl,
                    'same_generation' => true,
                    'same_symbol_timeframe' => true,
                    'same_strategy_family' => true,
                    'execution_lane' => $lane,
                    'strategy_family' => $family,
                    'same_execution_contract' => true,
                    'missing_control_action' => 'diagnostic_only_no_learning_credit_no_full_replay',
                    'promotion_evidence' => false,
                ],
            ];
            if ($isControl) {
                $slot['evolution_mode'] = 'frozen_control';
                $slot['niche']['role'] = 'frozen_control';
                $slot['niche']['specialist_role'] = 'frozen_control';
                $slot['niche']['control_only'] = true;
                    $slot['niche']['control_lane'] = $lane;
                $slot['niche']['control_family'] = $family;
                unset(
                    $slot['niche']['declared_gene'],
                    $slot['niche']['declared_gene_requested'],
                    $slot['niche']['declared_value'],
                    $slot['niche']['structural_research'],
                    $slot['niche']['structural_hypothesis_protocol'],
                    $slot['niche']['structural_hypothesis_id'],
                    $slot['niche']['structural_operation'],
                    $slot['niche']['structural_mutation_required'],
                    $slot['niche']['shadow_mutation_gene'],
                    $slot['niche']['shadow_mutation_index'],
                    $slot['niche']['entry_topology_variant'],
                    $slot['niche']['state_machine_variant'],
                    $slot['niche']['regime_classifier_variant'],
                    $slot['niche']['architecture_experiment'],
                    $slot['niche']['architecture_escape'],
                    $slot['niche']['architecture_control_only'],
                );
                if ($isPrimaryControl) {
                    $assignments[$key] = [
                        'slot' => (int) $index + 1,
                        'family' => $family,
                        'execution_lane' => $lane,
                        'pair_key' => $pairKey,
                    ];
                }
            } else {
                $slot['niche']['control_only'] = false;
                $candidateCounts[$key] = ($candidateCounts[$key] ?? 0) + 1;
            }
        }
        unset($slot);

        $missing = array_values(array_diff(array_keys($required), array_keys($assignments)));
        $missingCandidates = array_values(array_filter(
            array_keys($required),
            fn (string $key): bool => (int) ($candidateCounts[$key] ?? 0) < 1,
        ));

        return [
            'plan' => array_values($plan),
            'contract' => [
                'protocol' => 'frozen_control_pair_v1',
                'mode' => 'normal_research',
                'generation_id' => $generationId,
                'symbol' => strtoupper($symbol),
                'timeframe' => strtoupper($timeframe),
                'required_execution_lanes' => array_values($required),
                'materialized_controls' => array_values($assignments),
                'missing_execution_lanes' => $missing,
                'candidate_counts' => $candidateCounts,
                'missing_candidate_pairs' => $missingCandidates,
                'volume_pair_repair' => data_get($volumePairRepair, 'repair'),
                'allowed' => $missing === [] && $missingCandidates === [],
                'missing_control_action' => 'generation_diagnostic_only_no_learning_credit_no_full_replay',
                'promotion_evidence' => false,
            ],
        ];
    }

    /**
     * Ensure a lone volume lane has an actual candidate seat.  This is a
     * plan-level allocation repair, not a score or gate adjustment: the
     * converted seat is still required to differ in exactly one executable
     * `volume_lane` gene and every volume freshness/coverage contract remains
     * mandatory at dispatch/evaluation time.
     *
     * @return array{plan: array<int, array<string, mixed>>, repair: ?array<string, mixed>}
     */
    private function ensureNormalVolumeCandidate(array $plan): array
    {
        $volumeSlots = collect($plan)
            ->filter(fn (array $slot): bool => $this->executionLane($slot) === 'volume')
            ->groupBy(fn (array $slot): string => (string) data_get($slot, 'family', ''));

        foreach ($volumeSlots as $family => $slots) {
            if ($family === '' || $slots->count() >= 2) continue;

            $volumeIndex = (int) $slots->keys()->first();
            $candidateIndex = collect($plan)
                ->keys()
                ->filter(fn (int $index): bool => (string) data_get($plan[$index], 'family', '') === $family)
                ->reject(fn (int $index): bool => $this->executionLane($plan[$index]) === 'volume')
                ->reject(fn (int $index): bool => (bool) data_get($plan[$index], 'niche.control_only', false))
                ->reject(fn (int $index): bool => (bool) data_get($plan[$index], 'niche.structural_research', false))
                ->reject(fn (int $index): bool => (bool) data_get($plan[$index], 'niche.shadow_only', false))
                ->values()
                ->first();
            if ($candidateIndex === null) continue;

            $niche = (array) data_get($plan[$candidateIndex], 'niche', []);
            unset(
                $niche['declared_gene'],
                $niche['declared_gene_requested'],
                $niche['declared_value'],
                $niche['structural_research'],
                $niche['structural_hypothesis_protocol'],
                $niche['structural_hypothesis_id'],
                $niche['structural_operation'],
                $niche['structural_mutation_required'],
                $niche['entry_topology_variant'],
                $niche['state_machine_variant'],
                $niche['regime_classifier_variant'],
                $niche['architecture_experiment'],
                $niche['architecture_escape'],
            );
            $niche = [
                ...$niche,
                'protocol' => 'adaptive_exploration_lane_v1',
                'role' => 'volume_m15_specialist',
                'specialist_role' => 'volume_m15_specialist',
                'data_lane' => 'volume',
                'volume_shadow' => true,
                'shadow_only' => true,
                'shadow_mutation_gene' => 'volume_lane',
                'shadow_mutation_index' => max(0, $candidateIndex),
                'shadow_mutation_contract' => [
                    'protocol' => 'shadow_volume_mutation_v1',
                    'gene' => 'volume_lane',
                    'freshness_required' => true,
                    'coverage_required' => true,
                    'no_volume_control_required' => false,
                    'same_generation_control_pair_required' => true,
                    'promotion_evidence' => false,
                ],
                'control_only' => false,
                'research_only_until_independent_replay' => true,
                'promotion_evidence' => false,
            ];
            $plan[$candidateIndex]['niche'] = $niche;

            return [
                'plan' => array_values($plan),
                'repair' => [
                    'protocol' => 'normal_volume_pair_materialization_v1',
                    'family' => $family,
                    'control_slot' => $volumeIndex + 1,
                    'candidate_slot' => $candidateIndex + 1,
                    'gene' => 'volume_lane',
                    'reason' => 'lone_volume_lane_would_otherwise_become_control_only',
                    'promotion_evidence' => false,
                ],
            ];
        }

        return ['plan' => array_values($plan), 'repair' => null];
    }

    /** @return array<string, mixed> */
    public function audit(array $plan, bool $targetedRescueBlocked = false, ?array $shadowAllocation = null): array
    {
        $shadowMode = $shadowAllocation !== null;
        $contract = $shadowMode
            ? $this->shadowContract($targetedRescueBlocked, count($plan))
            : $this->contract($targetedRescueBlocked, count($plan));
        $counts = collect($plan)
            ->filter(fn (mixed $slot): bool => is_array($slot))
            ->countBy(fn (array $slot): string => $this->laneFor($slot, $shadowMode))
            ->all();
        $targetCounts = [];
        foreach ((array) data_get($contract, 'effective_shares', []) as $lane => $share) {
            $targetCounts[$lane] = (int) round(count($plan) * (float) $share);
        }

        return [
            ...$contract,
            'hybrid_evolution' => app(HybridEvolutionContractService::class)->allocation(
                count($plan),
                collect($plan)->filter(fn (array $slot): bool => (bool) data_get($slot, 'niche.control_only', false))->count(),
            ),
            'observed_lane_counts' => $counts,
            'target_lane_counts' => $targetCounts,
            'targeted_rescue_observed' => (int) ($counts['targeted_rescue'] ?? 0),
            'allocation_status' => $targetedRescueBlocked && (int) ($counts['targeted_rescue'] ?? 0) > 0
                ? 'blocked_lane_present'
                : 'audited',
            'promotion_evidence' => false,
        ];
    }

    private function laneFor(array $slot, bool $shadowMode = false): string
    {
        if ($shadowMode) {
            $role = (string) data_get(
                $slot,
                'niche.shadow_research_lane.role',
                data_get($slot, 'shadow_research_lane.role', ''),
            );
            if ($role !== '') return $role;
        }
        $origin = (string) data_get($slot, 'origin', '');
        $target = (string) data_get($slot, 'target', '');
        $niche = (array) data_get($slot, 'niche', []);
        if ($origin === 'targeted_failure_profile' || $origin === 'gate_targeted') return 'targeted_rescue';
        if ((bool) data_get($niche, 'control_only', false)
            || (bool) data_get($niche, 'role_control', false)
            || $origin === 'lineage_root_rebuild'
            || data_get($niche, 'evolution_mode') === 'frozen_control') {
            return 'frozen_control_replication';
        }
        if (in_array($origin, ['curiosity_probe', 'regime_research'], true)
            || in_array($target, ['regime_coverage', 'opportunity_recall', 'unknown_state_curiosity'], true)) {
            return 'regime_abstention';
        }

        return 'architecture_signal';
    }

    private function executionLane(array $slot): string
    {
        $role = (string) data_get($slot, 'niche.role', data_get($slot, 'niche.specialist_role', ''));
        $parameters = (array) data_get($slot, 'niche.parameters', []);
        $volume = (bool) data_get($slot, 'niche.volume_shadow', false)
            || $role === 'volume_m15_specialist'
            || (string) data_get($slot, 'niche.data_lane', '') === 'volume'
            || (string) data_get($parameters, 'volume_lane', 'none') !== 'none';

        return $volume ? 'volume' : 'price';
    }
}
