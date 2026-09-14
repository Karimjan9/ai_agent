<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\Candle;
use App\Models\InstrumentValuePosterior;
use App\Models\LabAgent;
use App\Models\Symbol;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Allocates the ordinary twenty seats to causal experiment blocks, not fixed groups. */
class CooperativeContextualEvolutionCouncilService
{
    public const PROTOCOL = 'cooperative_contextual_evolution_council_v1';

    private const BLOCK_SEATS = [
        'repair_pair' => 2, 'novelty_pair' => 2, 'replication' => 2,
        'factorial' => 4, 'transfer' => 4, 'descendant' => 4,
        'coverage_guard' => 2, 'adversarial_guard' => 2,
    ];

    public function __construct(
        private MarketSessionCalendarService $calendar,
        private CooperativeModuleSpeciesService $species,
        private ResearchIdeaInboxService $ideas,
        private EvidenceSalvageConveyorService $salvage,
    ) {}

    /** @return array{plan:array<int,array<string,mixed>>,contract:array<string,mixed>} */
    public function allocate(array $plan, AiLaboratory $lab, array $governorSnapshot = []): array
    {
        $plan = array_values($plan);
        if (count($plan) !== 20) {
            return ['plan' => $plan, 'contract' => [
                'protocol' => self::PROTOCOL, 'status' => 'not_applicable_non_twenty_seat_population',
                'planned_population' => count($plan), 'dynamic' => false, 'promotion_evidence' => false,
            ]];
        }

        $evidence = $this->evidence($lab);
        $salvage = $this->salvage->planForLab($lab, 20);
        $steppingStone = $this->steppingStoneAvailable($lab);
        $phase = $steppingStone ? 'causal_compounding' : 'cold_start';
        $types = $steppingStone
            ? ['replication', 'factorial', 'transfer', 'descendant', 'repair_pair', 'novelty_pair', 'coverage_guard']
            : ['repair_pair', 'repair_pair', 'repair_pair', 'repair_pair', 'repair_pair', 'repair_pair',
                'novelty_pair', 'novelty_pair', 'novelty_pair', 'adversarial_guard'];
        $legacyLearning = app(MultiModalLearningPortfolioService::class)->planForLab(
            $lab,
            app(EvolutionaryAuthorityLadderService::class)->experimentBlocks($steppingStone),
            $this->learningEvidence($evidence),
        );
        $reference = $this->referenceTimestamp($lab);
        $generationCursor = ((int) ($lab->generations()->max('generation') ?? 0)) + 1;
        $readyIdeas = $this->ideas->ready($lab->symbol, $lab->timeframe);
        $ideaCursor = 0;
        $allocations = array_fill_keys($this->calendar->researchPhases(), 0);
        $allocated = [];
        $blockRows = [];
        $capsules = [];
        $priorityLedger = [];
        $familyTemplates = collect($plan)->groupBy(fn (array $slot): string => (string) data_get($slot, 'family', 'hybrid'));
        $families = $familyTemplates->keys()->values();
        $repairTargets = $this->orderedRepairTargets($governorSnapshot, 6);
        $repairCursor = 0;

        foreach ($types as $blockIndex => $blockType) {
            $seatCount = self::BLOCK_SEATS[$blockType];
            if ($blockType === 'repair_pair' && $repairTargets !== []) {
                $repairTarget = $repairTargets[$repairCursor++ % count($repairTargets)];
                $template = $this->templateForRepairTarget($plan, $repairTarget, $blockIndex, $governorSnapshot);
                $family = (string) data_get($template, 'family', 'hybrid');
            } else {
                $family = (string) $families[$blockIndex % max(1, $families->count())];
                $bucket = $familyTemplates->get($family, collect($plan))->values();
                $template = (array) $bucket[intdiv($blockIndex, max(1, $families->count())) % max(1, $bucket->count())];
            }
            $other = $this->differentGeneTemplate($plan, $template, $blockIndex + 1);
            $sourcePhase = $this->selectPhase($blockType, $blockIndex, $generationCursor, $evidence, $allocations);
            $causalSourceScope = (array) data_get($legacyLearning, 'source_references.causal_skill.context_scope', []);
            if ($steppingStone && in_array($blockType, ['replication', 'factorial', 'transfer', 'descendant'], true)) {
                $sourcePhase = $this->phaseForSourceScope($causalSourceScope, $sourcePhase);
            }
            $targetPhase = $blockType === 'transfer'
                ? $this->differentPhase($sourcePhase, $evidence, $allocations, $blockIndex + $generationCursor)
                : $sourcePhase;
            $sourceCell = $this->cell($template, $sourcePhase, $reference);
            $targetCell = $targetPhase === $sourcePhase ? $sourceCell : $this->cell($template, $targetPhase, $reference);
            $blockKey = hash('sha256', json_encode([
                self::PROTOCOL, $lab->id, $generationCursor, $blockIndex + 1, $blockType,
                $sourceCell['cell_hash'], $targetCell['cell_hash'],
            ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            $idea = null;
            if ($blockType === 'novelty_pair' && isset($readyIdeas[$ideaCursor])) {
                $idea = (array) $readyIdeas[$ideaCursor++];
                $this->ideas->assign((int) $idea['id'], $blockKey);
            }
            $priority = $this->priority($blockType, $sourcePhase, $seatCount, $evidence, $allocations, $idea !== null);
            $priorityLedger[] = ['block_key' => $blockKey, 'block_type' => $blockType, ...$priority];
            $arms = $this->arms($blockType, $template, $other);
            $learning = $this->learningContract($blockType, $blockIndex, $blockKey, $legacyLearning, $priority);
            foreach ($arms as $arm) {
                $armCell = (bool) ($arm['target_context'] ?? false) ? $targetCell : $sourceCell;
                $spec = $this->armSpec(
                    (array) $arm['template'], $armCell, $arm, $blockKey, $blockType,
                    $blockIndex + 1, $arms, $learning, $priority, $idea,
                );
                $allocated[] = $spec;
                $capsules[] = (array) data_get($spec, 'niche.cooperative_evolution_capsule', []);
            }
            $allocations[$sourcePhase] += intdiv($seatCount, 2);
            if ($targetPhase !== $sourcePhase) {
                $allocations[$targetPhase] += intdiv($seatCount, 2);
            }
            $blockRows[] = [
                'block_key' => $blockKey, 'block_index' => $blockIndex + 1, 'block_type' => $blockType,
                'failure_target' => (bool) data_get($template, 'niche.failure_directed_repair')
                    ? data_get($template, 'target') : null,
                'seat_count' => $seatCount, 'arms' => array_column($arms, 'role'),
                'source_context_cell_key' => $sourceCell['cell_hash'],
                'target_context_cell_key' => $targetCell['cell_hash'],
                'source_venue_phase' => $sourcePhase, 'target_venue_phase' => $targetPhase,
                'priority' => $priority, 'idea_key' => data_get($idea, 'idea_key'),
                'requires_exact_frozen_controls' => true, 'settlement_required' => true,
                'promotion_evidence' => false,
            ];
        }

        if (count($allocated) !== 20) {
            throw new \LogicException('Cooperative council must allocate exactly twenty seats.');
        }
        $seatCounts = collect($blockRows)->groupBy('block_type')->map(fn ($rows): int => $rows->sum('seat_count'))->all();
        $pairQuotas = collect($blockRows)->groupBy('block_type')->map(fn ($rows): int => intdiv($rows->sum('seat_count'), 2))->all();
        $learningBlocks = collect($allocated)->map(fn (array $slot): array => (array) data_get($slot, 'niche.learning_method_contract', []))
            ->unique('block_key')->values()->all();
        $legacyLearning['blocks'] = $learningBlocks;
        $legacyLearning['active_methods'] = collect($learningBlocks)->pluck('learning_method')->filter()->unique()->values()->all();
        $legacyLearning['method_diversity'] = count($legacyLearning['active_methods']);

        $pairCells = collect(array_chunk($allocated, 2))->map(function (array $pair): array {
            $cell = (array) data_get($pair[0], 'niche.contextual_specialist_cell', []);

            return [
                'block_key' => data_get($pair[0], 'niche.cooperative_experiment_block.block_key'),
                'block_type' => data_get($pair[0], 'niche.cooperative_experiment_block.block_type'),
                'session' => data_get($cell, 'session'), 'venue_phase' => data_get($cell, 'venue_phase'),
                'session_instance_id' => data_get($cell, 'session_ownership.session_instance_id'),
                'cell_hash' => data_get($cell, 'cell_hash'), 'market_context_hash' => data_get($cell, 'market_context_hash'),
                'seat_count' => 2,
            ];
        })->all();

        return ['plan' => $allocated, 'contract' => [
            'protocol' => self::PROTOCOL, 'status' => 'allocated', 'phase' => $phase,
            'planned_population' => 20, 'dynamic' => true,
            'permanent_semantic_group_quotas' => false, 'fixed_equal_quota_forbidden' => true,
            'experiment_blocks' => $blockRows, 'block_count' => count($blockRows),
            'seat_counts' => $seatCounts, 'pair_quotas' => $pairQuotas, 'pair_budget' => 10,
            'pair_integrity' => collect($blockRows)->every(fn (array $row): bool => $row['seat_count'] % 2 === 0),
            'cells' => $pairCells,
            'session_pair_counts' => collect($allocated)->countBy(fn (array $slot): string => (string) data_get($slot, 'niche.contextual_specialist_cell.session'))->map(fn (int $seats): int => intdiv($seats, 2))->all(),
            'venue_phase_pair_counts' => collect($allocated)->countBy(fn (array $slot): string => (string) data_get($slot, 'niche.contextual_specialist_cell.venue_phase'))->map(fn (int $seats): int => intdiv($seats, 2))->all(),
            'evidence_snapshot' => $evidence,
            'priority_formula' => 'probability_of_improvement + expected_information_gain + context_coverage_deficit + novelty_score + unresolved_failure_urgency + replication_need - execution_cost - duplicate_penalty - overfit_risk - correlated_failure_penalty',
            'priority_ledger' => $priorityLedger,
            'failure_directed_allocation' => [
                'canonical_reason_counts' => (array) data_get($governorSnapshot, 'reason_counts', []),
                'canonical_target_counts' => (array) data_get($governorSnapshot, 'target_counts', []),
                'repair_pair_targets' => $repairTargets,
                'fallback_used' => $repairTargets === [],
            ],
            'session_selection_policy' => 'venue_phase_coverage_rotation_then_contextual_ucb_with_local_success_failure_and_instrument_posterior',
            'module_species' => $this->species->populationContract($capsules),
            'contextual_elite_archive' => [
                'protocol' => ContextualCapsuleArchiveService::PROTOCOL,
                'axes' => ['regime', 'venue_phase', 'volatility', 'spread_liquidity', 'transition_state', 'direction'],
                'replacement_rule' => 'Only a contextually confirmed Pareto-dominating capsule may replace the local elite.',
                'local_evidence_grants_global_inheritance' => false,
            ],
            'evolutionary_authority_allocation' => [
                'protocol' => EvolutionaryAuthorityLadderService::PROTOCOL, 'phase' => $phase,
                'seat_counts' => $seatCounts, 'promotion_evidence' => false,
            ],
            'evidence_salvage_conveyor' => $salvage,
            'multi_modal_learning_portfolio' => $legacyLearning,
            'cold_start_constitution' => [
                'repair_pairs' => 6, 'structural_novelty_pairs' => 3, 'continuity_adversarial_guard_pairs' => 1,
                'repair_seats' => 12, 'structural_novelty_seats' => 6,
                'continuity_adversarial_guard_seats' => 2,
                'factorial_deferred_until_positive_stepping_stone' => true,
            ],
            'council_router' => [
                'protocol' => ContextualSpecialistAuthorityService::PROTOCOL,
                'selection' => 'exact_context_then_risk_veto_then_highest_lower_confidence_bound',
                'uncertainty_action' => 'BASELINE_OR_WAIT', 'majority_vote_forbidden' => true,
            ],
            'idea_inbox' => ['protocol' => ResearchIdeaInboxService::PROTOCOL,
                'ready_seen' => count($readyIdeas), 'assigned' => $ideaCursor,
                'direct_install_or_inheritance_forbidden' => true],
            'promotion_evidence' => false,
        ]];
    }

    /** @return array<int, string> */
    private function orderedRepairTargets(array $snapshot, int $limit): array
    {
        $counts = collect((array) data_get($snapshot, 'target_counts', []))
            ->map(fn (mixed $count): int => max(0, (int) $count))
            ->filter(fn (int $count): bool => $count > 0)
            ->sortDesc();
        if ($counts->isEmpty()) {
            return [];
        }

        $ordered = [];
        $targets = $counts->keys()->values()->all();
        while (count($ordered) < $limit) {
            foreach ($targets as $target) {
                if (count($ordered) >= $limit) {
                    break 2;
                }
                $alreadyAllocated = count(array_filter($ordered, fn (string $value): bool => $value === $target));
                if ($alreadyAllocated < (int) $counts->get($target)) {
                    $ordered[] = (string) $target;
                }
            }
            if (collect($targets)->every(fn (string $target): bool => count(array_filter($ordered, fn (string $value): bool => $value === $target)) >= (int) $counts->get($target)
            )) {
                break;
            }
        }

        // Six repair pairs remain mandatory in cold start. When fewer than
        // six distinct observations exist, rotate the proven failure targets;
        // never replace the missing work with unrelated independent search.
        for ($index = count($ordered); $index < $limit; $index++) {
            $ordered[] = (string) $targets[$index % count($targets)];
        }

        return $ordered;
    }

    /** @return array<string, mixed> */
    private function templateForRepairTarget(array $plan, string $target, int $index, array $snapshot): array
    {
        $templateTarget = match ($target) {
            'calendar_stability', 'monthly_survival', 'train_forward_robustness',
            'temporal_score_drift', 'parameter_stability' => 'temporal_stability',
            'drawdown_risk', 'ruin_risk' => 'non_target_regression',
            'trade_frequency', 'architecture' => 'regime_coverage',
            default => $target,
        };
        $matches = collect($plan)->filter(fn (array $slot): bool => (string) data_get($slot, 'target', '') === $templateTarget
            && filled(data_get($slot, 'niche.declared_gene'))
        )->values();
        $template = (array) ($matches[$index % max(1, $matches->count())] ?? $plan[$index % count($plan)]);
        $contract = collect((array) data_get($snapshot, 'canonical_gate_contracts', []))
            ->firstWhere('optimization_target', $target);
        $genes = (array) data_get($snapshot, 'failure_specific_plan.'.$target.'.genes', []);
        $schema = app(StrategyParameterSchemaService::class)->schema((string) data_get($template, 'family', 'hybrid'));
        $gene = collect($genes)->first(fn (mixed $candidate): bool => is_string($candidate) && array_key_exists($candidate, $schema));
        $niche = (array) data_get($template, 'niche', []);
        if (is_string($gene) && $gene !== '') {
            $niche['declared_gene'] = $gene;
            unset($niche['declared_value']);
        }
        $niche['failure_target'] = $target;
        $niche['mutation_target'] = $target;
        $niche['rescue_lane'] = (string) data_get($contract, 'lane', $target);
        $niche['canonical_gate_contract'] = is_array($contract) ? $contract : null;
        $niche['failure_directed_repair'] = true;
        $niche['independent_exploration_forbidden'] = true;

        return [...$template, 'target' => $target, 'niche' => $niche];
    }

    /** @return array<int,array<string,mixed>> */
    private function arms(string $type, array $a, array $b): array
    {
        return match ($type) {
            'factorial' => [
                ['role' => 'control', 'template' => $a, 'intervention' => null],
                ['role' => 'a_only', 'template' => $a, 'intervention' => $this->intervention($a)],
                ['role' => 'b_only', 'template' => $b, 'intervention' => $this->intervention($b), 'pair_baseline_intervention' => true],
                ['role' => 'a_plus_b', 'template' => $a, 'intervention' => $this->intervention($a), 'baseline_arm' => 'b_only'],
            ],
            'transfer' => [
                ['role' => 'source_control', 'template' => $a, 'intervention' => null],
                ['role' => 'source_candidate', 'template' => $a, 'intervention' => $this->intervention($a)],
                ['role' => 'target_control', 'template' => $a, 'intervention' => null, 'target_context' => true],
                ['role' => 'target_candidate', 'template' => $a, 'intervention' => $this->intervention($a), 'target_context' => true],
            ],
            'descendant' => [
                ['role' => 'parent_control', 'template' => $a, 'intervention' => null],
                ['role' => 'parent_reference', 'template' => $a, 'intervention' => $this->intervention($a)],
                ['role' => 'descendant_control', 'template' => $b, 'intervention' => null],
                ['role' => 'child_challenge', 'template' => $b, 'intervention' => $this->intervention($b)],
            ],
            default => [
                ['role' => 'exact_frozen_control', 'template' => $a, 'intervention' => null],
                ['role' => in_array($type, ['coverage_guard', 'adversarial_guard'], true) ? 'guard_challenge' : 'candidate',
                    'template' => $a, 'intervention' => $this->intervention($a)],
            ],
        };
    }

    /** @return array<string,mixed> */
    private function armSpec(array $template, array $cell, array $arm, string $blockKey, string $blockType, int $blockIndex, array $arms, array $learning, array $priority, ?array $idea): array
    {
        $niche = (array) data_get($template, 'niche', []);
        unset(
            $niche['control_pair_contract'], $niche['declared_values'],
            $niche['causal_learning_cohort'], $niche['causal_confirmation_source_lesson_id'],
            $niche['causal_repair_source_experiment_id']
        );
        $intervention = (array) ($arm['intervention'] ?? []);
        if ($intervention === []) {
            unset($niche['declared_gene'], $niche['declared_value'], $niche['learning_evolution']);
        } else {
            $niche['declared_gene'] = $intervention['gene'];
            if (array_key_exists('value', $intervention)) {
                $niche['declared_value'] = $intervention['value'];
            }
        }
        $block = [
            'protocol' => self::PROTOCOL, 'block_key' => $blockKey, 'block_index' => $blockIndex,
            'block_type' => $blockType, 'seat_count' => self::BLOCK_SEATS[$blockType],
            'failure_target' => (bool) data_get($template, 'niche.failure_directed_repair')
                ? data_get($template, 'target') : null,
            'arm' => $arm['role'], 'arm_ordinal' => array_search($arm['role'], array_column($arms, 'role'), true) + 1,
            'required_arms' => array_column($arms, 'role'),
            'pair_baseline_intervention' => (bool) ($arm['pair_baseline_intervention'] ?? false),
            'baseline_arm' => $arm['baseline_arm'] ?? null, 'priority' => $priority,
            'candidate_control_same_context_required' => $blockType !== 'transfer',
            'settlement_required' => true, 'research_nursery_only' => true, 'promotion_evidence' => false,
        ];
        $learning = $this->bindLearningContext($learning, $cell, $blockType, (string) $arm['role']);
        $sourceIntervention = (array) data_get($learning, 'source_reference.intervention', []);
        if ($sourceIntervention !== [] && in_array((string) $arm['role'], [
            'candidate', 'a_only', 'a_plus_b', 'source_candidate', 'target_candidate',
            'parent_reference', 'child_challenge',
        ], true) && in_array((string) data_get($learning, 'learning_method'), [
            'positive_skill_replication', 'counterfactual_factorial', 'context_transfer_validation',
        ], true)) {
            $niche['learning_evolution'] = [
                'protocol' => MultiModalLearningPortfolioService::PROTOCOL,
                'experiment_role' => 'exploit', 'required_gene' => data_get($sourceIntervention, 'gene'),
                'mutation_from' => data_get($sourceIntervention, 'old_value'),
                'mutation_to' => data_get($sourceIntervention, 'new_value'),
                'consumed_receipt_ids' => [data_get($learning, 'selection_receipt.receipt_hash')],
                'inherited_components' => [data_get($sourceIntervention, 'gene')],
                'required_component' => data_get($sourceIntervention, 'gene'),
                'same_context_only' => $blockType !== 'transfer', 'promotion_evidence' => false,
            ];
        }
        $niche = [...$niche, 'contextual_specialist_cell' => $cell,
            'cooperative_experiment_block' => $block,
            'learning_method_contract' => [...$learning, 'experiment_arm' => $arm['role']],
            'outside_scope_action' => 'WAIT'];
        $spec = [...$template,
            'target' => (bool) data_get($template, 'niche.failure_directed_repair')
                ? (string) data_get($template, 'target', $blockType) : $blockType,
            'research_group' => 'experiment_block:'.$blockType,
            'group_axis' => 'cooperative_causal_block', 'group_seat' => $block['arm_ordinal'],
            'group_search_mode' => 'dynamic_priority', 'group_search_role' => $arm['role'], 'niche' => $niche];
        data_set($spec, 'niche.cooperative_evolution_capsule', $this->species->assemble($spec, $cell, $block, $idea));

        return $spec;
    }

    /** @return array<string,mixed> */
    private function intervention(array $template): array
    {
        $niche = (array) data_get($template, 'niche', []);
        $row = ['gene' => (string) (data_get($niche, 'declared_gene') ?: 'minimum_signal_confidence')];
        // Composite values describe a module/session envelope, not a legal
        // scalar gene mutation. Keep only the gene intent and let the schema
        // compiler choose a bounded value for those cases.
        if (array_key_exists('declared_value', $niche)
            && (is_scalar($niche['declared_value']) || $niche['declared_value'] === null)) {
            $row['value'] = data_get($niche, 'declared_value');
        }

        return $row;
    }

    private function differentGeneTemplate(array $plan, array $template, int $offset): array
    {
        $gene = (string) data_get($template, 'niche.declared_gene', '');

        return (array) (collect(array_merge(array_slice($plan, $offset), array_slice($plan, 0, $offset)))->first(
            fn (array $row): bool => filled(data_get($row, 'niche.declared_gene'))
                && (string) data_get($row, 'family', '') === (string) data_get($template, 'family', '')
                && (string) data_get($row, 'niche.declared_gene') !== $gene
        ) ?? $template);
    }

    /** @return array<string,mixed> */
    private function cell(array $template, string $phase, mixed $reference): array
    {
        $niche = (array) data_get($template, 'niche', []);
        $market = [
            'regime' => (string) data_get($niche, 'regime', 'unknown_regime'),
            'venue_phase' => $phase, 'session' => $this->calendar->legacySessionForPhase($phase),
            'volatility' => (string) data_get($niche, 'volatility', 'normal_volatility'),
            'spread_liquidity_state' => $phase === 'comex_maintenance' ? 'closed_or_maintenance' : (string) data_get($niche, 'spread_liquidity_state', 'liquid'),
            'transition_state' => $phase === 'comex_maintenance' ? 'transition' : (string) data_get($niche, 'transition_state', 'stable'),
            'direction' => (string) data_get($niche, 'direction', 'both'),
        ];
        $hash = hash('sha256', json_encode($market, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        return ['protocol' => self::PROTOCOL, ...$market, 'cell_hash' => $hash, 'market_context_hash' => $hash,
            'session_ownership' => $this->calendar->specialistOwnership($phase, $reference),
            'trait_gene' => data_get($niche, 'declared_gene'),
            'execution_policy' => $phase === 'comex_maintenance' ? 'abstain_only' : 'context_owned',
            'outside_scope_action' => 'WAIT', 'promotion_scope' => 'same_context_only', 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    private function learningContract(string $type, int $index, string $blockKey, array $portfolio, array $priority): array
    {
        $desired = match ($type) {
            'repair_pair' => 'failure_directed_repair', 'replication' => 'positive_skill_replication',
            'factorial' => 'counterfactual_factorial', 'transfer' => 'context_transfer_validation',
            'descendant' => 'positive_skill_replication', 'coverage_guard' => 'elite_rehearsal_guard',
            'adversarial_guard' => 'adversarial_robustness', default => 'quality_diversity_novelty',
        };
        $sourceKey = match ($desired) {
            'failure_directed_repair' => 'failure',
            'positive_skill_replication', 'counterfactual_factorial', 'context_transfer_validation' => 'causal_skill',
            'elite_rehearsal_guard' => 'economic_parent', 'adversarial_robustness' => 'adversarial', default => 'archive',
        };
        $source = data_get($portfolio, 'source_references.'.$sourceKey);
        $requires = in_array($desired, ['positive_skill_replication', 'counterfactual_factorial', 'context_transfer_validation', 'elite_rehearsal_guard'], true);
        $method = $requires && ! is_array($source) ? ($desired === 'elite_rehearsal_guard' ? 'adversarial_robustness' : 'bayesian_active_learning') : $desired;

        return [
            'protocol' => MultiModalLearningPortfolioService::PROTOCOL, 'block_key' => $blockKey,
            'block_index' => $index + 1, 'learning_method' => $method,
            'deferred_learning_method' => $method !== $desired ? $desired : null,
            'source_reference' => $source, 'source_reference_status' => is_array($source) ? 'bound_before_mutation' : ($requires ? 'missing_fail_closed' : 'not_required'),
            'source_reference_required' => $method === $desired && $requires,
            'source_context_required' => $method === $desired && $requires,
            'selection_receipt' => ['receipt_hash' => hash('sha256', json_encode([self::PROTOCOL, $blockKey, $method, $source, $priority], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
                'status' => 'pre_registered', 'selected_before_mutation' => true, 'result_link_pending' => true, 'promotion_evidence' => false],
            'settlement_must_link_to_receipt' => true, 'requires_exact_frozen_control' => true,
            'research_nursery_only' => true,
            'authority_ceiling' => 'research_only_until_dedicated_causal_settlement', 'promotion_evidence' => false,
        ];
    }

    /** @return array<string,float> */
    private function priority(string $type, string $phase, int $seats, array $evidence, array $allocations, bool $hasIdea): array
    {
        $row = (array) data_get($evidence, 'venue_phases.'.$phase, []);
        $n = (int) data_get($row, 'observations', 0);
        $wins = (int) data_get($row, 'successes', 0);
        $failures = (int) data_get($row, 'failures', 0);
        $near = (int) data_get($row, 'near_passes', 0);
        $p = ($wins + (.5 * $near) + 1) / ($n + 2);
        $posteriorN = (int) data_get($row, 'posterior_observations', 0);
        $posteriorMean = $posteriorN > 0 ? (float) data_get($row, 'posterior_utility_sum', 0) / $posteriorN : 0.0;
        $p = max(.01, min(.99, $p + (.20 * max(-1.0, min(1.0, $posteriorMean)))));
        $eig = $this->binaryEntropy(max(.0001, min(.9999, $p))) / sqrt($n + 1);
        $coverage = 1 / sqrt($n + 1);
        $novelty = ((int) data_get($row, 'archive_cells', 0) === 0 ? 1.0 : .25) + ($hasIdea ? .5 : 0);
        $failureUrgency = ($failures / max(1, $n)) + min(1.0, (int) data_get($evidence, 'open_failures', 0) / 10);
        $replication = in_array($type, ['replication', 'factorial', 'descendant'], true) ? min(1.5, (int) data_get($evidence, 'credits.causal_skill_credit', 0) / 3) : 0.0;
        $cost = $seats / 4;
        $duplicate = min(1.0, (float) data_get($row, 'duplicate_rate', 0));
        $overfit = $wins > 0 && $n < 3 ? .65 : 0.0;
        $correlated = .25 * max(0, (int) ($allocations[$phase] ?? 0) - 1);
        $informationCredit = (int) data_get($evidence, 'credits.information_credit', 0);
        $repairCredit = (int) data_get($evidence, 'credits.repair_credit', 0);
        $creditBoost = in_array($type, ['novelty_pair', 'factorial'], true)
            ? min(1.0, log($informationCredit + 1) / log(6))
            : ($type === 'repair_pair' ? min(1.25, log($repairCredit + 1) / log(4)) : 0.0);
        $score = $p + $eig + $coverage + $novelty + $failureUrgency + $replication + $creditBoost - $cost - $duplicate - $overfit - $correlated;

        return ['score' => round($score, 6), 'probability_of_improvement' => round($p, 6),
            'expected_information_gain' => round($eig, 6), 'context_coverage_deficit' => round($coverage, 6),
            'novelty_score' => round($novelty, 6), 'unresolved_failure_urgency' => round($failureUrgency, 6),
            'replication_need' => round($replication, 6), 'execution_cost' => round($cost, 6),
            'learning_credit_routing_boost' => round($creditBoost, 6),
            'duplicate_penalty' => round($duplicate, 6), 'overfit_risk' => round($overfit, 6),
            'correlated_failure_penalty' => round($correlated, 6)];
    }

    private function selectPhase(string $type, int $index, int $generation, array $evidence, array $allocations): string
    {
        $coverage = ['comex_maintenance', 'london_comex_overlap', 'asia_sge_night', 'asia_sge_day', 'london_am_fix', 'comex_pre_settlement'];
        if ($index < count($coverage)) {
            return $coverage[$index];
        }
        $scores = [];
        foreach ($this->calendar->researchPhases() as $phaseIndex => $phase) {
            $scores[$phase] = $this->priority($type, $phase, self::BLOCK_SEATS[$type], $evidence, $allocations, false)['score']
                + ((($index + $generation) % count($this->calendar->researchPhases())) === $phaseIndex ? .0001 : 0);
        }

        return (string) collect($scores)->sortDesc()->keys()->first();
    }

    private function differentPhase(string $source, array $evidence, array $allocations, int $rotation): string
    {
        return (string) collect($this->calendar->researchPhases())->reject(fn (string $phase): bool => $phase === $source)
            ->sortBy(fn (string $phase, int $index): array => [(int) ($allocations[$phase] ?? 0), (int) data_get($evidence, 'venue_phases.'.$phase.'.observations', 0), ($index + $rotation) % 11])->first();
    }

    private function phaseForSourceScope(array $scope, string $fallback): string
    {
        $source = strtolower((string) data_get($scope, 'venue_phase', data_get($scope, 'session', '')));
        if (in_array($source, $this->calendar->researchPhases(), true)) {
            return $source;
        }
        $match = collect($this->calendar->researchPhases())->first(
            fn (string $phase): bool => $this->calendar->legacySessionForPhase($phase) === $source
        );

        return is_string($match) ? $match : $fallback;
    }

    /** @return array<string,mixed> */
    private function bindLearningContext(array $learning, array $cell, string $blockType, string $arm): array
    {
        if (! (bool) data_get($learning, 'source_context_required', false)) {
            return $learning;
        }
        $scope = (array) data_get($learning, 'source_reference.context_scope', []);
        $sourceSession = strtolower((string) data_get($scope, 'venue_phase', data_get($scope, 'session', '')));
        $cellPhase = strtolower((string) data_get($cell, 'venue_phase', ''));
        $cellSession = strtolower((string) data_get($cell, 'session', ''));
        $sameSession = $sourceSession === '' || $sourceSession === $cellPhase || $sourceSession === $cellSession;
        $sourceRegime = strtolower((string) data_get($scope, 'regime', ''));
        $sameRegime = $sourceRegime === '' || $sourceRegime === strtolower((string) data_get($cell, 'regime', ''));
        $targetArm = $blockType === 'transfer' && str_starts_with($arm, 'target_');
        $status = $targetArm
            ? ((! $sameSession || ! $sameRegime) ? 'target_context_isolated' : 'source_target_not_separated_fail_closed')
            : (($sameSession && $sameRegime) ? 'same_source_context_bound' : 'source_context_mismatch_fail_closed');
        $learning['context_binding'] = [
            'status' => $status, 'source_context' => $scope,
            'target_cell_hash' => data_get($cell, 'cell_hash'),
            'target_context' => ['regime' => data_get($cell, 'regime'), 'session' => data_get($cell, 'session'),
                'venue_phase' => data_get($cell, 'venue_phase')],
            'promotion_evidence' => false,
        ];

        return $learning;
    }

    /** @return array<string,mixed> */
    private function evidence(AiLaboratory $lab): array
    {
        $phases = array_fill_keys($this->calendar->researchPhases(), [
            'observations' => 0, 'successes' => 0, 'failures' => 0, 'near_passes' => 0,
            'posterior_observations' => 0, 'posterior_utility_sum' => 0.0, 'archive_cells' => 0, 'duplicate_rate' => 0.0]);
        $ids = $lab->generations()->latest('generation')->limit(8)->pluck('id');
        $agents = $ids->isEmpty() ? collect() : LabAgent::query()->with('modelVersion')->whereIn('lab_generation_id', $ids)->get();
        foreach ($agents as $agent) {
            $phase = strtolower((string) data_get($agent->modelVersion?->metadata, 'specialist_council_membership.contextual_cell.venue_phase', ''));
            if (! isset($phases[$phase])) {
                $legacy = strtolower((string) data_get($agent->modelVersion?->metadata, 'specialist_council_membership.contextual_cell.session', ''));
                $phase = (string) (collect(array_keys($phases))->first(
                    fn (string $candidate): bool => $this->calendar->legacySessionForPhase($candidate) === $legacy
                ) ?? '');
            }
            if (! isset($phases[$phase])) {
                continue;
            }
            $phases[$phase]['observations']++;
            if (in_array((string) $agent->lifecycle_status, ['challenger', 'forward_validated', 'paper', 'champion'], true)) {
                $phases[$phase]['successes']++;
            }
            if (in_array((string) $agent->lifecycle_status, ['rejected', 'failed', 'overfit', 'stagnated', 'technical_quarantine'], true)) {
                $phases[$phase]['failures']++;
            }
            if (data_get($agent->modelVersion?->metadata, 'mutation_observability.control_relative_improved') === true) {
                $phases[$phase]['near_passes']++;
            }
        }
        if (Schema::hasTable('instrument_value_posteriors')) {
            foreach (InstrumentValuePosterior::query()->where('symbol', strtoupper($lab->symbol))->where('observations', '>', 0)->limit(500)->get() as $posterior) {
                $parts = explode('|', (string) $posterior->state_key);
                $phase = strtolower((string) ($parts[8] ?? ''));
                $targets = isset($phases[$phase]) ? [$phase] : collect(array_keys($phases))->filter(
                    fn (string $candidate): bool => $this->calendar->legacySessionForPhase($candidate) === strtolower((string) ($parts[1] ?? ''))
                )->values()->all();
                if ($targets === []) {
                    continue;
                }
                $count = max(1, (int) $posterior->observations);
                foreach ($targets as $target) {
                    $phases[$target]['posterior_observations'] += $count;
                    $phases[$target]['posterior_utility_sum'] += (float) $posterior->net_value * $count;
                }
            }
        }
        if (Schema::hasTable('contextual_specialist_capsules')) {
            $capsules = DB::table('contextual_specialist_capsules')->where('symbol', strtoupper($lab->symbol))
                ->where('timeframe', strtoupper($lab->timeframe))->get(['identity', 'components']);
            $byPhase = $capsules->groupBy(fn ($row): string => strtolower((string) data_get(json_decode((string) $row->identity, true), 'venue_phase', '')));
            foreach ($byPhase as $phase => $rows) {
                if (! isset($phases[$phase])) {
                    continue;
                }
                $phases[$phase]['archive_cells'] = $rows->count();
                $unique = $rows->map(fn ($row): string => hash('sha256', (string) $row->components))->unique()->count();
                $phases[$phase]['duplicate_rate'] = $rows->count() > 0 ? round(1 - ($unique / $rows->count()), 6) : 0.0;
            }
        }
        $credits = array_fill_keys(EvolutionaryAuthorityLadderService::CREDIT_LADDER, 0);
        if (Schema::hasTable('lab_evolution_credit_events')) {
            foreach (DB::table('lab_evolution_credit_events')->where('symbol', strtoupper($lab->symbol))->where('timeframe', strtoupper($lab->timeframe))
                ->where('amount', '>', 0)->selectRaw('event_type, COUNT(*) aggregate')->groupBy('event_type')->get() as $credit) {
                $type = in_array((string) $credit->event_type, ['learning', 'discovery'], true) ? 'information_credit' : (string) $credit->event_type;
                if (array_key_exists($type, $credits)) {
                    $credits[$type] += (int) $credit->aggregate;
                }
            }
        }

        return ['venue_phases' => $phases, 'credits' => $credits,
            'open_failures' => Schema::hasTable('lab_failure_repair_anchors') ? DB::table('lab_failure_repair_anchors')
                ->where('symbol', strtoupper($lab->symbol))->where('timeframe', strtoupper($lab->timeframe))->where('status', 'open')->count() : 0,
            'recent_agent_count' => $agents->count()];
    }

    private function learningEvidence(array $evidence): array
    {
        $legacy = [];
        foreach (['asia', 'london', 'new_york', 'overlap'] as $session) {
            $rows = collect((array) data_get($evidence, 'venue_phases', []))->filter(fn (array $_row, string $phase): bool => $this->calendar->legacySessionForPhase($phase) === $session);
            $legacy[$session] = ['observations' => $rows->sum('observations'), 'successes' => $rows->sum('successes'),
                'failures' => $rows->sum('failures'), 'posterior_observations' => $rows->sum('posterior_observations')];
        }

        return ['__sessions' => ['__global' => $legacy]];
    }

    private function steppingStoneAvailable(AiLaboratory $lab): bool
    {
        if (Schema::hasTable('lab_evolution_credit_events') && DB::table('lab_evolution_credit_events')
            ->where('symbol', strtoupper($lab->symbol))->where('timeframe', strtoupper($lab->timeframe))
            ->where('event_type', 'causal_skill_credit')->where('amount', '>', 0)->exists()) {
            return true;
        }

        return Schema::hasTable('contextual_specialist_capsules') && DB::table('contextual_specialist_capsules')
            ->where('symbol', strtoupper($lab->symbol))->where('timeframe', strtoupper($lab->timeframe))
            ->whereIn('authority_level', ['research_mentor', 'economic_parent', 'contextually_confirmed'])->exists();
    }

    private function referenceTimestamp(AiLaboratory $lab): mixed
    {
        $symbolId = Symbol::query()->where('code', strtoupper($lab->symbol))->value('id');

        return $symbolId ? (Candle::query()->where('symbol_id', $symbolId)->latest('time')->value('time') ?: now()->utc()) : now()->utc();
    }

    private function binaryEntropy(float $p): float
    {
        return -($p * log($p, 2)) - ((1 - $p) * log(1 - $p, 2));
    }
}
