<?php

namespace App\Services;

use App\Models\AgentDiagnosis;
use App\Models\AgentFailureCase;
use App\Models\AgentKnowledgeCard;
use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningMutationIntent;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\Candle;
use App\Models\EliteAgentPortfolio;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\MutationMemory;
use App\Models\Symbol;
use App\Services\MarketData\HistoricalDataQualityService;
use App\Services\MarketData\MarketDataContinuityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Internal method group composed exclusively into LabPopulationService. */
trait LabPopulationPlanningTrait
{
    /** @return array<string, mixed> */
    private function populationGroupSeatContract(string $group, int $seat): array
    {
        $definition = self::POPULATION_GROUPS[$group] ?? [
            'label' => $group,
            'axis' => 'diagnostic_reserve',
        ];

        return [
            'protocol' => self::POPULATION_GROUP_PROTOCOL,
            'key' => $group,
            'label' => $definition['label'],
            'axis' => $definition['axis'],
            'seat' => $seat,
            'cohort_size' => self::POPULATION_GROUP_SEATS,
            'search_mode' => $seat <= 2 ? 'depth' : 'breadth',
            'search_role' => match ($seat) {
                1 => 'checkpoint_continuation',
                2 => 'deepening_mutation',
                3 => 'architecture_widening',
                default => 'curiosity_widening',
            },
        ];
    }

    private function researchGroupForTarget(string $target, int $slot): string
    {
        if (array_key_exists($target, self::POPULATION_GROUPS)) {
            return $target;
        }

        $groups = array_keys(self::POPULATION_GROUPS);

        return $groups[max(0, ($slot - 1) % count($groups))];
    }

    /** @param array<int, array<string, mixed>> $plan */
    private function populationGroupContract(array $plan, array $contextualAllocation = []): array
    {
        $dynamic = data_get($contextualAllocation, 'protocol') === ContextualCouncilAllocatorService::PROTOCOL
            && (bool) data_get($contextualAllocation, 'dynamic', false);
        if ($dynamic) {
            return [
                'protocol' => self::POPULATION_GROUP_PROTOCOL,
                'planned_population' => count($plan),
                'core_group_count' => 0,
                'core_seats_per_group' => null,
                'core_population' => count($plan),
                'balanced_core' => false,
                'dynamic_contextual_allocation' => true,
                'contextual_allocator' => $contextualAllocation,
                'experiment_blocks' => (array) data_get($contextualAllocation, 'experiment_blocks', []),
                'module_species' => (array) data_get($contextualAllocation, 'module_species', []),
                'legacy_five_by_four_role' => 'fallback_shadow_or_operator_approved_rescue_only',
                'groups' => [],
                'overflow_seats' => 0,
                'rule' => 'Ordinary seats belong to evidence-ranked causal blocks; no permanent semantic group receives a quota.',
                'promotion_evidence' => false,
            ];
        }

        $groups = collect($plan)
            ->map(function (array $spec, int $index): array {
                $key = (string) ($spec['research_group'] ?? $this->researchGroupForTarget((string) ($spec['target'] ?? ''), $index + 1));

                return [
                    'key' => $key,
                    'seat' => (int) ($spec['group_seat'] ?? 0),
                    'axis' => (string) ($spec['group_axis'] ?? data_get(self::POPULATION_GROUPS, $key.'.axis', 'diagnostic_reserve')),
                    'search_mode' => (string) ($spec['group_search_mode'] ?? 'adaptive_reserve'),
                    'search_role' => (string) ($spec['group_search_role'] ?? 'adaptive_reserve'),
                    'target' => (string) ($spec['target'] ?? ''),
                    'origin' => (string) ($spec['origin'] ?? ''),
                    'rescue_objective' => (string) data_get($spec, 'niche.rescue_lane', ''),
                ];
            })
            ->groupBy('key');

        $groupRows = [];
        foreach (array_keys(self::POPULATION_GROUPS) as $key) {
            $rows = $groups->get($key, collect());
            $groupRows[$key] = [
                ...self::POPULATION_GROUPS[$key],
                'planned_seats' => $rows->count(),
                'seat_numbers' => $rows->pluck('seat')->filter()->values()->all(),
                'search_modes' => $rows->pluck('search_mode')->unique()->values()->all(),
                'targets' => $rows->pluck('target')->filter()->unique()->values()->all(),
                'origins' => $rows->pluck('origin')->filter()->unique()->values()->all(),
                'rescue_objectives' => $rows->pluck('rescue_objective')->filter()->unique()->values()->all(),
                'checkpoint_inheritance' => true,
                'promotion_evidence' => false,
            ];
        }

        $plannedCore = array_sum(array_map(fn (array $row): int => (int) $row['planned_seats'], $groupRows));

        return [
            'protocol' => self::POPULATION_GROUP_PROTOCOL,
            'planned_population' => count($plan),
            'core_group_count' => count(self::POPULATION_GROUPS),
            'core_seats_per_group' => $dynamic ? null : self::POPULATION_GROUP_SEATS,
            'core_population' => $dynamic ? $plannedCore : count(self::POPULATION_GROUPS) * self::POPULATION_GROUP_SEATS,
            'balanced_core' => ! $dynamic && $plannedCore === count(self::POPULATION_GROUPS) * self::POPULATION_GROUP_SEATS
                && collect($groupRows)->every(fn (array $row): bool => (int) $row['planned_seats'] === self::POPULATION_GROUP_SEATS),
            'dynamic_contextual_allocation' => $dynamic,
            'contextual_allocator' => $contextualAllocation ?: null,
            'groups' => $groupRows,
            'overflow_seats' => max(0, count($plan) - $plannedCore),
            'rule' => $dynamic
                ? 'Twenty seats are evidence-weighted contextual candidate/control pairs; group quotas may split, merge, abstain or retire and local evidence never grants global authority.'
                : 'Special-purpose cohorts retain their sealed allocation; they do not define the ordinary twenty-agent council.',
            'promotion_evidence' => false,
        ];
    }

    /**
     * Keep the normal council budget physically balanced as well as
     * conceptually balanced.  A target such as transition_firewall can be a
     * useful experiment inside a group, but it is not itself a sixth group.
     * Prefer the declared target group while it has a free core seat, then
     * place the remaining diagnostic lanes into the least-filled group.  This
     * makes the five-by-four contract deterministic without changing the
     * experiment target or manufacturing a parent.
     *
     * @param  array<int, array<string, mixed>>  $plan
     * @return array<int, array<string, mixed>>
     */
    private function assignPopulationGroupSeats(array $plan): array
    {
        $groupKeys = array_keys(self::POPULATION_GROUPS);
        $counts = array_fill_keys($groupKeys, 0);
        $assigned = [];

        foreach ($plan as $index => $spec) {
            // Targeted rescue seats already declare the research group that
            // owns the failure lane. Preserve that declaration before the
            // normal target-based balancing pass; otherwise abstract targets
            // such as `profit_factor` and `architecture` are redistributed
            // into the wrong groups and the five-by-four cohort loses its
            // intended causal ownership.
            $declared = (string) ($spec['research_group'] ?? '');
            if (($spec['origin'] ?? null) === 'targeted_failure_profile'
                && in_array($declared, $groupKeys, true)
                && $counts[$declared] < self::POPULATION_GROUP_SEATS) {
                $assigned[$index] = $declared;
                $counts[$declared]++;

                continue;
            }
            $target = (string) ($spec['target'] ?? '');
            if (! in_array($target, $groupKeys, true) || $counts[$target] >= self::POPULATION_GROUP_SEATS) {
                continue;
            }
            $assigned[$index] = $target;
            $counts[$target]++;
        }

        foreach ($plan as $index => $spec) {
            if (array_key_exists($index, $assigned)) {
                continue;
            }
            $available = array_values(array_filter(
                $groupKeys,
                fn (string $group): bool => $counts[$group] < self::POPULATION_GROUP_SEATS,
            ));
            if ($available === []) {
                // Seats after the twenty-agent core are explicit overflow;
                // retain a declared group when one exists and otherwise use
                // a stable round-robin reserve. The core remains untouched.
                $declared = (string) ($spec['research_group'] ?? '');
                $assigned[$index] = in_array($declared, $groupKeys, true)
                    ? $declared
                    : $groupKeys[$index % count($groupKeys)];

                continue;
            }
            usort($available, function (string $left, string $right) use ($counts, $groupKeys): int {
                $countOrder = $counts[$left] <=> $counts[$right];
                if ($countOrder !== 0) {
                    return $countOrder;
                }

                return array_search($left, $groupKeys, true) <=> array_search($right, $groupKeys, true);
            });
            $assigned[$index] = $available[0];
            $counts[$available[0]]++;
        }

        $seatCounts = array_fill_keys($groupKeys, 0);
        foreach ($plan as $index => &$spec) {
            $group = $assigned[$index] ?? $groupKeys[$index % count($groupKeys)];
            $seat = ++$seatCounts[$group];
            $spec['research_group'] = $group;
            $spec['group_seat'] = $seat;
            $spec['group_axis'] = self::POPULATION_GROUPS[$group]['axis'];
            $spec['group_search_mode'] = $seat <= 2 ? 'depth' : 'breadth';
            $spec['group_search_role'] = match ($seat) {
                1 => 'checkpoint_continuation',
                2 => 'deepening_mutation',
                3 => 'architecture_widening',
                default => 'curiosity_widening',
            };
        }
        unset($spec);

        return $plan;
    }

    /**
     * Reserve a small structural lane inside every normal research
     * generation.  The ordinary council still keeps its five operating
     * objectives, but a few seats must alter executable topology or regime
     * classification; otherwise the population can report twenty mutations
     * while only changing a wait/threshold scalar.  This method changes plan
     * metadata only.  The constructor below enforces the one-key diff and
     * the paired frozen-control contract before a queue job exists.
     *
     * @return array{plan: array<int, array<string, mixed>>, contract: array<string, mixed>}
     */
    private function normalStructuralResearchPlan(array $plan, AiLaboratory $lab): array
    {
        $families = collect($plan)
            ->map(fn (array $slot): string => (string) data_get($slot, 'family', ''))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $hypotheses = [];
        $assigned = [];

        foreach ($families as $family) {
            $supportsRouterTopology = in_array($family, ['hybrid', 'differential_router'], true);
            $familyHypotheses = $supportsRouterTopology
                ? [
                    [
                        'gene' => 'entry_topology_variant',
                        'value' => 'regime_consensus_v1',
                        'operation' => 'regime_conditioned_entry_topology',
                    ],
                    [
                        'gene' => 'state_machine_variant',
                        'value' => 'neutral_transition_cooldown_reentry_v1',
                        'operation' => 'transition_state_machine_reentry',
                    ],
                    [
                        'gene' => 'regime_classifier_variant',
                        'value' => 'adx_hysteresis_v1',
                        'operation' => 'closed_regime_classifier_hysteresis',
                    ],
                ]
                : [
                    [
                        'gene' => 'regime_classifier_variant',
                        'value' => 'adx_hysteresis_v1',
                        'operation' => 'closed_regime_classifier_hysteresis',
                    ],
                    [
                        'gene' => 'state_machine_variant',
                        'value' => 'neutral_transition_cooldown_reentry_v1',
                        'operation' => 'transition_state_machine_reentry',
                    ],
                ];
            $available = collect($plan)
                ->keys()
                ->filter(fn (int $index): bool => (string) data_get($plan[$index], 'family', '') === $family)
                ->filter(fn (int $index): bool => ! (bool) data_get($plan[$index], 'niche.control_only', false))
                // The three causal roles own a separate counterfactual
                // contract. They must remain intact and must not be borrowed
                // to satisfy the seventeen-seat discovery diversity budget.
                ->reject(fn (int $index): bool => $this->isCausalLearningSeat($plan[$index]))
                ->reject(fn (int $index): bool => in_array($index, $assigned, true))
                ->values()
                ->all();
            // Reserve a material structural share for every executable
            // family. The old one-third rule left small families with one
            // structural seat and allowed a generation to become mostly
            // transition-wait/cooldown probes. At least 35% of a family lane
            // is now structural, while the explicit hypothesis list still
            // bounds the number of distinct executable genes.
            $seatCount = min(count($familyHypotheses), max(1, (int) ceil(count($available) * .35)));
            foreach (array_slice($familyHypotheses, 0, $seatCount) as $offset => $hypothesis) {
                $index = $available[$offset] ?? null;
                if ($index === null) {
                    continue;
                }
                $assigned[] = $index;
                $niche = (array) data_get($plan[$index], 'niche', []);
                unset(
                    $niche['declared_gene'],
                    $niche['declared_value'],
                    $niche['entry_topology_variant'],
                    $niche['state_machine_variant'],
                    $niche['regime_classifier_variant'],
                    $niche['architecture_experiment'],
                    $niche['architecture_escape'],
                );
                $niche['structural_research'] = true;
                $niche['structural_hypothesis_protocol'] = 'normal_structural_hypothesis_v1';
                $niche['structural_operation'] = $hypothesis['operation'];
                $niche['declared_gene'] = $hypothesis['gene'];
                $niche['declared_value'] = $hypothesis['value'];
                $niche[$hypothesis['gene']] = $hypothesis['value'];
                $niche['structural_hypothesis_id'] = hash('sha256', json_encode([
                    'protocol' => 'normal_structural_hypothesis_v1',
                    'family' => $family,
                    'gene' => $hypothesis['gene'],
                    'value' => $hypothesis['value'],
                    'slot' => $index + 1,
                ], JSON_UNESCAPED_SLASHES));
                $niche['structural_mutation_required'] = true;
                $niche['shadow_only'] = false;
                $niche['hypothesis_contract'] = [
                    'protocol' => 'pre_registered_prediction_v1',
                    'falsifiable_statement' => match ($hypothesis['gene']) {
                        'regime_classifier_variant' => 'The closed regime classifier must reroute transition/regime cells and improve survival versus the frozen control.',
                        'state_machine_variant' => 'The state machine must change transition entry/exit decisions and reduce false transition losses without non-target regression.',
                        'entry_topology_variant' => 'The entry topology must change accepted signal cells and improve the declared target in at least two independent windows.',
                        default => 'The structural operation must change the declared decision surface and improve its target versus the frozen control.',
                    },
                    'expected_behavioral_delta' => [
                        'decision_ledger_changed' => true,
                        'trade_or_abstention_ledger_changed' => true,
                        'non_target_regression' => false,
                    ],
                    'non_target_invariants' => ['no_calendar_feature', 'no_gate_bypass', 'no_unbounded_drawdown'],
                    'expected_window' => 'chronological_validation_and_sealed_holdout',
                    'promotion_evidence' => false,
                ];
                $niche['promotion_evidence'] = false;
                $plan[$index]['niche'] = $niche;
                $hypotheses[] = [
                    'slot' => $index + 1,
                    'family' => $family,
                    'gene' => $hypothesis['gene'],
                    'value' => $hypothesis['value'],
                    'operation' => $hypothesis['operation'],
                    'hypothesis_contract' => $niche['hypothesis_contract'],
                ];
            }
        }

        // Fallback: diversity-contract shortfall repair.
        // The per-family 35% structural seat rule can leave the overall cohort
        // short of the 25% structural minimum while the scalar share breaches
        // the 75% ceiling. Reclassifying an existing scalar candidate into a
        // structural hypothesis seat repairs BOTH constraints at once — the
        // structural count rises and the scalar share falls — without lowering
        // either limit and without adding new candidate rows. Only the exact
        // shortfall is reclassified, and only into genes whose signature is not
        // already over-replicated (max 2 per signature), so no seat becomes a
        // structural clone.
        $diversityCandidates = collect($plan)->filter(fn (array $slot): bool => ! (bool) data_get($slot, 'niche.control_only', false)
            && ! $this->isCausalLearningSeat($slot));
        $existingStructural = $diversityCandidates->filter(fn (array $slot): bool => (bool) data_get($slot, 'niche.structural_research', false));
        $minimumStructural = max(2, (int) ceil($diversityCandidates->count() * .25));
        $shortfall = $minimumStructural - $existingStructural->count();
        if ($shortfall > 0) {
            $geneValuePairs = [
                ['entry_topology_variant', 'regime_consensus_v1', 'regime_conditioned_entry_topology'],
                ['state_machine_variant', 'neutral_transition_cooldown_reentry_v1', 'transition_state_machine_reentry'],
                ['regime_classifier_variant', 'adx_hysteresis_v1', 'closed_regime_classifier_hysteresis'],
            ];
            $existingSignatures = $existingStructural
                ->filter(fn (array $slot): bool => (string) data_get($slot, 'niche.declared_gene', '') !== '')
                ->map(fn (array $slot): string => (string) data_get($slot, 'family', '').'|'.(string) data_get($slot, 'niche.declared_gene').'|'.(string) data_get($slot, 'niche.declared_value'))
                ->countBy();
            $promoted = 0;
            foreach ($plan as $index => $slot) {
                if ($promoted >= $shortfall) {
                    break;
                }
                if ((bool) data_get($slot, 'niche.control_only', false)) {
                    continue;
                }
                if ($this->isCausalLearningSeat($slot)) {
                    continue;
                }
                if ((bool) data_get($slot, 'niche.structural_research', false)) {
                    continue;
                }

                $chosen = null;
                foreach ($geneValuePairs as [$gene, $value, $operation]) {
                    $signature = (string) data_get($slot, 'family', '').'|'.$gene.'|'.$value;
                    if ((int) ($existingSignatures[$signature] ?? 0) < 2) {
                        $chosen = [$gene, $value, $operation, $signature];
                        break;
                    }
                }
                if ($chosen === null) {
                    continue;
                }
                [$gene, $value, $operation, $signature] = $chosen;

                $niche = (array) data_get($plan[$index], 'niche', []);
                unset(
                    $niche['declared_gene'],
                    $niche['declared_value'],
                    $niche['entry_topology_variant'],
                    $niche['state_machine_variant'],
                    $niche['regime_classifier_variant'],
                    $niche['architecture_experiment'],
                    $niche['architecture_escape'],
                );
                $niche['structural_research'] = true;
                $niche['structural_hypothesis_protocol'] = 'normal_structural_hypothesis_v1';
                $niche['structural_operation'] = $operation;
                $niche['declared_gene'] = $gene;
                $niche['declared_value'] = $value;
                $niche[$gene] = $value;
                $niche['structural_hypothesis_id'] = hash('sha256', json_encode([
                    'protocol' => 'normal_structural_hypothesis_v1',
                    'family' => (string) data_get($plan[$index], 'family', ''),
                    'gene' => $gene,
                    'value' => $value,
                    'slot' => $index + 1,
                    'fallback' => 'mutation_diversity_shortfall',
                ], JSON_UNESCAPED_SLASHES));
                $niche['structural_mutation_required'] = true;
                $niche['shadow_only'] = false;
                $niche['hypothesis_contract'] = [
                    'protocol' => 'pre_registered_prediction_v1',
                    'falsifiable_statement' => 'The reclassified structural operation must change the declared decision surface and improve its target versus the frozen control.',
                    'expected_behavioral_delta' => [
                        'decision_ledger_changed' => true,
                        'trade_or_abstention_ledger_changed' => true,
                        'non_target_regression' => false,
                    ],
                    'non_target_invariants' => ['no_calendar_feature', 'no_gate_bypass', 'no_unbounded_drawdown'],
                    'expected_window' => 'chronological_validation_and_sealed_holdout',
                    'promotion_evidence' => false,
                ];
                $niche['promotion_evidence'] = false;
                $plan[$index]['niche'] = $niche;
                $existingSignatures[$signature] = ($existingSignatures[$signature] ?? 0) + 1;
                $hypotheses[] = [
                    'slot' => $index + 1,
                    'family' => (string) data_get($plan[$index], 'family', ''),
                    'gene' => $gene,
                    'value' => $value,
                    'operation' => $operation,
                    'mode' => 'mutation_diversity_fallback',
                    'hypothesis_contract' => $niche['hypothesis_contract'],
                ];
                $promoted++;
            }
        }

        return [
            'plan' => array_values($plan),
            'contract' => [
                'protocol' => 'normal_structural_research_v1',
                'mode' => 'normal_research',
                'hypothesis' => 'Executable entry/exit topology and closed regime classification must be tested alongside scalar lanes.',
                'candidate_required' => true,
                'control_pair_required' => true,
                'structural_candidate_count' => count($hypotheses),
                'structural_candidates' => $hypotheses,
                'families_covered' => collect($hypotheses)->pluck('family')->unique()->values()->all(),
                'no_calendar_feature' => true,
                'mutation_diversity_contract' => [
                    'protocol' => 'mutation_diversity_contract_v1',
                    'scalar_wait_or_threshold_share_maximum' => .75,
                    'structural_candidate_share_minimum' => .25,
                    'behavioral_effect_required' => true,
                    'promotion_evidence' => false,
                ],
                'promotion_evidence' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function normalMutationDiversityContract(array $plan): array
    {
        $candidates = collect($plan)->filter(fn (array $slot): bool => ! (bool) data_get($slot, 'niche.control_only', false)
            && ! $this->isCausalLearningSeat($slot));
        $structural = $candidates->filter(fn (array $slot): bool => (bool) data_get($slot, 'niche.structural_research', false));
        $genes = $structural->pluck('niche.declared_gene')->filter()->unique()->values()->all();
        $candidateCount = $candidates->count();
        $structuralCount = $structural->count();
        $routerStructuralCount = $structural->filter(fn (array $slot): bool => in_array(
            (string) data_get($slot, 'family', ''), ['hybrid', 'differential_router'], true,
        ))->count();
        $minimumStructural = max(2, (int) ceil($candidateCount * .25));
        // The topology policy is explicit: every normal evolution cohort must
        // materialize all three executable topology/classifier genes. A
        // smaller router lane is a planning failure, not permission to weaken
        // the contract to two genes.
        $requiredGenes = ['entry_topology_variant', 'state_machine_variant', 'regime_classifier_variant'];
        $missingGenes = array_values(array_diff($requiredGenes, $genes));
        $scalarShare = $candidateCount > 0
            ? round(($candidateCount - $structuralCount) / $candidateCount, 4)
            : 1.0;
        // A compiler may reserve an open exploration seat whose exact gene
        // is selected only by the constructor. Several such placeholders are
        // not clones; hashing their shared null declaration incorrectly
        // aborted otherwise diverse cohorts before construction. Exact clone
        // protection applies to declared, executable hypotheses here and to
        // persisted parameter fingerprints at construction time.
        $mutationSignatures = $candidates
            ->filter(fn (array $slot): bool => (string) data_get($slot, 'niche.declared_gene', '') !== '')
            ->map(fn (array $slot): string => hash('sha256', json_encode([
                'family' => data_get($slot, 'family'),
                'gene' => data_get($slot, 'niche.declared_gene'),
                'value' => data_get($slot, 'niche.declared_value'),
                'topology' => data_get($slot, 'niche.entry_topology_variant', data_get($slot, 'niche.state_machine_variant')),
                // The same bounded intervention in London and Asia is a
                // contextual replication, not an identical clone. Within one
                // market-context cell the two-replicate ceiling still holds.
                'market_context_hash' => data_get(
                    $slot,
                    'niche.contextual_specialist_cell.market_context_hash',
                    data_get($slot, 'niche.contextual_specialist_cell.cell_hash'),
                ),
            ], JSON_UNESCAPED_SLASHES)))
            ->countBy();
        $overReplicated = $mutationSignatures->filter(fn (int $count): bool => $count > 2);
        $allowed = $candidateCount > 0
            && $structuralCount >= $minimumStructural
            && $missingGenes === []
            && $scalarShare <= .75
            && $overReplicated->isEmpty();

        return [
            'protocol' => 'mutation_diversity_contract_v1',
            'allowed' => $allowed,
            'candidate_count' => $candidateCount,
            'structural_candidate_count' => $structuralCount,
            'router_structural_candidate_count' => $routerStructuralCount,
            'minimum_structural_candidate_count' => $minimumStructural,
            'structural_genes' => $genes,
            'required_genes' => $requiredGenes,
            'missing_genes' => $missingGenes,
            'scalar_wait_or_threshold_share' => $scalarShare,
            'scalar_wait_or_threshold_share_maximum' => .75,
            'maximum_identical_mutation_replicates' => 2,
            'over_replicated_mutation_signatures' => $overReplicated->all(),
            'behavioral_effect_required' => true,
            'promotion_evidence' => false,
            'reason' => $allowed ? 'DIVERSITY_CONTRACT_VALID' : 'DIVERSITY_CONTRACT_BLOCKED',
        ];
    }

    /**
     * Reconcile the structural floor after control pairing has finalized
     * control_only seats. Pairing is allowed to change roles, so the final
     * candidate population—not the pre-pairing draft—is the admission truth.
     *
     * @return array{plan: array<int, array<string, mixed>>, repaired: bool, audit: array<string, mixed>}
     */
    private function ensureFinalStructuralFloor(array $plan): array
    {
        $candidates = collect($plan)->filter(fn (array $slot): bool => ! (bool) data_get($slot, 'niche.control_only', false)
            && ! $this->isCausalLearningSeat($slot));
        $structural = $candidates->filter(fn (array $slot): bool => (bool) data_get($slot, 'niche.structural_research', false));
        $candidateCount = $candidates->count();
        $minimum = max(2, (int) ceil($candidateCount * .25));
        $requiredGenes = ['entry_topology_variant', 'state_machine_variant', 'regime_classifier_variant'];
        $existingGenes = $structural->pluck('niche.declared_gene')->filter()->unique()->values()->all();
        $missingGenes = array_values(array_diff($requiredGenes, $existingGenes));
        $missingGenesBefore = $missingGenes;
        $shortfall = max($minimum - $structural->count(), count($missingGenes));
        if ($shortfall <= 0) {
            return ['plan' => $plan, 'repaired' => false, 'audit' => []];
        }

        $geneValues = [
            'entry_topology_variant' => ['value' => 'regime_consensus_v1', 'operation' => 'regime_conditioned_entry_topology'],
            'state_machine_variant' => ['value' => 'neutral_transition_cooldown_reentry_v1', 'operation' => 'transition_state_machine_reentry'],
            'regime_classifier_variant' => ['value' => 'adx_hysteresis_v1', 'operation' => 'closed_regime_classifier_hysteresis'],
        ];
        $selected = [];
        $signatureCounts = $structural
            ->filter(fn (array $slot): bool => (string) data_get($slot, 'niche.declared_gene', '') !== '')
            ->map(fn (array $slot): string => (string) data_get($slot, 'family', '').'|'.(string) data_get($slot, 'niche.declared_gene').'|'.(string) data_get($slot, 'niche.declared_value'))
            ->countBy()
            ->all();
        foreach ($plan as $index => $slot) {
            if (($structural->count() + count($selected) >= $minimum && $missingGenes === [])
                || (bool) data_get($slot, 'niche.control_only', false)
                || $this->isCausalLearningSeat($slot)) {
                continue;
            }
            if ((bool) data_get($slot, 'niche.structural_research', false)) {
                continue;
            }
            $gene = collect([...$missingGenes, ...$requiredGenes])
                ->first(function (string $candidateGene) use ($slot, $geneValues, $signatureCounts): bool {
                    $definition = $geneValues[$candidateGene];
                    $signature = (string) data_get($slot, 'family', '').'|'.$candidateGene.'|'.$definition['value'];

                    return (int) ($signatureCounts[$signature] ?? 0) < 2;
                });
            if (! is_string($gene) || $gene === '') {
                continue;
            }
            $definition = $geneValues[$gene];
            $signature = (string) data_get($slot, 'family', '').'|'.$gene.'|'.$definition['value'];
            $niche = (array) data_get($plan[$index], 'niche', []);
            unset($niche['declared_gene'], $niche['declared_value'], $niche['entry_topology_variant'], $niche['state_machine_variant'], $niche['regime_classifier_variant']);
            $niche['structural_research'] = true;
            $niche['structural_hypothesis_protocol'] = 'normal_structural_hypothesis_v1';
            $niche['structural_operation'] = $definition['operation'];
            $niche['declared_gene'] = $gene;
            $niche['declared_value'] = $definition['value'];
            $niche[$gene] = $definition['value'];
            $niche['structural_mutation_required'] = true;
            $niche['shadow_only'] = false;
            $niche['structural_hypothesis_id'] = hash('sha256', json_encode([
                'protocol' => 'normal_structural_hypothesis_v1',
                'final_pairing_repair' => true,
                'family' => data_get($plan[$index], 'family'),
                'gene' => $gene,
                'value' => $definition['value'],
                'slot' => $index + 1,
            ], JSON_UNESCAPED_SLASHES));
            $niche['hypothesis_contract'] = [
                'protocol' => 'pre_registered_prediction_v1',
                'falsifiable_statement' => 'The final structural repair must change the declared decision surface versus the frozen control; promotion evidence remains withheld.',
                'expected_behavioral_delta' => ['decision_ledger_changed' => true, 'trade_or_abstention_ledger_changed' => true, 'non_target_regression' => false],
                'promotion_evidence' => false,
            ];
            $niche['promotion_evidence'] = false;
            $plan[$index]['niche'] = $niche;
            $selected[$index + 1] = $gene;
            $signatureCounts[$signature] = ($signatureCounts[$signature] ?? 0) + 1;
            $missingGenes = array_values(array_diff($missingGenes, [$gene]));
        }

        $after = collect($plan)->filter(fn (array $slot): bool => ! (bool) data_get($slot, 'niche.control_only', false)
            && ! $this->isCausalLearningSeat($slot))
            ->filter(fn (array $slot): bool => (bool) data_get($slot, 'niche.structural_research', false));
        $afterGenes = $after->pluck('niche.declared_gene')->filter()->unique()->values()->all();

        return [
            'plan' => array_values($plan),
            'repaired' => $selected !== [],
            'audit' => [
                'protocol' => 'final_structural_floor_repair_v1',
                'candidate_count' => $candidateCount,
                'minimum_structural' => $minimum,
                'before_structural' => $structural->count(),
                'after_structural' => $after->count(),
                'required_genes' => $requiredGenes,
                'missing_genes_before' => $missingGenesBefore,
                'repaired_slots' => $selected,
                'structural_genes_after' => $afterGenes,
                'promotion_evidence' => false,
            ],
        ];
    }

    /**
     * Causal confirmation/repair is a fixed three-arm experiment embedded in
     * the ordinary population, not part of its discovery diversity quota.
     */
    private function isCausalLearningSeat(array $slot): bool
    {
        return (int) data_get($slot, 'niche.causal_confirmation_source_lesson_id', 0) > 0
            || (int) data_get($slot, 'niche.prospective_repair_source_pair_id', 0) > 0
            || (int) data_get($slot, 'niche.causal_repair_source_experiment_id', 0) > 0
            || (array) data_get($slot, 'niche.causal_learning_cohort', []) !== [];
    }

    /**
     * Root experiments are planned before control pairing, but validated
     * after it. This prevents a control materializer or generic planner flag
     * from turning a bold, falsifiable intervention into a clone or a
     * no-op. It is an admission contract, never a screening-gate relaxation.
     *
     * @param  array<int, array<string, mixed>>  $plan
     * @return array<string, mixed>
     */
    private function rootPortfolioIntegrityContract(array $plan): array
    {
        $rootSeats = collect($plan)
            ->filter(fn (array $seat): bool => (bool) data_get($seat, 'niche.root_experiment_portfolio', false));
        $controls = $rootSeats->filter(fn (array $seat): bool => (bool) data_get($seat, 'niche.control_only', false));
        $candidates = $rootSeats->reject(fn (array $seat): bool => (bool) data_get($seat, 'niche.control_only', false));
        $violations = [];
        $mutationSignatures = [];
        $behaviorSignatures = [];
        $hypothesisFamilies = [];

        foreach ($candidates as $index => $seat) {
            $niche = (array) data_get($seat, 'niche', []);
            $gene = (string) data_get($niche, 'declared_gene', '');
            $valueDeclared = array_key_exists('declared_value', $niche);
            $falsification = (array) data_get($niche, 'behavioral_falsification', []);
            $axes = array_values(array_filter(array_map('strval', (array) data_get($falsification, 'axes', []))));
            $expectedChange = (array) data_get($falsification, 'expected_change', []);
            $hypothesisFamily = (string) data_get($niche, 'hypothesis_family', '');
            $pair = (array) data_get($niche, 'control_pair_contract', []);
            $pairValid = (bool) data_get($pair, 'required_for_candidate', false)
                && (bool) data_get($pair, 'same_generation', false)
                && (bool) data_get($pair, 'same_strategy_family', false)
                && (string) data_get($pair, 'strategy_family', '') === (string) data_get($seat, 'family', '');
            if ($gene === '' || ! $valueDeclared || $axes === [] || $expectedChange === [] || $hypothesisFamily === '' || ! $pairValid) {
                $violations[] = ['slot' => (int) $index + 1, 'gene' => $gene !== '' ? $gene : null,
                    'declared_value_present' => $valueDeclared, 'hypothesis_family' => $hypothesisFamily ?: null,
                    'behavior_axes' => $axes, 'expected_change' => $expectedChange, 'pair_valid' => $pairValid];

                continue;
            }
            $mutationSignature = hash('sha256', json_encode([
                'family' => data_get($seat, 'family'), 'gene' => $gene, 'value' => data_get($niche, 'declared_value'),
            ], JSON_PRESERVE_ZERO_FRACTION));
            $behaviorSignature = hash('sha256', json_encode([
                'family' => data_get($seat, 'family'), 'lane' => data_get($niche, 'experiment_lane'),
                'gene' => $gene, 'value' => data_get($niche, 'declared_value'), 'axes' => $axes,
            ], JSON_PRESERVE_ZERO_FRACTION));
            $mutationSignatures[$mutationSignature] = ($mutationSignatures[$mutationSignature] ?? 0) + 1;
            $behaviorSignatures[$behaviorSignature] = ($behaviorSignatures[$behaviorSignature] ?? 0) + 1;
            $hypothesisFamilies[$hypothesisFamily] = ($hypothesisFamilies[$hypothesisFamily] ?? 0) + 1;
        }
        $overReplicatedMutation = array_filter($mutationSignatures, fn (int $count): bool => $count > 2);
        $overReplicatedBehavior = array_filter($behaviorSignatures, fn (int $count): bool => $count > 2);
        $overBudgetHypothesisFamilies = array_filter($hypothesisFamilies, fn (int $count): bool => $count > 1);
        $laneCounts = $candidates->countBy(fn (array $seat): string => (string) data_get($seat, 'niche.experiment_lane', 'unknown'))->all();
        $candidatePairKeys = $candidates->pluck('niche.control_pair_contract.pair_key')->filter()->values();
        $controlPairKeys = $controls->pluck('niche.control_pair_contract.pair_key')->filter()->values();
        $exactOneToOnePairs = $candidatePairKeys->count() === 10
            && $controlPairKeys->count() === 10
            && $candidatePairKeys->unique()->count() === 10
            && $controlPairKeys->unique()->count() === 10
            && $candidatePairKeys->sort()->values()->all() === $controlPairKeys->sort()->values()->all();
        $allowed = $rootSeats->count() === 20
            && $controls->count() === 10
            && $candidates->count() === 10
            && $exactOneToOnePairs
            && count($laneCounts) >= 5
            && $violations === []
            && $overReplicatedMutation === []
            && $overReplicatedBehavior === []
            && $overBudgetHypothesisFamilies === []
            && count($hypothesisFamilies) >= 10;

        return [
            'protocol' => 'root_portfolio_post_pairing_integrity_v1',
            'allowed' => $allowed,
            'root_seats' => $rootSeats->count(), 'controls' => $controls->count(), 'candidates' => $candidates->count(),
            'lane_counts' => $laneCounts,
            'minimum_distinct_lanes' => 5,
            'exact_one_to_one_pairs' => $exactOneToOnePairs,
            'declaration_or_pairing_violations' => $violations,
            'over_replicated_mutations' => $overReplicatedMutation,
            'over_replicated_behavioral_hypotheses' => $overReplicatedBehavior,
            'hypothesis_family_counts' => $hypothesisFamilies,
            'independent_candidate_hypothesis_families' => count($hypothesisFamilies),
            'minimum_independent_candidate_hypothesis_families' => 10,
            'maximum_seats_per_hypothesis_family' => 1,
            'over_budget_hypothesis_families' => $overBudgetHypothesisFamilies,
            'rule' => 'Every intervention keeps its declared value, expected behavioral change, falsifiable axes and one unique exact same-generation paired control; no hypothesis family receives more than one candidate seat.',
            'promotion_evidence' => false,
        ];
    }

    /**
     * Preserve the single-anchor cohort shape. It is intentionally not
     * balanced into the normal five-group council: its contract is four
     * mutation siblings plus one control for one declared failure target.
     * The ordinary population-group contract remains present as a diagnostic
     * projection, with balanced_core=false, so lifecycle audits stay
     * fail-closed without misclassifying the bounded rescue as corruption.
     *
     * @param  array<int, array<string, mixed>>  $plan
     * @return array<int, array<string, mixed>>
     */
    private function assignAnchorCohortSeats(array $plan): array
    {
        foreach ($plan as $index => &$spec) {
            $group = (string) ($spec['research_group'] ?? 'volatility_session_stability');
            $seat = $index + 1;
            $spec['group_seat'] = $seat;
            $spec['group_axis'] = data_get(self::POPULATION_GROUPS, $group.'.axis', 'targeted_failure_repair');
            $spec['group_search_mode'] = $seat <= 2 ? 'depth' : 'breadth';
            $spec['group_search_role'] = match ($seat) {
                1 => 'primary_direction',
                2 => 'reverse_direction',
                3 => 'alternative_gene',
                4 => 'secondary_alternative_or_architecture',
                default => 'frozen_control',
            };
            $spec['niche']['repair_cohort_contract'] = 'four_siblings_plus_control_v1';
            $spec['niche']['control_recompute_required'] = true;
        }
        unset($spec);

        return $plan;
    }

    /**
     * Fill the normal core with real council experiments when the failure
     * curriculum contains fewer than twenty lanes.  The old implementation
     * filled the remainder with generic `robustness`/`architecture` labels;
     * those labels are not executable mutation targets for every family and
     * could silently reduce a pair's population to eighteen. Reusing an
     * existing council niche (or a deterministic family fallback) preserves
     * the semantic contract and lets the normal one-gene compiler choose a
     * lawful bounded change.
     *
     * @param  array<int, array<string, mixed>>  $plan
     * @return array<int, array<string, mixed>>
     */
    private function fillNormalCouncilCore(array $plan, AiLaboratory $lab, int $targetPopulation): array
    {
        $groupKeys = array_keys(self::POPULATION_GROUPS);
        $required = min($targetPopulation, count($groupKeys) * self::POPULATION_GROUP_SEATS);
        if ($required <= 0) {
            return [];
        }

        $family = in_array('differential_router', $lab->strategy_families, true)
            ? 'differential_router'
            : (in_array('hybrid', $lab->strategy_families, true) ? 'hybrid' : ($lab->strategy_families[0] ?? 'hybrid'));
        $fallback = collect($plan)->first(fn (array $spec): bool => ($spec['target'] ?? null) === 'portfolio_router')
            ?? collect($plan)->first(fn (array $spec): bool => ($spec['family'] ?? null) === 'hybrid')
            ?? ($plan[0] ?? [
                'origin' => 'g98_council',
                'family' => $family,
                'target' => 'portfolio_router',
                'niche' => [],
            ]);

        $counts = array_fill_keys($groupKeys, 0);
        $kept = [];
        $replaceable = [];
        foreach (array_slice($plan, 0, $required) as $spec) {
            // A causal confirmation or repair frontier is a reserved proof
            // seat, not an unknown legacy council target. Keeping it here is
            // what lets the downstream materializer bind the three arms to
            // one source lesson/experiment and one frozen baseline.
            if ($this->isCausalLearningSeat($spec)) {
                $kept[] = $spec;

                continue;
            }
            $target = (string) ($spec['target'] ?? '');
            if (in_array($target, $groupKeys, true) && $counts[$target] < self::POPULATION_GROUP_SEATS) {
                $kept[] = $spec;
                $counts[$target]++;
            } else {
                // Unknown/overflow targets are still useful research context,
                // but they cannot consume one of the five core target seats.
                $replaceable[] = $spec;
            }
        }

        $missingTargets = [];
        foreach ($groupKeys as $target) {
            for ($seat = $counts[$target] + 1; $seat <= self::POPULATION_GROUP_SEATS; $seat++) {
                $missingTargets[] = $target;
            }
        }

        foreach ($missingTargets as $index => $target) {
            if (count($kept) >= $required) {
                break;
            }
            $targetTemplate = collect($plan)->first(fn (array $spec): bool => ($spec['target'] ?? null) === $target);
            $template = $targetTemplate ?? ($replaceable[$index] ?? $fallback);
            $families = array_values($lab->strategy_families);
            $familyForSeat = $targetTemplate['family'] ?? ($families[$index % max(1, count($families))] ?? $family);
            $niche = (array) ($template['niche'] ?? []);
            $kept[] = [
                ...$template,
                'origin' => 'g98_council',
                'family' => $familyForSeat,
                'target' => $target,
                'niche' => [
                    ...$niche,
                    'protocol' => 'portfolio_council_v1',
                    'objective' => $target,
                    'mutation_target' => $target,
                    'research_reason' => 'balanced_core_group_seat_fill',
                    'balanced_core_reserve_variant' => $index + 1,
                    'non_target_parent_freeze' => true,
                    'promotion_rule' => $target === 'portfolio_router'
                        ? 'combined_portfolio_only'
                        : 'standalone_forward_passport_required',
                ],
            ];
        }

        // If a curriculum was unusually sparse, keep filling from the
        // deterministic fallback until every core target has four seats.
        while (count($kept) < $required) {
            $target = $groupKeys[count($kept) % count($groupKeys)];
            $families = array_values($lab->strategy_families);
            $familyForSeat = $families[count($kept) % max(1, count($families))] ?? $family;
            $kept[] = [
                ...$fallback,
                'origin' => 'g98_council',
                'family' => $familyForSeat,
                'target' => $target,
                'niche' => [
                    ...(array) ($fallback['niche'] ?? []),
                    'protocol' => 'portfolio_council_v1',
                    'objective' => $target,
                    'mutation_target' => $target,
                    'research_reason' => 'balanced_core_group_seat_fill',
                    'non_target_parent_freeze' => true,
                    'promotion_rule' => $target === 'portfolio_router'
                        ? 'combined_portfolio_only'
                        : 'standalone_forward_passport_required',
                ],
            ];
        }

        $overflow = count($plan) > $required ? array_slice($plan, $required) : [];

        return [...array_slice($kept, 0, $required), ...$overflow];
    }

    /**
     * Read the last durable group report as context for the next generation.
     * It never manufactures a parent: strict semantic parent eligibility is
     * still evaluated independently by createAgent().
     *
     * @return array<string, array<string, mixed>>
     */
    private function latestGroupCheckpoints(AiLaboratory $lab): array
    {
        $generations = $lab->generations()
            ->whereIn('status', self::TERMINAL_GENERATION_STATUSES)
            ->latest('generation')
            ->take(5)
            ->get(['trigger_context']);

        foreach ($generations as $generation) {
            $checkpoints = (array) data_get(
                $generation->trigger_context,
                'latest_generation_report.population_group_checkpoints',
                [],
            );
            if ($checkpoints !== []) {
                return $checkpoints;
            }
        }

        return [];
    }

    /**
     * The default 20-slot budget remains stable across generations so observed
     * gate transitions can be compared. The budget is configurable when a
     * laboratory needs a wider search. Family selection is deficit-weighted:
     * the greatest unsatisfied forward gate gets the earliest experiments.
     */
    private function generationPlan(AiLaboratory $lab, array $coverageRescue = [], bool $roleComplete = false, ?int $populationLimit = null, array $targetedFailureTargets = [], ?array $targetedFailureProfile = null): array
    {
        $targetPopulation = $roleComplete
            ? max(4, $populationLimit !== null ? (int) $populationLimit : $this->configuredPopulationSize())
            : ($populationLimit !== null ? max(1, (int) $populationLimit) : $this->configuredPopulationSize());
        if ((bool) data_get($coverageRescue, 'eligible')) {
            return $this->coverageRescuePlan($coverageRescue, $targetPopulation);
        }
        if ($targetedFailureTargets !== []) {
            if ((string) data_get($targetedFailureProfile, 'protocol') === self::TARGETED_RESCUE_PROFILE_PROTOCOL) {
                if ((string) data_get($targetedFailureProfile, 'cohort_mode') === 'four_siblings_plus_control_v1') {
                    return $this->anchorSiblingPlan($lab, $targetedFailureProfile ?? [], $targetPopulation);
                }
                if ((string) data_get($targetedFailureProfile, 'cohort_mode') === StructuralResearchCohortService::COHORT_MODE) {
                    return app(StructuralResearchCohortService::class)->plan($lab, $targetedFailureProfile ?? []);
                }
                if ($targetPopulation >= count(self::POPULATION_GROUPS) * self::POPULATION_GROUP_SEATS) {
                    return $this->fiveByFourTargetedFailurePlan($lab, $targetedFailureProfile ?? []);
                }
            }

            return $this->targetedFailurePlan($lab, $targetedFailureTargets, $targetPopulation, $targetedFailureProfile ?? []);
        }
        // A role-complete build may be intentionally bounded to the four
        // mandatory seats while its constructor/lineage contract is being
        // proven.  Do not route that request through the generic root-recovery
        // plan: it would create four control roots and silently omit the
        // council roles.  The caller still enforces a minimum of four seats.
        if ($populationLimit !== null && $roleComplete) {
            return $this->mandatoryCouncilRolePlan($lab);
        }
        if ($populationLimit !== null) {
            return $this->boundedRootRecoveryPlan($lab, max(1, $populationLimit));
        }
        $families = $this->prioritizedFamilies($lab);
        $matrixFrontier = $this->robustnessMatrixFrontier($lab);
        $explorationOnly = $this->explorationOnlyFamilies($lab);
        $nonExploratoryFamilies = array_values(array_filter(
            $families,
            fn (array $evidence) => ! in_array($evidence['family'], $explorationOnly, true),
        ));
        // G98 is a failure-eliminator, not a PF maximizer. The configured
        // budget starts with five causal layers; additional seats are explicit
        // robust/architecture/curiosity experiments so a larger population
        // expands the search instead of cloning the old 20-slot shape.
        $slots = [];
        // Keep the historical causal baseline at twenty seats; a larger
        // population is deliberately spent on new search paths instead of
        // multiplying the same repair lane indefinitely.
        $causalRepeats = min(4, max(1, intdiv($targetPopulation, 5)));
        foreach (array_keys(self::POPULATION_GROUPS) as $lane) {
            foreach (range(1, $causalRepeats) as $groupSeat) {
                $slots[] = [
                    'origin' => 'g98_council',
                    'target' => $lane,
                    'research_group' => $lane,
                    'group_seat' => $groupSeat,
                    'group_axis' => self::POPULATION_GROUPS[$lane]['axis'],
                    'group_search_mode' => $groupSeat <= 2 ? 'depth' : 'breadth',
                    'group_search_role' => match ($groupSeat) {
                        1 => 'checkpoint_continuation',
                        2 => 'deepening_mutation',
                        3 => 'architecture_widening',
                        default => 'curiosity_widening',
                    },
                ];
            }
        }
        while (count($slots) < $targetPopulation) {
            $extraIndex = count($slots);
            $extraGroup = array_keys(self::POPULATION_GROUPS)[$extraIndex % count(self::POPULATION_GROUPS)];
            $slots[] = [
                ...match ($extraIndex % 3) {
                    0 => ['origin' => 'robust_crossover', 'target' => 'robustness'],
                    1 => ['origin' => 'architecture', 'target' => 'architecture'],
                    default => ['origin' => 'curiosity_probe', 'target' => 'unknown_state_curiosity'],
                },
                'research_group' => $extraGroup,
                'group_seat' => self::POPULATION_GROUP_SEATS + intdiv($extraIndex, count(self::POPULATION_GROUPS)) + 1,
                'group_axis' => self::POPULATION_GROUPS[$extraGroup]['axis'],
                'group_search_mode' => 'breadth_reserve',
                'group_search_role' => 'adaptive_reserve',
            ];
        }
        $slots = array_slice($slots, 0, $targetPopulation);

        // A failed sealed portfolio is an ensemble-level problem.  Its next
        // population must therefore contain several independent attempts for
        // each weak regime x volatility niche, instead of letting a global
        // slot index accidentally turn the rescue into a generic PF/stress
        // mutation.  These are still screening agents; no member can bypass
        // the unchanged full/forward/paper gates.
        $council = $this->portfolioCouncilCurriculum($lab);
        $latestGeneration = $lab->generations()->latest('generation')->first();
        $latestRecall = data_get($latestGeneration?->trigger_context, 'latest_generation_report.kpis.coverage_recall');
        $councilNiches = collect((array) data_get($council, 'niches', []));
        // A failed portfolio can have a newer individual forward replay with
        // a more informative transition bottleneck than the portfolio's
        // member ledger. Prefer that explicit recall owner when present; the
        // portfolio remains frozen and this only changes future research
        // routing.
        $recallNiche = $councilNiches
            ->first(fn (array $niche): bool => data_get($niche, 'recall_variant') === 'transition_wait_shortening')
            ?? $councilNiches->first(fn (array $niche): bool => in_array('FAILED_PASSPORT_OPPORTUNITY_RECALL', (array) data_get($niche, 'failed_gate_reasons', []), true));
        // A low recall KPI keeps the research lane alive even when the latest
        // cohort used a non-G98 contract (for example the volume shadow
        // cohort, whose passport intentionally does not require the G98
        // recall check). This only routes experiments; it never changes the
        // unchanged .20/.50 passport thresholds.
        $recallLaneActive = is_array($recallNiche)
            || (is_numeric($latestRecall) && (float) $latestRecall < .20);
        $councilPlan = [];
        foreach ((array) data_get($council, 'niches', []) as $nicheIndex => $niche) {
            // The third lane is deliberately a context-rescue experiment. It
            // uses regime/volatility-local entry topology, never a calendar
            // month label, so the council can repair recurring conditions
            // without learning "November" or "July" as a hidden feature.
            // Council lanes include an explicit transition/risk experiment.
            // The ordinary five-lane G98 population remains unchanged when no
            // council curriculum is active, preserving its fixed budget and
            // historical comparability.
            $isTransitionRiskRouter = (string) data_get($niche, 'role') === 'transition_risk_router';
            $councilTargets = $isTransitionRiskRouter
                ? ['transition_firewall', 'exit_topology']
                : ['monthly_survival', 'regime_coverage', 'volatility_session_stability', 'exit_topology', 'transition_firewall', 'portfolio_router'];
            // Recall is a first-class forward deficit. Reserve a seat inside
            // the bounded council itself when the declared regime evidence
            // carries that failure; relying on the two leftover generic slots
            // made the recall lane disappear whenever the council occupied
            // the fixed 20-agent budget.
            if (! $isTransitionRiskRouter && ($recallLaneActive
                || in_array('FAILED_PASSPORT_OPPORTUNITY_RECALL', (array) data_get($niche, 'failed_gate_reasons', []), true))) {
                $councilTargets[array_key_last($councilTargets)] = 'opportunity_recall';
            }
            foreach ($councilTargets as $laneIndex => $target) {
                $role = (string) data_get($niche, 'role', data_get($niche, 'regime', 'specialist').'_specialist');
                $councilPlan[] = [
                    'origin' => 'g98_council',
                    // The range adapter already lives inside hybrid. Keep
                    // its trend/breakout lanes frozen and mutate only the
                    // range gene; a differential child would replace the
                    // parent range flow and can manufacture zero activity.
                    'family' => $isTransitionRiskRouter || data_get($niche, 'regime') === 'range'
                        ? 'hybrid' : 'differential_router',
                    'target' => $target,
                    'niche' => [
                        'protocol' => 'portfolio_council_v1',
                        'role' => $role,
                        'specialist_role' => $role,
                        'regime' => (string) data_get($niche, 'regime', 'trend_down'),
                        'volatility' => (string) data_get($niche, 'volatility', 'normal_volatility'),
                        'direction' => filled(data_get($niche, 'direction')) ? strtoupper((string) data_get($niche, 'direction')) : null,
                        'objective' => $target,
                        'mutation_target' => in_array($target, ['volatility_session_stability', 'exit_topology'], true) ? 'stress_cost' : $target,
                        'source_performance_id' => $target === 'opportunity_recall'
                            // A nullable `recall_source_performance_id` must
                            // not mask the ordinary council source. Without
                            // the null-coalescing fallback, a recall child
                            // could lose the evidence parent while retaining
                            // its mutation label.
                            ? (data_get($niche, 'recall_source_performance_id')
                                ?? data_get($niche, 'source_performance_id'))
                            : data_get($niche, 'source_performance_id'),
                        'research_reason' => data_get($niche, 'reason'),
                        'opposite_profit_factor' => data_get($niche, 'opposite_profit_factor'),
                        'state_cluster' => data_get($niche, 'state_cluster'),
                        'month_labels_are_diagnostic_only' => true,
                        'recall_variant' => $target === 'opportunity_recall'
                            ? (data_get($niche, 'recall_variant')
                                ?? $this->recallVariantForNiche($niche, (int) $nicheIndex))
                            : null,
                        'non_target_parent_freeze' => true,
                        'promotion_rule' => 'combined_portfolio_only',
                    ],
                ];
            }
        }
        // Council lanes get first claim on the configured budget; standard
        // lanes fill the remainder. No hidden fixed population ceiling is
        // applied here.
        $councilPlan = $this->fillNormalCouncilCore($councilPlan, $lab, $targetPopulation);
        $councilPlan = array_slice($councilPlan, 0, count($slots));
        $standardSlots = array_slice($slots, count($councilPlan));
        // Reserve one independent research seat when the current knowledge
        // projection reports unresolved/unknown states. This is a curiosity
        // lane, not a hidden promotion lane and not a calendar-month repair.
        $curiosityLaneActive = AgentKnowledgeCard::query()
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->where('unknown_state_action', 'WAIT')->limit(50)->exists();
        if ($curiosityLaneActive && $standardSlots !== []) {
            $standardSlots[0] = ['origin' => 'curiosity_probe', 'target' => 'unknown_state_curiosity'];
        }
        // Opportunity recall is a forward passport failure, not merely a
        // reporting metric.  Reserve one of the remaining fixed-budget
        // slots for a bounded recall experiment when the latest council
        // evidence says recall is the deficit.  The six regime-owner lanes
        // remain intact; the separate slot keeps router ownership and recall
        // repair from being conflated.
        $standardPlan = collect($standardSlots)->map(function (array $slot, int $localIndex) use ($families, $explorationOnly, $nonExploratoryFamilies, $councilPlan, $lab, $matrixFrontier, $recallLaneActive, $recallNiche): array {
            $index = $localIndex + count($councilPlan);
            $familyEvidence = $families[$index % count($families)];
            $origin = $slot['origin'];
            if (in_array($familyEvidence['family'], $explorationOnly, true) && $nonExploratoryFamilies !== []) {
                $familyEvidence = $nonExploratoryFamilies[$index % count($nonExploratoryFamilies)];
            }
            $targets = array_values(array_filter((array) ($familyEvidence['screening_targets'] ?? [$familyEvidence['target'] ?? 'profit_factor'])));
            $targets = $targets ?: [$familyEvidence['target'] ?? 'profit_factor'];
            $experimentTarget = $slot['target'] ?? match ($origin) {
                // The gate-targeted slot must test the family's primary
                // deficit. Previously the global slot index selected the
                // third/fourth reason for a family (for example PF instead of
                // XAU hybrid's calendar failure), so the new curriculum was
                // recorded but never actually executed. Secondary deficits
                // belong to causal/architecture lanes below.
                'gate_targeted' => ! empty($familyEvidence['portfolio_curriculum'])
                    ? $targets[$localIndex % count($targets)]
                    : $targets[0],
                // Causal lanes test a second deficit in the same family,
                // producing a useful counterfactual instead of four copies of
                // the primary mutation.
                'causal_isolation' => $targets[1] ?? $targets[0],
                default => match ($origin) {
                    'risk_exit' => 'risk_exit', 'architecture' => 'architecture',
                    'robust_crossover' => 'robustness', default => 'regime_coverage',
                },
            };
            if ($recallLaneActive && $experimentTarget === 'portfolio_router' && $localIndex === 0) {
                $experimentTarget = 'opportunity_recall';
            }
            $matrixNiche = $matrixFrontier === [] ? null : $matrixFrontier[$index % count($matrixFrontier)];
            $niche = $matrixNiche;
            if ($experimentTarget === 'unknown_state_curiosity') {
                $niche = [
                    'protocol' => 'unknown_state_curiosity_lane_v1',
                    'role' => 'curiosity_router_specialist',
                    'specialist_role' => 'transition_risk_router',
                    'regime' => 'unknown', 'volatility' => 'unknown', 'direction' => null,
                    'objective' => 'unknown_state_curiosity', 'curiosity_lane' => true,
                    'research_reason' => 'unresolved_state_requires_safe_abstention_probe',
                    'promotion_rule' => 'research_only_no_promotion_evidence',
                ];
            }
            if ($experimentTarget === 'opportunity_recall') {
                $recallSourceNiche = is_array($recallNiche) ? $recallNiche : ($matrixNiche ?? []);
                $niche = [
                    'protocol' => 'g98_opportunity_recall_lane_v1',
                    'role' => 'opportunity_recall_specialist',
                    'regime' => data_get($recallSourceNiche, 'regime', data_get($matrixNiche, 'regime', 'trend_down')),
                    'volatility' => data_get($recallSourceNiche, 'volatility', data_get($matrixNiche, 'volatility', 'normal_volatility')),
                    'direction' => data_get($recallSourceNiche, 'direction', data_get($matrixNiche, 'direction')),
                    'objective' => 'opportunity_recall',
                    'source_performance_id' => data_get($recallSourceNiche, 'recall_source_performance_id')
                        ?? data_get($recallSourceNiche, 'source_performance_id'),
                    'research_reason' => 'forward_recall_below_twenty_percent',
                    'state_cluster' => data_get($recallSourceNiche, 'state_cluster'),
                    'month_labels_are_diagnostic_only' => true,
                    'recall_variant' => data_get($recallSourceNiche, 'recall_variant')
                        ?? $this->recallVariantForNiche($recallSourceNiche, $index),
                    'non_target_parent_freeze' => true,
                    'promotion_rule' => 'unchanged_forward_recall_and_abstention_gates',
                ];
            }

            return [
                'origin' => $origin,
                // The router owns its own layer. It never mutates a signal
                // specialist under a misleading portfolio-router label.
                'family' => in_array($experimentTarget, ['portfolio_router', 'opportunity_recall'], true) && in_array('differential_router', $lab->strategy_families, true)
                    ? 'differential_router' : $familyEvidence['family'],
                'target' => $experimentTarget,
                // The matrix is causal attribution. Month stays attached as
                // recurrence evidence, while regime/volatility/session/side
                // identifies the only envelope the lane may try to repair.
                'niche' => $niche,
            ];
        })->all();

        $plan = [...$councilPlan, ...$standardPlan];
        if (! $roleComplete) {
            return $plan;
        }

        // A role-complete generation reserves one auditable research seat for
        // each council role before the ordinary deficit-weighted plan. These
        // are still screening children; the full selector and unchanged
        // passport gates decide whether a role receives evidence.
        $mandatory = $this->mandatoryCouncilRolePlan($lab);
        $remaining = array_slice($plan, 0, max(0, $targetPopulation - count($mandatory)));

        return [...$mandatory, ...$remaining];
    }

    /**
     * A targeted handoff is a four-seat diagnostic cohort. Each seat owns one
     * observed failure dimension, so PF, stress cost, temporal stability and
     * regime coverage can be credited independently. This is search routing
     * only: every child still enters the normal screen/full/forward gates.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fiveByFourTargetedFailurePlan(AiLaboratory $lab, array $profile): array
    {
        $familyFallback = in_array('hybrid', $lab->strategy_families, true)
            ? 'hybrid'
            : ($lab->strategy_families[0] ?? 'hybrid');
        $plan = [];
        $slot = 0;
        $selectedRegimeGenes = [];

        foreach (self::TARGETED_RESCUE_GROUP_PLAN as $group => $definition) {
            foreach ((array) data_get($definition, 'targets', []) as $seat => $target) {
                $slot++;
                $family = match ($group) {
                    'volatility_session_stability' => collect((array) data_get($definition, 'family_preference', []))
                        ->first(fn (string $candidate): bool => in_array($candidate, $lab->strategy_families, true)) ?: $familyFallback,
                    'monthly_survival' => collect((array) data_get($definition, 'family_preference', []))
                        ->first(fn (string $candidate): bool => in_array($candidate, $lab->strategy_families, true)) ?: $familyFallback,
                    'regime_coverage' => collect((array) data_get($definition, 'family_preference', []))
                        ->first(fn (string $candidate): bool => in_array($candidate, $lab->strategy_families, true)) ?: $familyFallback,
                    'portfolio_router' => collect((array) data_get($definition, 'family_preference', []))
                        ->first(fn (string $candidate): bool => in_array($candidate, $lab->strategy_families, true)) ?: $familyFallback,
                    default => $familyFallback,
                };
                $objective = (string) data_get($definition, 'rescue_objective', $group);
                $role = (string) data_get($definition, 'specialist_role', 'targeted_failure_specialist');
                $declaredGene = data_get($definition, 'gene_plan.'.$seat);
                $architecturePlan = (array) data_get($definition, 'architecture_plan.'.$seat, []);
                // The v4 state-timing cohort is a fresh hypothesis set. Its
                // failure-cell audit is diagnostic context only; reusing an
                // older failed vector here can import a blocked direction or
                // a type-only zero diff into a new seat. Anchors remain
                // immutable research records, never genetic parents.
                $anchor = (string) data_get($profile, 'rescue_curriculum') === self::TARGETED_RESCUE_CURRICULUM
                    ? null
                    : $this->profileRepairAnchor($profile, (string) $target, $family, $lab);
                if ($anchor !== null && in_array((string) data_get($anchor, 'strategy_family'), $lab->strategy_families, true)) {
                    // A repair anchor owns the failed model's executable
                    // vector. Keep the specialist in that exact family; an
                    // anchor is diagnostic baseline material, never a parent.
                    $family = (string) data_get($anchor, 'strategy_family', $family);
                }
                $requestedGene = is_string($declaredGene) && $declaredGene !== '' ? $declaredGene : null;
                $geneSelection = [
                    'protocol' => 'controlled_rescue_gene_selection_v1',
                    'requested_gene' => $requestedGene,
                    'selected_gene' => $requestedGene,
                    'status' => 'requested_gene',
                    'reason' => null,
                    'promotion_evidence' => false,
                ];
                if ($group === 'regime_coverage' && $family === 'differential_router') {
                    [$declaredGene, $geneSelection] = $this->controlledRegimeGeneSelection(
                        $lab,
                        (string) data_get($definition, 'regime_plan.'.$seat, ''),
                        $requestedGene,
                        $selectedRegimeGenes,
                    );
                    if (is_string($declaredGene) && $declaredGene !== '') {
                        $selectedRegimeGenes[] = $declaredGene;
                    }
                }
                if ($group !== 'portfolio_router'
                    && data_get($geneSelection, 'status') !== 'no_legal_same_regime_gene'
                    && (! is_string($declaredGene) || $declaredGene === ''
                        || ! array_key_exists($declaredGene, $this->schemas->schema($family)))) {
                    $declaredGene = $this->repairGeneForTarget((string) $target, $family, $seat, $anchor);
                    $geneSelection['selected_gene'] = $declaredGene;
                    $geneSelection['status'] = 'generic_repair_gene';
                    $geneSelection['reason'] = 'requested_gene_missing_from_family_schema';
                }
                $plan[] = [
                    'origin' => 'targeted_failure_profile',
                    'family' => $family,
                    'target' => (string) $target,
                    'research_group' => $group,
                    'group_seat' => $seat + 1,
                    'group_axis' => data_get(self::POPULATION_GROUPS, $group.'.axis', 'diagnostic_reserve'),
                    'group_search_mode' => $seat < 2 ? 'depth' : 'breadth',
                    'group_search_role' => match ($seat) {
                        0 => 'checkpoint_continuation',
                        1 => 'deepening_mutation',
                        2 => 'architecture_widening',
                        default => 'curiosity_widening',
                    },
                    'niche' => [
                        'protocol' => self::TARGETED_RESCUE_PROFILE_PROTOCOL,
                        'rescue_protocol' => LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL,
                        'rescue_lane' => $objective,
                        'specialist_role' => $role,
                        'failure_target' => (string) $target,
                        'mutation_target' => (string) $target,
                        'target_sequence' => $slot,
                        'group_key' => $group,
                        'group_seat' => $seat + 1,
                        // The compiler must preserve this declaration all the
                        // way into parameter_diff; historical priors may not
                        // replace the seat's causal gene.
                        'declared_gene' => is_string($declaredGene) && $declaredGene !== '' ? $declaredGene : null,
                        'declared_gene_requested' => $requestedGene,
                        'declared_gene_selection' => $geneSelection,
                        'repair_anchor_id' => data_get($anchor, 'id'),
                        'repair_anchor_protocol' => $anchor !== null
                            ? FailureRepairAnchorService::PROTOCOL : null,
                        'repair_anchor_parameter_fingerprint' => data_get($anchor, 'parameter_fingerprint'),
                        'repair_anchor_parameter_diff_keys' => (array) data_get($anchor, 'parameter_diff_keys', []),
                        'regime' => data_get($definition, 'regime_plan.'.$seat),
                        'architecture_experiment' => $group === 'portfolio_router',
                        'architecture_variant' => data_get($architecturePlan, 'variant'),
                        'architecture_control_only' => (bool) data_get($architecturePlan, 'control_only', false),
                        'source_generation_id' => data_get($profile, 'source_generation_id'),
                        'source_generation' => data_get($profile, 'source_generation'),
                        'profile_hash' => data_get($profile, 'profile_hash'),
                        'rescue_curriculum' => self::TARGETED_RESCUE_CURRICULUM,
                        'temporal_edge_audit' => (array) data_get($profile, 'temporal_edge_audit', []),
                        'observed_failure_counts' => (array) data_get($profile, 'target_counts', []),
                        'calendar_diagnostic_only' => $objective === 'temporal_calendar_stability',
                        'non_target_parent_freeze' => true,
                        'protected_semantic_cell' => true,
                        'research_reason' => 'Temporary five-by-four targeted rescue; one declared failure objective per seat and unchanged promotion gates.',
                        'promotion_rule' => 'unchanged_screen_full_forward_paper_gates',
                    ],
                ];
            }
        }

        return $plan;
    }

    /**
     * Compile a bounded four-sibling cohort for every immutable failure
     * anchor. This plan takes precedence over the generic five-by-four rescue
     * only when anchors are present; profiles without anchors retain the old
     * deterministic curriculum and its historical test contract.
     *
     * @return array<int, array<string, mixed>>
     */
    private function anchorSiblingPlan(AiLaboratory $lab, array $profile, int $populationLimit = 20): array
    {
        $anchors = collect((array) data_get($profile, 'repair_anchors', []))
            ->filter(fn (mixed $anchor): bool => is_array($anchor)
                && filled(data_get($anchor, 'id'))
                && strtoupper((string) data_get($anchor, 'symbol')) === strtoupper($lab->symbol)
                && strtoupper((string) data_get($anchor, 'timeframe')) === strtoupper($lab->timeframe)
                && in_array((string) data_get($anchor, 'strategy_family'), $lab->strategy_families, true))
            ->unique('id')
            ->values();
        if ($anchors->isEmpty()) {
            return [];
        }

        $fiveSeat = (string) data_get($profile, 'cohort_mode') === 'four_siblings_plus_control_v1';
        $cohortSize = $fiveSeat ? 5 : 4;
        $maxAnchors = max(1, intdiv(max($cohortSize, $populationLimit), $cohortSize));
        $anchors = $anchors->take($maxAnchors)->values();
        $plan = [];
        foreach ($anchors as $anchor) {
            $family = (string) data_get($anchor, 'strategy_family');
            $target = (string) data_get($anchor, 'failure_target', 'profit_factor');
            $failureLane = $this->anchorFailureLane($anchor, $target);
            $policy = (array) data_get($anchor, 'policy', []);
            $escape = (string) data_get($policy, 'action', '') === 'escape_to_architecture';
            // Temporal scalar repairs have already produced observable gate
            // movement in the lighthouse, but not a pass. Spend one bounded
            // seat on the executable state-machine hypothesis now instead of
            // cycling another cooldown threshold. New anchors do not enter
            // this lane until at least one bounded attempt is recorded; the
            // explicit three-clean-failures policy still forces it later.
            $temporalArchitectureEscape = $fiveSeat
                && in_array($target, ['temporal_stability', 'monthly_survival'], true)
                && (
                    $escape
                    || ((int) data_get($policy, 'parameter_attempts', 0) >= 1
                        && (int) data_get($policy, 'target_improvements', 0) >= 1)
                );
            $architectureEscapeRecommended = $escape || $temporalArchitectureEscape;
            $includeRobustnessSeat = $fiveSeat
                && in_array($target, ['temporal_stability', 'monthly_survival'], true)
                && ((int) data_get($profile, 'target_counts.train_forward_robustness', 0) > 0
                    || in_array('train_forward_robustness', (array) data_get($profile, 'failure_specific_lanes_observed', []), true));
            $cohortGenes = $fiveSeat
                ? $this->anchorCohortGenePlan($target, $family, $anchor, $includeRobustnessSeat)
                : [];
            $primaryGene = $fiveSeat
                ? data_get($cohortGenes, '0.gene')
                : $this->repairGeneForTarget($target, $family, 0, $anchor);
            $alternativeGene = $fiveSeat
                ? data_get($cohortGenes, '2.gene')
                : $this->repairAlternativeGeneForTarget($target, $family, $primaryGene, $anchor);
            $secondaryAlternativeGene = $fiveSeat
                ? data_get($cohortGenes, '3.gene')
                : $this->repairSecondAlternativeGeneForTarget($target, $family, $primaryGene, $alternativeGene, $anchor);
            $reverseGene = $fiveSeat
                ? data_get($cohortGenes, '1.gene')
                : $primaryGene;
            $primaryDirection = $fiveSeat
                ? (string) data_get($cohortGenes, '0.direction', 'increase')
                : $this->anchorDirectionForGene($anchor, $primaryGene, 'increase');
            $reverseDirection = $fiveSeat
                ? (string) data_get($cohortGenes, '1.direction', 'decrease')
                : $this->anchorDirectionForGene($anchor, $primaryGene, 'decrease');
            $cohortId = hash('sha256', json_encode([
                self::ANCHOR_SIBLING_PROTOCOL,
                (int) data_get($anchor, 'id'),
                data_get($anchor, 'parameter_fingerprint'),
                data_get($profile, 'source_generation_id'),
            ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            $group = $this->anchorResearchGroup($failureLane === 'train_forward_robustness' ? 'temporal_stability' : $target);
            // Anchor mentors must land in the same canonical council role as
            // the later specialist probes. A temporary repair_* role would
            // make a confirmed skill invisible to its compatible frontier.
            $role = $this->anchorSpecialistRole($failureLane);
            $siblings = [
                ['kind' => 'primary_direction', 'gene' => $primaryGene, 'direction' => $primaryDirection],
                ['kind' => 'reverse_direction', 'gene' => $reverseGene, 'direction' => $reverseDirection],
                ...($fiveSeat
                    ? [
                        ['kind' => 'alternative_gene', 'gene' => $alternativeGene, 'direction' => (string) data_get($cohortGenes, '2.direction', 'increase')],
                        $architectureEscapeRecommended
                            ? [
                                'kind' => 'architecture_escape',
                                'gene' => $temporalArchitectureEscape ? 'state_machine_variant' : null,
                                'direction' => 'architecture',
                                'architecture_experiment' => true,
                                'state_machine_variant' => $temporalArchitectureEscape ? 'neutral_transition_cooldown_reentry_v1' : null,
                            ]
                            : ['kind' => 'secondary_alternative_gene', 'gene' => $secondaryAlternativeGene, 'direction' => (string) data_get($cohortGenes, '3.direction', 'decrease')],
                    ]
                    : [
                        $architectureEscapeRecommended
                            ? [
                                'kind' => 'architecture_escape',
                                'gene' => $temporalArchitectureEscape ? 'state_machine_variant' : null,
                                'direction' => 'architecture',
                                'architecture_experiment' => true,
                                'state_machine_variant' => $temporalArchitectureEscape ? 'neutral_transition_cooldown_reentry_v1' : null,
                            ]
                            : ['kind' => 'alternative_gene', 'gene' => $alternativeGene, 'direction' => 'increase'],
                    ]),
                ['kind' => 'frozen_control', 'gene' => null, 'direction' => 'unchanged', 'control_only' => true],
            ];
            foreach ($siblings as $index => $sibling) {
                $robustnessSeat = $includeRobustnessSeat
                    && (string) data_get($sibling, 'gene', '') === 'confidence_calibration_min_samples';
                $seatFailureLane = $robustnessSeat ? 'train_forward_robustness' : $failureLane;
                $plan[] = [
                    'origin' => 'targeted_failure_profile',
                    'family' => $family,
                    'target' => $target,
                    'research_group' => $group,
                    'group_seat' => $index + 1,
                    'niche' => [
                        'protocol' => self::ANCHOR_SIBLING_PROTOCOL,
                        'rescue_protocol' => LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL,
                        'specialist_role' => $robustnessSeat ? 'robustness_split_specialist' : $role,
                        'failure_target' => $target,
                        'mutation_target' => $target,
                        'failure_specific_lane' => $seatFailureLane,
                        'temporal_mutation_hypothesis' => $robustnessSeat ? null : $this->temporalMutationHypothesis(
                            $target,
                            $failureLane,
                            (array) data_get($profile, 'temporal_edge_audit', []),
                        ),
                        'robustness_split_contract' => $seatFailureLane === 'train_forward_robustness'
                            ? 'immutable_same_train_forward_split'
                            : null,
                        'declared_gene' => $sibling['gene'],
                        'repair_anchor_id' => (int) data_get($anchor, 'id'),
                        'repair_anchor_protocol' => FailureRepairAnchorService::PROTOCOL,
                        'repair_anchor_parameter_fingerprint' => data_get($anchor, 'parameter_fingerprint'),
                        'repair_anchor_parameter_diff_keys' => (array) data_get($anchor, 'parameter_diff_keys', []),
                        'repair_anchor_sibling_cohort_id' => $cohortId,
                        'repair_anchor_sibling_index' => $index + 1,
                        'repair_anchor_sibling_kind' => $sibling['kind'],
                        'sibling_kind' => $sibling['kind'],
                        'cohort_contract' => $fiveSeat ? 'four_siblings_plus_control_v1' : 'legacy_three_siblings_plus_control_v1',
                        'repair_direction' => $sibling['direction'],
                        'control_only' => (bool) data_get($sibling, 'control_only', false),
                        'architecture_experiment' => (bool) data_get($sibling, 'architecture_experiment', false),
                        'architecture_variant' => data_get(
                            $temporalArchitectureEscape
                                ? [(string) data_get($anchor, 'architecture', '')]
                                : collect(self::ARCHITECTURES[$family] ?? [])->reject(fn (string $variant): bool => $variant === (string) data_get($anchor, 'architecture', ''))->values()->all(),
                            '0',
                        ),
                        'state_machine_variant' => data_get($sibling, 'state_machine_variant'),
                        'shadow_only' => data_get($sibling, 'state_machine_variant') !== null,
                        'architecture_escape' => (bool) data_get($sibling, 'architecture_experiment', false),
                        'escape_action' => $architectureEscapeRecommended ? 'architecture_or_specialist_hypothesis' : 'bounded_gene_repair',
                        'escape_recommended' => $architectureEscapeRecommended,
                        'anchor_policy' => $policy,
                        'temporal_edge_audit' => (array) data_get($profile, 'temporal_edge_audit', []),
                        'source_generation_id' => data_get($profile, 'source_generation_id'),
                        'source_generation' => data_get($profile, 'source_generation'),
                        'profile_hash' => data_get($profile, 'profile_hash'),
                        'non_target_parent_freeze' => true,
                        'protected_semantic_cell' => true,
                        'research_reason' => $architectureEscapeRecommended
                            ? ($temporalArchitectureEscape
                                ? 'Temporal scalar repairs moved the gate but did not pass; this shadow-only sibling tests the finite transition/cooldown/re-entry state machine against the same frozen control.'
                                : 'Three clean anchor attempts did not improve the target; this cohort is an architecture/specialist escape experiment.')
                            : ($robustnessSeat
                                ? 'Mixed failure cohort: train/forward robustness is tested as a bounded calibration gene while the split remains immutable.'
                            : ($fiveSeat
                                ? 'Immutable failure anchor with four bounded one-gene siblings and one freshly replayed frozen control.'
                                : 'Immutable failure anchor with bounded primary, reverse, alternative-gene and frozen-control siblings.')),
                        'promotion_rule' => 'unchanged_screen_full_forward_paper_gates',
                    ],
                ];
            }
        }

        // Legacy rescue profiles retain their 20-seat contract. The new
        // gate-margin profile is a complete five-seat cohort and must never
        // be padded with unrelated experiments.
        $required = $fiveSeat ? 5 : max(4, $populationLimit);
        if ($fiveSeat) {
            return array_slice($plan, 0, $required);
        }
        if (count($plan) < $required) {
            $fallbackProfile = [...$profile, 'repair_anchors' => []];
            foreach ($this->fiveByFourTargetedFailurePlan($lab, $fallbackProfile) as $fallback) {
                if (count($plan) >= $required) {
                    break;
                }
                $plan[] = $fallback;
            }
        }

        return array_slice($plan, 0, $required);
    }

    /**
     * Select four distinct, actually changeable genes for a bounded repair
     * cohort. Boolean genes and boundary values are valid hypotheses, but
     * using one boolean for both "primary" and "reverse" seats often makes
     * one arm a zero-diff clone. Distinct schema-bounded genes keep every
     * sibling interpretable while the frozen control remains unchanged.
     *
     * @return array<int, array{gene:string,direction:string}>
     */
    private function anchorCohortGenePlan(string $target, string $family, array $anchor, bool $includeRobustnessSeat = false): array
    {
        $schema = $this->schemas->schema($family);
        $defaults = $this->schemas->defaults($family);
        $snapshot = (array) data_get($anchor, 'parameter_snapshot', []);
        $failureLane = $this->anchorFailureLane($anchor, $target);
        $temporalStatePersistence = $failureLane === 'temporal_stability'
            && in_array($target, ['temporal_stability', 'monthly_survival'], true);
        $preferred = $failureLane === 'train_forward_robustness'
            ? [
                // These are execution/calibration genes. The train/forward
                // split itself is an evaluation invariant and is never
                // mutated by a rescue cohort.
                'confidence_calibration_min_samples', 'weak_regime_min_samples',
                'meta_label_min_history', 'cooldown_shadow_min_samples',
                'confidence_calibration_enabled', 'meta_label_enabled',
                'weak_regime_wait_candles', 'loss_streak_wait_candles',
            ]
            : ($temporalStatePersistence
                ? ($includeRobustnessSeat
                    ? [
                        // A mixed temporal cohort has four deliberately
                        // different learning questions: executable
                        // transition state, loss-streak state, active
                        // cooldown and train/forward robustness. The split
                        // remains immutable; only the declared execution
                        // gene moves. Inactive session/all-day and capped
                        // cooldown mutations are excluded here.
                        'transition_firewall_enabled', 'max_loss_streak_before_wait',
                        'loss_cooldown_candles', 'confidence_calibration_min_samples',
                    ]
                    : [
                        // A losing baseline may not manufacture apparent PF
                        // by tuning post-loss cooldowns. Probe four executable
                        // decision architectures instead; each is a single
                        // frozen gene and remains paired with the same control.
                        'entry_topology_variant', 'state_machine_variant',
                        'regime_classifier_variant', 'architecture_interaction_variant',
                    ])
                : match ($target) {
                    'profit_factor' => [
                        'minimum_signal_confidence', 'minimum_confidence', 'trend_ema_period', 'trend_up_strength_min',
                        'trend_down_strength_min', 'lookback', 'atr_target_multiplier', 'atr_stop_multiplier',
                    ],
                    'stress_cost' => [
                        'atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles',
                        'max_spread_atr_ratio', 'partial_target_atr_multiplier', 'partial_take_profit_fraction',
                    ],
                    'drawdown_risk' => [
                        'time_stop_candles', 'max_loss_streak_before_wait', 'loss_cooldown_candles',
                        'atr_stop_multiplier', 'high_volatility_risk_multiplier', 'partial_target_atr_multiplier',
                    ],
                    'regime_coverage' => [
                        'trend_up_strength_min', 'trend_down_strength_min', 'trend_up_roc_period', 'trend_down_roc_period',
                        'trend_up_ema_period', 'trend_down_ema_period', 'range_deviation', 'range_adx_max',
                    ],
                    'temporal_stability', 'monthly_survival' => [
                        'transition_wait_candles', 'trend_roc_period', 'trend_ema_period', 'breakout_lookback',
                        'range_lookback', 'time_stop_candles', 'session_start', 'session_end',
                        'transition_firewall_enabled', 'session_filter_enabled',
                    ],
                    default => array_keys($schema),
                });
        $keys = array_values(array_unique([...$preferred, ...array_keys($schema)]));
        $plan = [];
        foreach ($keys as $key) {
            if (! is_string($key) || ! isset($schema[$key]) || in_array($key, array_column($plan, 'gene'), true)) {
                continue;
            }
            [$type, $min, $max] = array_pad($schema[$key], 3, null);
            $current = array_key_exists($key, $snapshot) ? $snapshot[$key] : ($defaults[$key] ?? null);
            $directionPreference = $temporalStatePersistence
                ? match ($key) {
                    // With dynamic cooldown enabled, the trend/normal path
                    // is capped at two candles. Increasing 4 -> 6 is
                    // therefore non-observable; shorten toward the active
                    // side instead.
                    'loss_cooldown_candles' => 'decrease',
                    'loss_streak_wait_candles', 'weak_regime_wait_candles' => 'increase',
                    'max_loss_streak_before_wait' => 'decrease',
                    default => 'increase',
                }
            : (count($plan) % 2 === 0 ? 'increase' : 'decrease');
            $direction = $this->anchorChangeDirection($type, $min, $max, $current, $directionPreference);
            if ($direction === null) {
                continue;
            }
            $plan[] = ['gene' => $key, 'direction' => $direction];
            if (count($plan) >= 4) {
                break;
            }
        }

        return $plan;
    }

    private function anchorChangeDirection(mixed $type, mixed $min, mixed $max, mixed $current, string $preferred): ?string
    {
        if ($type === 'boolean') {
            // A boolean has one legal changed value. The old alternating
            // preference could return "enable" for an already-enabled gene,
            // producing a zero-diff reverse sibling. Always return the actual
            // changed direction; numeric genes carry direction diversity.
            return (bool) $current ? 'disable' : 'enable';
        }
        if ($type === 'string') {
            return 'increase';
        }
        if (! is_numeric($min) || ! is_numeric($max) || ! is_numeric($current)) {
            return null;
        }

        $current = (float) $current;
        $epsilon = 0.0000001;
        $canIncrease = $current < ((float) $max - $epsilon);
        $canDecrease = $current > ((float) $min + $epsilon);
        if ($preferred === 'increase' && $canIncrease) {
            return 'increase';
        }
        if ($preferred === 'decrease' && $canDecrease) {
            return 'decrease';
        }
        if ($canIncrease) {
            return 'increase';
        }
        if ($canDecrease) {
            return 'decrease';
        }

        return null;
    }

    private function anchorResearchGroup(string $target): string
    {
        return match ($target) {
            'temporal_stability', 'monthly_survival' => 'monthly_survival',
            'regime_coverage' => 'regime_coverage',
            'stress_cost', 'drawdown_risk', 'profit_factor', 'trade_frequency' => 'volatility_session_stability',
            'architecture' => 'portfolio_router',
            default => 'exit_topology',
        };
    }

    private function anchorFailureLane(array $anchor, string $target): string
    {
        $reason = strtoupper((string) data_get($anchor, 'failure_reason', ''));

        return $reason === 'FAILED_TRAIN_FORWARD_GAP'
            ? 'train_forward_robustness'
            : $target;
    }

    private function anchorSpecialistRole(string $target): string
    {
        return match ($target) {
            'profit_factor' => 'edge_quality_specialist',
            'stress_cost' => 'cost_stability_specialist',
            'train_forward_robustness' => 'robustness_split_specialist',
            'temporal_stability', 'monthly_survival' => 'temporal_stability_specialist',
            'regime_coverage' => 'regime_coverage_specialist',
            'drawdown_risk', 'trade_frequency' => 'non_target_regression_specialist',
            'architecture' => 'architecture_control_specialist',
            default => 'repair_'.preg_replace('/[^a-z0-9_]+/i', '_', $target).'_specialist',
        };
    }

    /**
     * The repeated weak cells are evidence coordinates, not executable
     * calendar selectors. This hypothesis tests whether the failure is
     * caused by state persistence around transitions/losses rather than by
     * another indicator-window choice. Each cohort sibling still changes
     * exactly one gene and the frozen control remains the immutable anchor.
     *
     * @return array<string, mixed>|null
     */
    private function temporalMutationHypothesis(
        string $target,
        string $failureLane,
        array $temporalEdgeAudit = [],
    ): ?array {
        if ($failureLane !== 'temporal_stability'
            || ! in_array($target, ['temporal_stability', 'monthly_survival'], true)) {
            return null;
        }

        return [
            'protocol' => self::TEMPORAL_STATE_PERSISTENCE_HYPOTHESIS,
            'claim' => 'Repeated chronological/session/side weakness may be a decision-architecture failure; test executable entry topology, transition state, closed regime classifier and their bounded interaction while keeping risk and indicator timing frozen.',
            'declared_genes' => [
                'entry_topology_variant',
                'state_machine_variant',
                'regime_classifier_variant',
                'architecture_interaction_variant',
            ],
            'direction_rule' => [
                'entry_topology_variant' => 'increase',
                'state_machine_variant' => 'increase',
                'regime_classifier_variant' => 'increase',
                'architecture_interaction_variant' => 'increase',
            ],
            'already_active_controls' => [
                'dynamic_cooldown_enabled' => true,
            ],
            'historically_exhausted_controls' => [
                // These were observed as parameter-only in the current
                // control topology, not as strategy failures. They must be
                // replaced by an executable gene before another cohort is
                // admitted.
                'transition_wait_candles',
                'session_filter_enabled',
            ],
            'observability_guard' => [
                'transition_wait_candles' => 'requires_transition_firewall_enabled',
                'session_filter_enabled' => 'requires_non_full_session_window',
                'loss_cooldown_candles_increase' => 'capped_in_trend_normal_dynamic_cooldown',
            ],
            'evidence' => [
                'temporal_edge_audit' => $temporalEdgeAudit,
                'month_session_direction_labels_are_diagnostic_only' => true,
            ],
            'non_target_parent_freeze' => true,
            'diagnostic_only' => true,
            'promotion_evidence' => false,
        ];
    }

    private function repairAlternativeGeneForTarget(string $target, string $family, ?string $primary, ?array $anchor = null): ?string
    {
        $schemaKeys = array_keys($this->schemas->schema($family));
        $preferred = [];
        foreach ([$this->repairGeneForTarget($target, $family, 0, $anchor), $this->repairGeneForTarget($target, $family, 1, $anchor), $this->repairGeneForTarget($target, $family, 2, $anchor)] as $key) {
            if (is_string($key) && $key !== '' && $key !== $primary && in_array($key, $schemaKeys, true)) {
                $preferred[] = $key;
            }
        }
        if ($preferred !== []) {
            return $preferred[0];
        }

        return collect($schemaKeys)->first(fn (string $key): bool => $key !== $primary);
    }

    private function repairSecondAlternativeGeneForTarget(
        string $target,
        string $family,
        ?string $primary,
        ?string $alternative,
        ?array $anchor = null,
    ): ?string {
        $schemaKeys = array_keys($this->schemas->schema($family));
        $blocked = array_values(array_filter([$primary, $alternative], static fn (mixed $key): bool => is_string($key) && $key !== ''));
        $preferred = [];
        foreach ([
            $this->repairGeneForTarget($target, $family, 2, $anchor),
            $this->repairGeneForTarget($target, $family, 3, $anchor),
            ...$schemaKeys,
        ] as $key) {
            if (is_string($key) && $key !== '' && ! in_array($key, $blocked, true) && in_array($key, $schemaKeys, true)) {
                $preferred[] = $key;
            }
        }

        return $preferred[0] ?? null;
    }

    private function anchorDirectionForGene(array $anchor, ?string $gene, string $fallback): string
    {
        $value = data_get($anchor, 'parameter_snapshot.'.(string) $gene);
        if (is_bool($value)) {
            return $fallback === 'increase'
                ? ($value ? 'disable' : 'enable')
                : ($value ? 'enable' : 'disable');
        }

        return $fallback;
    }

    /**
     * Select a legal active regime gene for a controlled differential seat.
     *
     * The sealed curriculum names the first causal gene, but a provisional
     * mutation-budget lesson may block that direction before construction.
     * In that case the seat must move to another gene in the same regime
     * owner, rather than becoming a zero-diff child or silently crossing into
     * a different semantic cell.
     *
     * @return array{0:?string,1:array<string,mixed>}
     */
    private function controlledRegimeGeneSelection(
        AiLaboratory $lab,
        string $regime,
        ?string $requestedGene,
        array $usedGenes,
    ): array {
        $schema = $this->schemas->schema('differential_router');
        $regime = in_array($regime, ['trend_up', 'trend_down', 'range'], true) ? $regime : 'trend_up';
        $prefix = $regime === 'range' ? 'range_' : $regime.'_';
        $ordered = match ($regime) {
            'trend_up' => [
                'trend_up_strength_min',
                'trend_up_roc_period',
                'trend_up_roc_threshold',
                'trend_up_ema_period',
                'trend_up_pullback_atr_fraction',
                'trend_up_risk_multiplier',
            ],
            'trend_down' => [
                'trend_down_strength_min',
                'trend_down_roc_period',
                'trend_down_roc_threshold',
                'trend_down_ema_period',
                'trend_down_pullback_atr_fraction',
                'trend_down_risk_multiplier',
            ],
            default => [
                'range_deviation',
                'range_adx_max',
                'range_lookback',
                'range_low_volatility_only',
                'range_reentry_required',
            ],
        };
        $candidates = array_values(array_unique(array_filter([
            $requestedGene,
            ...$ordered,
        ], static fn (mixed $key): bool => is_string($key) && $key !== '')));
        $blocked = $this->knowledge->blockedMutationKeys(
            $lab->symbol,
            $lab->timeframe,
            'differential_router',
            $regime,
        );
        $budget = app(AgentProfessionalExamService::class)->mutationBudget(
            $lab->symbol,
            $lab->timeframe,
            'differential_router',
        );
        $allowed = app(AgentProfessionalExamService::class)->allowedMutationKeys(
            array_keys($schema),
            $budget,
        );
        $allowed = array_values(array_diff($allowed, $blocked, $usedGenes));
        foreach ($candidates as $candidate) {
            if (! array_key_exists($candidate, $schema) || ! in_array($candidate, $allowed, true)) {
                continue;
            }

            return [$candidate, [
                'protocol' => 'controlled_rescue_gene_selection_v1',
                'requested_gene' => $requestedGene,
                'selected_gene' => $candidate,
                'status' => $candidate === $requestedGene ? 'requested_gene' : 'same_regime_fallback',
                'reason' => $candidate === $requestedGene ? null : 'requested_gene_blocked_by_mutation_budget_or_knowledge_firewall',
                'regime' => $regime,
                'allowed_candidate_count' => count($allowed),
                'promotion_evidence' => false,
            ]];
        }

        // Keep the failure explicit. The caller will skip the seat rather
        // than create a zero-diff or cross-regime experiment.
        return [null, [
            'protocol' => 'controlled_rescue_gene_selection_v1',
            'requested_gene' => $requestedGene,
            'selected_gene' => null,
            'status' => 'no_legal_same_regime_gene',
            'reason' => 'all_same_regime_genes_blocked_or_already_used',
            'regime' => $regime,
            'allowed_candidate_count' => count($allowed),
            'promotion_evidence' => false,
        ]];
    }

    private function targetedFailurePlan(AiLaboratory $lab, array $targets, int $targetPopulation, array $profile = []): array
    {
        $canonical = ['profit_factor', 'stress_cost', 'temporal_stability', 'regime_coverage'];
        $ordered = array_values(array_unique(array_filter(array_map(
            static fn (mixed $target): string => (string) $target,
            $targets,
        ), fn (string $target): bool => in_array($target, $canonical, true))));
        $ordered = array_values(array_unique([...$ordered, ...$canonical]));
        $ordered = array_slice($ordered, 0, max(4, $targetPopulation));
        while (count($ordered) < $targetPopulation) {
            $ordered[] = $canonical[count($ordered) % count($canonical)];
        }

        $family = in_array('differential_router', $lab->strategy_families, true)
            ? 'differential_router'
            : (in_array('hybrid', $lab->strategy_families, true) ? 'hybrid' : ($lab->strategy_families[0] ?? 'hybrid'));
        $groups = [
            'profit_factor' => 'volatility_session_stability',
            'stress_cost' => 'volatility_session_stability',
            'temporal_stability' => 'monthly_survival',
            'regime_coverage' => 'regime_coverage',
        ];
        $roles = [
            'profit_factor' => 'edge_quality_specialist',
            'stress_cost' => 'cost_stability_specialist',
            'temporal_stability' => 'temporal_stability_specialist',
            'regime_coverage' => 'regime_coverage_specialist',
        ];
        $targetCounts = (array) data_get($profile, 'target_counts', []);

        return collect($ordered)->take($targetPopulation)->values()->map(function (string $target, int $index) use ($family, $groups, $roles, $profile, $targetCounts, $lab): array {
            $anchor = $this->profileRepairAnchor($profile, $target, $family, $lab);
            $anchorFamily = (string) data_get($anchor, 'strategy_family', '');
            $childFamily = $anchor !== null && in_array($anchorFamily, $lab->strategy_families, true)
                ? $anchorFamily : $family;
            $declaredGene = $this->repairGeneForTarget($target, $childFamily, $index, $anchor);

            return [
                'origin' => 'targeted_failure_profile',
                'family' => $childFamily,
                'target' => $target,
                'research_group' => $groups[$target] ?? 'regime_coverage',
                'niche' => [
                    'protocol' => 'targeted_failure_profile_v1',
                    'specialist_role' => $roles[$target] ?? 'failure_profile_specialist',
                    'failure_target' => $target,
                    'mutation_target' => $target,
                    'declared_gene' => $declaredGene,
                    'repair_anchor_id' => data_get($anchor, 'id'),
                    'repair_anchor_protocol' => $anchor !== null
                        ? FailureRepairAnchorService::PROTOCOL : null,
                    'repair_anchor_parameter_fingerprint' => data_get($anchor, 'parameter_fingerprint'),
                    'repair_anchor_parameter_diff_keys' => (array) data_get($anchor, 'parameter_diff_keys', []),
                    'target_sequence' => $index + 1,
                    'target_count' => (int) ($targetCounts[$target] ?? 0),
                    'source_generation_id' => data_get($profile, 'source_generation_id'),
                    'source_generation' => data_get($profile, 'source_generation'),
                    'profile_hash' => data_get($profile, 'profile_hash'),
                    'research_reason' => 'Gen3 failure profile targeted one-gene repair; target remains diagnostic until every unchanged gate passes.',
                    'non_target_parent_freeze' => true,
                    'promotion_rule' => 'unchanged_screen_full_forward_paper_gates',
                ],
            ];
        })->all();
    }

    /**
     * Select a descriptor from the handoff without loading a failed model as
     * a parent. The actual immutable snapshot is resolved again in
     * createAgent() and is scope-checked there before use.
     */
    private function profileRepairAnchor(array $profile, string $target, string $family, AiLaboratory $lab): ?array
    {
        $anchors = collect((array) data_get($profile, 'repair_anchors', []))
            ->filter(fn (mixed $anchor): bool => is_array($anchor)
                && (string) data_get($anchor, 'failure_target') === $target
                && strtoupper((string) data_get($anchor, 'symbol')) === strtoupper($lab->symbol)
                && strtoupper((string) data_get($anchor, 'timeframe')) === strtoupper($lab->timeframe)
                && filled(data_get($anchor, 'id')))
            ->values();
        if ($anchors->isEmpty()) {
            return null;
        }

        return $anchors->first(fn (array $anchor): bool => (string) data_get($anchor, 'strategy_family') === $family)
            ?? $anchors->first(fn (array $anchor): bool => in_array((string) data_get($anchor, 'strategy_family'), $lab->strategy_families, true));
    }

    private function repairGeneForTarget(string $target, string $family, int $index, ?array $anchor = null): ?string
    {
        $schema = $this->schemas->schema($family);
        $preferred = match ($target) {
            'profit_factor' => [
                'minimum_signal_confidence', 'minimum_confidence', 'trend_strength_min',
                'trend_up_strength_min', 'trend_down_strength_min', 'trend_ema_period', 'lookback',
            ],
            'stress_cost', 'drawdown_risk' => [
                'atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier',
                'time_stop_candles', 'partial_take_profit_fraction', 'max_spread_atr_ratio',
            ],
            'train_forward_robustness' => [
                'confidence_calibration_min_samples', 'weak_regime_min_samples',
                'meta_label_min_history', 'cooldown_shadow_min_samples',
                'confidence_calibration_enabled', 'meta_label_enabled',
            ],
            'temporal_stability', 'monthly_survival' => [
                'session_filter_enabled', 'session_start', 'session_end', 'transition_firewall_enabled',
                'transition_wait_candles', 'time_stop_candles', 'trend_ema_period', 'lookback',
            ],
            'regime_coverage' => [
                'trend_up_strength_min', 'trend_down_strength_min', 'trend_up_roc_period',
                'trend_down_roc_period', 'differential_target_min_signal_confidence',
                'minimum_signal_confidence', 'range_deviation',
            ],
            default => [],
        };
        $available = array_values(array_intersect($preferred, array_keys($schema)));
        if ($available !== []) {
            return $available[$index % count($available)];
        }

        // A single failed gene is a useful repair candidate only when it is a
        // legal field for this target. Never blindly mutate an arbitrary
        // failed diff key and call it a causal repair.
        $failedKeys = array_values(array_intersect(
            (array) data_get($anchor, 'parameter_diff_keys', []),
            array_keys($schema),
        ));

        return $failedKeys[0] ?? array_key_first($schema);
    }
}
