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

    public const CONTROL_PAIR_PROTOCOL = 'exact_frozen_control_pair_v2';

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
        foreach (array_keys($shares) as $lane) {
            $counts[$lane] = max(0, $counts[$lane]);
        }
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
            if ($lane === null) {
                break;
            }
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
                'protocol' => self::CONTROL_PAIR_PROTOCOL,
                'scope' => 'same_generation_same_strategy_family_exact_parameter_vector_except_one_gene',
                'minimum_exact_control_per_candidate' => true,
                'persist_control_before_candidate' => true,
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
                if ($lane === 'targeted_rescue') {
                    continue;
                }
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
            if (! is_array($slot)) {
                continue;
            }
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
     * frozen control is reserved for every candidate; shared family/lane
     * controls are forbidden because they may carry a different untouched
     * parameter vector.
     *
     * @return array{plan: array<int, array<string, mixed>>, contract: array<string, mixed>}
     */
    public function materializeNormalControlPairing(
        array $plan,
        string $symbol,
        string $timeframe,
        int $generationId,
    ): array {
        $plan = array_values($plan);
        $protected = [];
        $free = [];
        foreach ($plan as $index => $slot) {
            if ($this->isPrimaryProofSeat((array) $slot)) {
                $protected[] = (int) $index;
            } else {
                $free[] = (int) $index;
            }
        }

        $pairCount = intdiv(count($free), 2);
        $templateIndexes = $this->diverseCandidateTemplateIndexes($plan, $free, $pairCount);
        $templatePlan = $plan;
        $materialized = [];
        $candidateCounts = [];
        $pairedSlots = [];

        for ($pairIndex = 0; $pairIndex < $pairCount; $pairIndex++) {
            $controlIndex = (int) $free[$pairIndex * 2];
            $candidateIndex = (int) $free[($pairIndex * 2) + 1];
            $templateIndex = (int) ($templateIndexes[$pairIndex] ?? $candidateIndex);
            $template = (array) ($templatePlan[$templateIndex] ?? $templatePlan[$candidateIndex]);
            $family = (string) data_get($template, 'family', '');
            $lane = $this->executionLane($template);
            $pairKey = hash('sha256', json_encode([
                'protocol' => self::CONTROL_PAIR_PROTOCOL,
                'generation_id' => $generationId,
                'symbol' => strtoupper($symbol),
                'timeframe' => strtoupper($timeframe),
                'pair_index' => $pairIndex + 1,
                'family' => $family,
                'execution_lane' => $lane,
                'hypothesis_family' => data_get($template, 'niche.hypothesis_family'),
                'declared_gene' => data_get($template, 'niche.declared_gene'),
                'declared_value' => data_get($template, 'niche.declared_value'),
            ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

            // Preserve the two host seats' population-group coordinates, but
            // run both against one copied experiment definition. This keeps
            // the 5x4 scheduler budget intact while making the scientific
            // unit a real one-candidate/one-control pair.
            $control = $this->pairHost($plan[$controlIndex], $template);
            $candidate = $this->pairHost($plan[$candidateIndex], $template);
            $semanticRole = (string) data_get(
                $template,
                'niche.paired_semantic_role',
                data_get($template, 'niche.specialist_role', data_get($template, 'niche.role', '')),
            );
            $contract = [
                'protocol' => self::CONTROL_PAIR_PROTOCOL,
                'pair_key' => $pairKey,
                'pair_index' => $pairIndex + 1,
                'same_generation' => true,
                'same_symbol_timeframe' => true,
                'same_strategy_family' => true,
                'same_parameter_baseline' => true,
                'single_intervention_required' => true,
                'execution_lane' => $lane,
                'strategy_family' => $family,
                'same_execution_contract' => true,
                'missing_control_action' => 'diagnostic_only_no_learning_credit_no_full_replay',
                'promotion_evidence' => false,
            ];

            $control['evolution_mode'] = 'frozen_control';
            $control['niche'] = [
                ...$this->withoutIntervention((array) data_get($control, 'niche', [])),
                'control_only' => true,
                'paired_semantic_role' => $semanticRole !== '' ? $semanticRole : null,
                'data_lane' => $lane,
                'control_lane' => $lane,
                'control_family' => $family,
                'control_pair_contract' => [
                    ...$contract,
                    'role' => 'control',
                    'required_for_candidate' => false,
                ],
            ];
            $candidate['niche'] = [
                ...((array) data_get($candidate, 'niche', [])),
                'control_only' => false,
                'paired_semantic_role' => $semanticRole !== '' ? $semanticRole : null,
                'data_lane' => $lane,
                'control_pair_contract' => [
                    ...$contract,
                    'role' => 'candidate',
                    'required_for_candidate' => true,
                ],
            ];
            if ((string) data_get($candidate, 'evolution_mode') === 'frozen_control') {
                $candidate['evolution_mode'] = 'paired_discovery_candidate';
            }

            $plan[$controlIndex] = $control;
            $plan[$candidateIndex] = $candidate;
            $cell = $lane.'|'.$family;
            $candidateCounts[$cell] = ($candidateCounts[$cell] ?? 0) + 1;
            $materialized[] = [
                'pair_index' => $pairIndex + 1,
                'control_slot' => $controlIndex + 1,
                'candidate_slot' => $candidateIndex + 1,
                'family' => $family,
                'execution_lane' => $lane,
                'pair_key' => $pairKey,
            ];
            $pairedSlots[] = $controlIndex + 1;
            $pairedSlots[] = $candidateIndex + 1;
        }

        $abstainSlots = [];
        if (count($free) % 2 === 1) {
            $index = (int) end($free);
            $slot = (array) $plan[$index];
            $family = (string) data_get($slot, 'family', '');
            $lane = $this->executionLane($slot);
            $pairKey = hash('sha256', self::CONTROL_PAIR_PROTOCOL.'|'.$generationId.'|abstain|'.($index + 1));
            $slot['evolution_mode'] = 'uncertainty_abstain';
            $slot['niche'] = [
                ...$this->withoutIntervention((array) data_get($slot, 'niche', [])),
                'control_only' => true,
                'uncertainty_abstain' => true,
                'control_pair_contract' => [
                    'protocol' => self::CONTROL_PAIR_PROTOCOL,
                    'pair_key' => $pairKey,
                    'role' => 'uncertainty_abstain',
                    'required_for_candidate' => false,
                    'same_generation' => true,
                    'same_symbol_timeframe' => true,
                    'same_strategy_family' => true,
                    'same_parameter_baseline' => true,
                    'single_intervention_required' => false,
                    'execution_lane' => $lane,
                    'strategy_family' => $family,
                    'same_execution_contract' => true,
                    'promotion_evidence' => false,
                ],
            ];
            $plan[$index] = $slot;
            $abstainSlots[] = $index + 1;
        }

        $allowed = $pairCount > 0
            && count($materialized) === $pairCount
            && count($pairedSlots) === $pairCount * 2
            && count($plan) === count($pairedSlots) + count($protected) + count($abstainSlots);

        return [
            'plan' => array_values($plan),
            'contract' => [
                'protocol' => self::CONTROL_PAIR_PROTOCOL,
                'mode' => 'normal_research',
                'generation_id' => $generationId,
                'symbol' => strtoupper($symbol),
                'timeframe' => strtoupper($timeframe),
                'population_size' => count($plan),
                'primary_proof_slots' => array_map(fn (int $index): int => $index + 1, $protected),
                'pair_count' => $pairCount,
                'materialized_controls' => $materialized,
                'candidate_counts' => $candidateCounts,
                'missing_execution_lanes' => [],
                'missing_candidate_pairs' => [],
                'uncertainty_abstain_slots' => $abstainSlots,
                'one_control_per_candidate' => true,
                'candidate_must_copy_persisted_control_baseline' => true,
                'allowed' => $allowed,
                'missing_control_action' => 'generation_diagnostic_only_no_learning_credit_no_full_replay',
                'promotion_evidence' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $slot */
    private function isPrimaryProofSeat(array $slot): bool
    {
        return (array) data_get($slot, 'niche.causal_learning_cohort', []) !== []
            || (int) data_get($slot, 'niche.causal_confirmation_source_lesson_id', 0) > 0
            || (int) data_get($slot, 'niche.causal_repair_source_experiment_id', 0) > 0;
    }

    /**
     * @param  array<int,array<string,mixed>>  $plan
     * @param  array<int,int>  $free
     * @return array<int,int>
     */
    private function diverseCandidateTemplateIndexes(array $plan, array $free, int $limit): array
    {
        $eligible = collect($free)
            ->reject(fn (int $index): bool => (bool) data_get($plan[$index], 'niche.control_only', false))
            ->reject(fn (int $index): bool => count((array) data_get($plan[$index], 'niche.declared_values', [])) > 1)
            ->values();
        if ($eligible->count() < $limit) {
            $eligible = collect($free)
                ->reject(fn (int $index): bool => count((array) data_get($plan[$index], 'niche.declared_values', [])) > 1)
                ->values();
        }
        $requiredStructuralGenes = ['entry_topology_variant', 'state_machine_variant', 'regime_classifier_variant'];
        $rank = function (int $index) use ($plan, $requiredStructuralGenes): array {
            $gene = (string) data_get($plan[$index], 'niche.declared_gene', '');

            return [
                in_array($gene, $requiredStructuralGenes, true)
                    ? 0
                    : ((bool) data_get($plan[$index], 'niche.structural_research', false)
                        ? 1
                        : ($this->executionLane((array) $plan[$index]) === 'volume' ? 2 : 3)),
                $index,
            ];
        };

        $selected = [];
        $selectedIndexes = [];
        $seen = [];
        for ($pairIndex = 0; $pairIndex < $limit; $pairIndex++) {
            $hostIndexes = array_values(array_filter([
                $free[$pairIndex * 2] ?? null,
                $free[($pairIndex * 2) + 1] ?? null,
            ], fn (mixed $index): bool => is_int($index)));
            $hostBuckets = collect($hostIndexes)
                ->map(fn (int $index): string => $this->templateBucket((array) $plan[$index]))
                ->filter()->unique()->values();
            $available = $eligible
                ->reject(fn (int $index): bool => isset($selectedIndexes[$index]));
            $local = $available
                ->filter(fn (int $index): bool => $hostBuckets->contains($this->templateBucket((array) $plan[$index])))
                ->sortBy($rank)
                ->values();
            $pool = $local->isNotEmpty() ? $local : $available->sortBy($rank)->values();
            $choice = $pool->first(function (int $index) use ($plan, $seen): bool {
                return ! isset($seen[$this->templateSignature((array) $plan[$index])]);
            });
            $choice ??= $pool->first();
            if ($choice === null) {
                break;
            }
            $choice = (int) $choice;
            $selected[] = $choice;
            $selectedIndexes[$choice] = true;
            $seen[$this->templateSignature((array) $plan[$choice])] = true;
        }

        return $selected;
    }

    /** @param array<string,mixed> $slot */
    private function templateBucket(array $slot): string
    {
        return (string) (
            data_get($slot, 'research_group')
            ?: data_get($slot, 'target')
            ?: ((string) data_get($slot, 'family', '')).'|'.$this->executionLane($slot)
        );
    }

    /** @param array<string,mixed> $slot */
    private function templateSignature(array $slot): string
    {
        return (string) (
            data_get($slot, 'niche.hypothesis_family')
            ?: implode('|', [
                (string) data_get($slot, 'family', ''),
                $this->executionLane($slot),
                (string) data_get($slot, 'niche.declared_gene', data_get($slot, 'target', '')),
                json_encode(data_get($slot, 'niche.declared_value'), JSON_PRESERVE_ZERO_FRACTION),
            ])
        );
    }

    /** @param array<string,mixed> $host @param array<string,mixed> $template @return array<string,mixed> */
    private function pairHost(array $host, array $template): array
    {
        return [
            ...$template,
            'research_group' => data_get($host, 'research_group', data_get($template, 'research_group')),
            'group_seat' => data_get($host, 'group_seat', data_get($template, 'group_seat')),
        ];
    }

    /** @param array<string,mixed> $niche @return array<string,mixed> */
    private function withoutIntervention(array $niche): array
    {
        foreach ([
            'declared_gene', 'declared_gene_requested', 'declared_value', 'declared_genes', 'declared_values',
            'structural_research', 'structural_hypothesis_protocol', 'structural_hypothesis_id',
            'structural_operation', 'structural_mutation_required', 'shadow_mutation_gene',
            'shadow_mutation_index', 'entry_topology_variant', 'state_machine_variant',
            'regime_classifier_variant', 'architecture_interaction_variant', 'architecture_experiment',
            'architecture_escape', 'architecture_control_only', 'learning_evolution',
            'learning_receipt_injection',
        ] as $key) {
            unset($niche[$key]);
        }

        return $niche;
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
            if ($role !== '') {
                return $role;
            }
        }
        $origin = (string) data_get($slot, 'origin', '');
        $target = (string) data_get($slot, 'target', '');
        $niche = (array) data_get($slot, 'niche', []);
        $dependencyReallocation = (array) data_get($niche, 'dependency_prerequisite_reallocation', []);
        $explicitLane = (string) data_get($slot, 'allocation_lane', '');
        if (data_get($dependencyReallocation, 'protocol') === 'dependency_prerequisite_reallocation_v1'
            && data_get($dependencyReallocation, 'status') === 'reallocated'
            && in_array($explicitLane, [
                'architecture_signal', 'regime_abstention', 'frozen_control_replication',
            ], true)) {
            return $explicitLane;
        }
        if ($origin === 'targeted_failure_profile' || $origin === 'gate_targeted') {
            return 'targeted_rescue';
        }
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
