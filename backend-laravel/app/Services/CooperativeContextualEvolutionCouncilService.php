<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\Candle;
use App\Models\CooperativeExperimentSettlement;
use App\Models\InstrumentValuePosterior;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\Symbol;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Allocates the ordinary twenty seats to causal experiment blocks, not fixed groups. */
class CooperativeContextualEvolutionCouncilService
{
    public const PROTOCOL = 'cooperative_contextual_evolution_council_v1';

    public const UNCERTAINTY_ABSTAIN_PROTOCOL = 'uncertainty_abstain_guard_v1';

    public const FEEDBACK_PROTOCOL = 'cooperative_allocation_feedback_v1';

    private const BLOCK_SEATS = [
        'repair_pair' => 2, 'novelty_pair' => 2, 'replication' => 2,
        'factorial' => 4, 'activation_factorial' => 4, 'phase_scope_probe' => 2,
        'transfer' => 4, 'descendant' => 4,
        'coverage_guard' => 2, 'adversarial_guard' => 2,
    ];

    public function __construct(
        private MarketSessionCalendarService $calendar,
        private CooperativeModuleSpeciesService $species,
        private ResearchIdeaInboxService $ideas,
        private EvidenceSalvageConveyorService $salvage,
    ) {}

    /** @return array{plan:array<int,array<string,mixed>>,contract:array<string,mixed>} */
    public function allocate(array $plan, AiLaboratory $lab, array $governorSnapshot = [], ?int $generationNumber = null): array
    {
        $plan = array_values($plan);
        if (count($plan) !== 20) {
            return ['plan' => $plan, 'contract' => [
                'protocol' => self::PROTOCOL, 'status' => 'not_applicable_non_twenty_seat_population',
                'planned_population' => count($plan), 'dynamic' => false, 'promotion_evidence' => false,
            ]];
        }

        $protectedIndexes = collect($plan)->keys()->filter(fn (int $index): bool => filled(
            data_get($plan[$index], 'niche.causal_learning_cohort.role'),
        ))->values()->all();
        if ($protectedIndexes !== [] && count($protectedIndexes) !== 3) {
            return ['plan' => $plan, 'contract' => [
                'protocol' => self::PROTOCOL,
                'status' => 'not_applicable_incomplete_causal_proof_set',
                'planned_population' => count($plan),
                'protected_causal_proof_slots' => array_map(fn (int $index): int => $index + 1, $protectedIndexes),
                'dynamic' => false,
                'promotion_evidence' => false,
            ]];
        }
        $freeIndexes = collect(array_keys($plan))->reject(
            fn (int $index): bool => in_array($index, $protectedIndexes, true),
        )->values()->all();
        $abstainIndex = $protectedIndexes !== [] && count($freeIndexes) % 2 === 1
            ? array_pop($freeIndexes)
            : null;
        $workingPlan = collect($freeIndexes)->map(fn (int $index): array => (array) $plan[$index])->values()->all();
        if ($workingPlan === [] || count($workingPlan) % 2 !== 0) {
            return ['plan' => $plan, 'contract' => [
                'protocol' => self::PROTOCOL,
                'status' => 'not_applicable_unpairable_free_seats',
                'planned_population' => count($plan),
                'protected_causal_proof_slots' => array_map(fn (int $index): int => $index + 1, $protectedIndexes),
                'dynamic' => false,
                'promotion_evidence' => false,
            ]];
        }

        $evidence = $this->evidence($lab);
        $salvage = $this->salvage->planForLab($lab, 20);
        $steppingStone = $this->steppingStoneAvailable($lab);
        $generationCursor = $generationNumber ?? (((int) ($lab->generations()->max('generation') ?? 0)) + 1);
        if ($generationCursor < 1) {
            throw new \InvalidArgumentException('Generation number must be positive.');
        }
        $feedback = $this->allocationFeedback($lab, $generationCursor);
        $phase = $steppingStone ? 'causal_compounding' : 'cold_start';
        $proofFrontier = $steppingStone
            ? ['protocol' => ProofFrontierService::PROTOCOL, 'status' => 'not_needed_positive_stepping_stone']
            : app(ProofFrontierService::class)->propose($lab, $workingPlan);
        $activationProposal = (string) data_get($proofFrontier, 'status') === 'proposed'
            ? (array) data_get($proofFrontier, 'proposal', []) : [];
        $phaseScope = $activationProposal === [] && ! $steppingStone
            ? app(ProofFrontierService::class)->proposePhaseScope($lab, $workingPlan)
            : ['protocol' => ProofFrontierService::PHASE_PROBE_PROTOCOL, 'status' => 'not_needed'];
        $phaseProposal = (string) data_get($phaseScope, 'status') === 'proposed'
            ? (array) data_get($phaseScope, 'proposal', []) : [];
        $baselineTypes = $this->blockTypes($steppingStone, count($workingPlan));
        $feedbackAllocation = $this->feedbackBlockTypes($baselineTypes, $feedback, $steppingStone);
        $types = $feedbackAllocation['types'];
        $frontierReplacement = [];
        if ($activationProposal !== [] && ! $steppingStone) {
            $repairIndexes = array_keys(array_filter($types, fn (string $type): bool => $type === 'repair_pair'));
            if (count($repairIndexes) < 2) {
                throw new \LogicException('Activation requires two repair pairs to replace.');
            }
            $frontierReplacement = array_slice($repairIndexes, 0, 2);
            $types = ['activation_factorial', ...array_values(array_filter(
                $types, fn (string $_type, int $index): bool => ! in_array($index, $frontierReplacement, true), ARRAY_FILTER_USE_BOTH,
            ))];
        } elseif ($phaseProposal !== [] && ! $steppingStone) {
            $repairIndexes = array_keys(array_filter($types, fn (string $type): bool => $type === 'repair_pair'));
            if ($repairIndexes === []) {
                throw new \LogicException('Phase scope probe requires one repair pair to replace.');
            }
            $frontierReplacement = [reset($repairIndexes)];
            $types = ['phase_scope_probe', ...array_values(array_filter(
                $types, fn (string $_type, int $index): bool => ! in_array($index, $frontierReplacement, true), ARRAY_FILTER_USE_BOTH,
            ))];
        }
        $legacyLearning = app(MultiModalLearningPortfolioService::class)->planForLab(
            $lab,
            app(EvolutionaryAuthorityLadderService::class)->experimentBlocks($steppingStone),
            $this->learningEvidence($evidence),
        );
        $reference = $this->referenceTimestamp($lab);
        $readyIdeas = $this->ideas->ready($lab->symbol, $lab->timeframe);
        $ideaCursor = 0;
        $allocations = array_fill_keys($this->calendar->researchPhases(), 0);
        $allocated = [];
        $blockRows = [];
        $capsules = [];
        $priorityLedger = [];
        $familyTemplates = collect($workingPlan)->groupBy(fn (array $slot): string => (string) data_get($slot, 'family', 'hybrid'));
        $families = $familyTemplates->keys()->values();
        $repairPairCount = collect($types)->filter(fn (string $type): bool => $type === 'repair_pair')->count();
        $repairTargets = $this->orderedRepairTargets($governorSnapshot, $repairPairCount);
        $repairCursor = 0;

        foreach ($types as $blockIndex => $blockType) {
            $seatCount = self::BLOCK_SEATS[$blockType];
            if ($blockType === 'phase_scope_probe') {
                $template = $this->phaseScopeTemplate($phaseProposal);
                $family = (string) $template['family'];
            } elseif ($blockType === 'activation_factorial') {
                $template = (bool) data_get($activationProposal, 'source_owned_probe', false)
                    ? $this->phaseScopeTemplate($activationProposal)
                    : (array) $workingPlan[(int) $activationProposal['base_index']];
                if ((bool) data_get($activationProposal, 'source_owned_probe', false)) {
                    data_set($template, 'niche.declared_gene', data_get($activationProposal, 'a.gene'));
                    data_set($template, 'niche.declared_value', data_get($activationProposal, 'a.value'));
                }
                $family = (string) data_get($template, 'family', 'hybrid');
            } elseif ($blockType === 'repair_pair' && $repairTargets !== []) {
                $repairTarget = $repairTargets[$repairCursor++ % count($repairTargets)];
                $template = $this->templateForRepairTarget($workingPlan, $repairTarget, $blockIndex, $governorSnapshot);
                $family = (string) data_get($template, 'family', 'hybrid');
            } else {
                $family = (string) $families[$blockIndex % max(1, $families->count())];
                $bucket = $familyTemplates->get($family, collect($plan))->values();
                $template = (array) $bucket[intdiv($blockIndex, max(1, $families->count())) % max(1, $bucket->count())];
            }
            $other = match ($blockType) {
                'activation_factorial' => (bool) data_get($activationProposal, 'source_owned_probe', false)
                    ? $template : (array) $workingPlan[(int) $activationProposal['other_index']],
                'phase_scope_probe' => $template,
                default => $this->differentGeneTemplate($workingPlan, $template, $blockIndex + 1),
            };
            if ($blockType === 'activation_factorial'
                && (bool) data_get($activationProposal, 'source_owned_probe', false)) {
                data_set($other, 'niche.declared_gene', data_get($activationProposal, 'b.gene'));
                data_set($other, 'niche.declared_value', data_get($activationProposal, 'b.value'));
            }
            $sourcePhase = $blockType === 'activation_factorial'
                && in_array((string) data_get($activationProposal, 'source_venue_phase'), $this->calendar->researchPhases(), true)
                ? (string) $activationProposal['source_venue_phase']
                : ($blockType === 'phase_scope_probe'
                    ? (string) $phaseProposal['venue_phase']
                    : $this->selectPhase($blockType, $blockIndex, $generationCursor, $evidence, $allocations));
            $causalSourceScope = (array) data_get($legacyLearning, 'source_references.causal_skill.context_scope', []);
            if ($steppingStone && in_array($blockType, ['replication', 'factorial', 'transfer', 'descendant'], true)) {
                $sourcePhase = $this->phaseForSourceScope($causalSourceScope, $sourcePhase);
            }
            $targetPhase = $blockType === 'transfer'
                ? $this->differentPhase($sourcePhase, $evidence, $allocations, $blockIndex + $generationCursor)
                : $sourcePhase;
            $sourceCell = $this->cell($template, $sourcePhase, $reference);
            $targetCell = $targetPhase === $sourcePhase ? $sourceCell : $this->cell($template, $targetPhase, $reference);
            $idea = null;
            // Match the submitted design before freezing a block. Do not route
            // factorial/repair requests through an unrelated novelty pair.
            foreach ($readyIdeas as $readyIdea) {
                if (isset($readyIdea['claimed']) || data_get($readyIdea, 'executable_contract.required_block_type') !== $blockType) continue;
                if (! in_array($blockType, ['repair_pair', 'novelty_pair', 'adversarial_guard'], true)) continue;
                $ideaPhase = (string) data_get($readyIdea, 'executable_contract.context_scope.venue_phase', $sourcePhase);
                if (! in_array($ideaPhase, $this->calendar->researchPhases(), true)) continue;
                $templates = $blockType === 'novelty_pair' ? $familyTemplates->get($family, collect([$template]))->all() : [$template];
                foreach ($templates as $ideaTemplate) {
                    $ideaCell = $this->cell((array) $ideaTemplate, $ideaPhase, $reference);
                    if (! $this->ideas->designMatches($readyIdea, $blockType, $ideaCell, $this->intervention((array) $ideaTemplate))) continue;
                    $idea = $readyIdea;
                    $template = (array) $ideaTemplate;
                    $sourcePhase = $targetPhase = $ideaPhase;
                    $sourceCell = $targetCell = $ideaCell;
                    $other = $this->differentGeneTemplate($workingPlan, $template, $blockIndex + 1);
                    break 2;
                }
            }
            $blockKey = hash('sha256', json_encode([
                self::PROTOCOL, $lab->id, $generationCursor, $blockIndex + 1, $blockType,
                $sourceCell['cell_hash'], $targetCell['cell_hash'], $feedback['settlement_digest'],
                ...($idea !== null ? [data_get($idea, 'idea_key'), data_get($idea, 'executable_contract')] : []),
            ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            if ($idea !== null) {
                if (! $this->ideas->assign((int) $idea['id'], $blockKey)) {
                    throw new \LogicException('Idea design was concurrently claimed; allocation must be retried.');
                }
                foreach ($readyIdeas as &$readyIdea) {
                    if ($readyIdea['id'] === $idea['id']) $readyIdea['claimed'] = true;
                }
                unset($readyIdea);
                $ideaCursor++;
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
                    $blockType === 'activation_factorial' ? $activationProposal
                        : ($blockType === 'phase_scope_probe' ? $phaseProposal : []),
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
                'idea_design' => data_get($idea, 'executable_contract'),
                'requires_exact_frozen_controls' => true, 'settlement_required' => true,
                'promotion_evidence' => false,
            ];
        }

        if (count($allocated) !== count($workingPlan)) {
            throw new \LogicException('Cooperative council must fill every allocatable seat.');
        }
        $finalPlan = $allocated;
        $abstainSlots = [];
        if ($protectedIndexes !== []) {
            $finalPlan = $plan;
            foreach ($freeIndexes as $cursor => $index) {
                $finalPlan[$index] = $allocated[$cursor];
            }
            if ($abstainIndex !== null) {
                $abstainCell = $this->cell((array) $plan[$abstainIndex], 'comex_maintenance', $reference);
                $finalPlan[$abstainIndex] = $this->uncertaintyAbstainSpec((array) $plan[$abstainIndex], $abstainCell);
                $abstainSlots[] = $abstainIndex + 1;
            }
            ksort($finalPlan);
            $finalPlan = array_values($finalPlan);
        }
        $seatOwnership = $this->seatOwnership($finalPlan, $generationCursor);
        foreach ($blockRows as &$row) {
            $row['seat_numbers'] = collect($seatOwnership)
                ->filter(fn (array $owner): bool => $owner['kind'] === 'cooperative_block'
                    && $owner['key'] === $row['block_key'])
                ->pluck('seat')->values()->all();
            if (count($row['seat_numbers']) !== $row['seat_count']) {
                throw new \LogicException('Cooperative block seat ownership is incomplete.');
            }
        }
        unset($row);
        if (($feedbackAllocation['decision']['status'] ?? '') === 'reallocated_research_only') {
            $sourceIndex = (int) $feedbackAllocation['decision']['block_index'] - 1;
            $remaining = array_values(array_filter(array_keys($feedbackAllocation['types']),
                fn (int $index): bool => ! in_array($index, $frontierReplacement, true)));
            $survivorIndex = array_search($sourceIndex, $remaining, true);
            if ($survivorIndex === false) {
                throw new \LogicException('Feedback-changed block cannot be displaced by activation.');
            }
            $finalIndex = $survivorIndex + ($frontierReplacement === [] ? 0 : 1);
            $feedbackAllocation['decision']['final_block_index'] = $finalIndex + 1;
            $feedbackAllocation['decision']['final_block_key'] = $blockRows[$finalIndex]['block_key'];
            $feedbackAllocation['decision']['final_seat_numbers'] = $blockRows[$finalIndex]['seat_numbers'];
        }
        $seatOwnershipDigest = $this->digest($seatOwnership);
        $allocationManifestHash = $this->digest([
            self::PROTOCOL, $lab->id, $generationCursor, $feedback['settlement_digest'],
            $feedbackAllocation['decision'], $frontierReplacement,
            $this->manifestBlocks($blockRows), $seatOwnershipDigest,
        ]);
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

        return ['plan' => $finalPlan, 'contract' => [
            'protocol' => self::PROTOCOL, 'status' => 'allocated', 'phase' => $phase,
            'planned_population' => 20, 'dynamic' => true,
            'generation_number' => $generationCursor,
            'permanent_semantic_group_quotas' => false, 'fixed_equal_quota_forbidden' => true,
            'experiment_blocks' => $blockRows, 'block_count' => count($blockRows),
            'seat_counts' => $seatCounts, 'pair_quotas' => $pairQuotas,
            'pair_budget' => intdiv(count($allocated), 2),
            'cooperative_seats' => count($allocated),
            'seat_ownership' => $seatOwnership,
            'seat_ownership_digest' => $seatOwnershipDigest,
            'seat_ownership_complete' => count($seatOwnership) === 20,
            'allocation_manifest_required' => true,
            'allocation_manifest_hash' => $allocationManifestHash,
            'settlement_feedback' => [
                ...$feedback,
                'baseline_block_types' => $baselineTypes,
                'feedback_block_types' => $feedbackAllocation['types'],
                'decision' => $feedbackAllocation['decision'],
                'activation_replaced_block_indexes' => $activationProposal !== []
                    ? array_map(fn (int $index): int => $index + 1, $frontierReplacement) : [],
                'phase_probe_replaced_block_indexes' => $phaseProposal !== []
                    ? array_map(fn (int $index): int => $index + 1, $frontierReplacement) : [],
                'final_block_types' => $types,
            ],
            'protected_causal_proof_slots' => array_map(fn (int $index): int => $index + 1, $protectedIndexes),
            'protected_causal_proof_seats' => count($protectedIndexes),
            'uncertainty_abstain_slots' => $abstainSlots,
            'pair_integrity' => collect($blockRows)->every(fn (array $row): bool => $row['seat_count'] % 2 === 0),
            'cells' => $pairCells,
            'session_pair_counts' => collect($allocated)->countBy(fn (array $slot): string => (string) data_get($slot, 'niche.contextual_specialist_cell.session'))->map(fn (int $seats): int => intdiv($seats, 2))->all(),
            'venue_phase_pair_counts' => collect($allocated)->countBy(fn (array $slot): string => (string) data_get($slot, 'niche.contextual_specialist_cell.venue_phase'))->map(fn (int $seats): int => intdiv($seats, 2))->all(),
            'evidence_snapshot' => $evidence,
            'priority_formula' => 'probability_of_improvement + expected_information_gain + context_coverage_deficit + novelty_score + unresolved_failure_urgency + replication_need - execution_cost - duplicate_penalty - overfit_risk - correlated_failure_penalty',
            'priority_ledger' => $priorityLedger,
            'proof_frontier' => $proofFrontier,
            'phase_scope_probe' => $phaseScope,
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
                'repair_pairs' => (int) ($pairQuotas['repair_pair'] ?? 0),
                'structural_novelty_pairs' => (int) ($pairQuotas['novelty_pair'] ?? 0),
                'continuity_adversarial_guard_pairs' => (int) (($pairQuotas['adversarial_guard'] ?? 0) + ($pairQuotas['coverage_guard'] ?? 0)),
                'repair_seats' => (int) ($seatCounts['repair_pair'] ?? 0),
                'structural_novelty_seats' => (int) ($seatCounts['novelty_pair'] ?? 0),
                'continuity_adversarial_guard_seats' => (int) (($seatCounts['adversarial_guard'] ?? 0) + ($seatCounts['coverage_guard'] ?? 0)),
                'factorial_deferred_until_positive_stepping_stone' => true,
                'research_only_activation_factorial_blocks' => intdiv((int) ($seatCounts['activation_factorial'] ?? 0), 4),
            ],
            'council_router' => [
                'protocol' => ContextualSpecialistAuthorityService::PROTOCOL,
                'selection' => 'exact_context_then_risk_veto_then_highest_lower_confidence_bound',
                'uncertainty_action' => 'BASELINE_OR_WAIT', 'majority_vote_forbidden' => true,
            ],
            'idea_inbox' => ['protocol' => ResearchIdeaInboxService::PROTOCOL,
                'ready_seen' => count($readyIdeas), 'assigned' => $ideaCursor,
                'deferred_designs' => collect($readyIdeas)->reject(fn (array $idea): bool => isset($idea['claimed']))
                    ->map(fn (array $idea): array => ['idea_key' => $idea['idea_key'],
                        'required_block_type' => data_get($idea, 'executable_contract.required_block_type'),
                        'reason' => 'NO_MATCHING_READY_BLOCK_DESIGN'])->values()->all(),
                'direct_install_or_inheritance_forbidden' => true],
            'promotion_evidence' => false,
        ]];
    }

    /** New manifests fail queue admission if their source receipt or seat ownership drifts. */
    public function allocationReasons(LabGeneration $generation): array
    {
        $contract = (array) data_get($generation->trigger_context,
            'population_group_contract.contextual_allocator', []);
        if ((string) ($contract['protocol'] ?? '') !== self::PROTOCOL
            || ! (bool) ($contract['dynamic'] ?? false)) {
            return [];
        }
        if (! isset($contract['allocation_manifest_hash'])) {
            return (bool) ($contract['allocation_manifest_required'] ?? false)
                || isset($contract['settlement_feedback'])
                ? ['ALLOCATION_MANIFEST_MISSING'] : []; // Historical generations predate this manifest.
        }
        $reasons = [];
        $feedback = (array) ($contract['settlement_feedback'] ?? []);
        $current = $this->allocationFeedback($generation->laboratory, (int) $generation->generation);
        if (! hash_equals((string) ($feedback['settlement_digest'] ?? ''),
            (string) $current['settlement_digest'])) {
            $reasons[] = 'ALLOCATION_SOURCE_SETTLEMENT_DRIFT';
        }
        $owners = (array) ($contract['seat_ownership'] ?? []);
        if (count($owners) !== 20 || collect($owners)->pluck('seat')->all() !== range(1, 20)
            || ! hash_equals((string) ($contract['seat_ownership_digest'] ?? ''), $this->digest($owners))) {
            $reasons[] = 'ALLOCATION_SEAT_OWNERSHIP_INVALID';
        }
        $plan = (array) data_get($generation->trigger_context, 'generation_plan', []);
        if ($plan !== []) {
            try {
                if ($this->seatOwnership($plan, (int) $generation->generation) !== $owners) {
                    $reasons[] = 'ALLOCATION_PLAN_OWNER_DRIFT';
                }
            } catch (\Throwable) {
                $reasons[] = 'ALLOCATION_PLAN_OWNER_DRIFT';
            }
        }
        $blocks = (array) ($contract['experiment_blocks'] ?? []);
        foreach ($blocks as $row) {
            if (! is_array($row)) {
                $reasons[] = 'ALLOCATION_BLOCK_SEAT_MISMATCH';
                break;
            }
            $seats = collect($owners)->filter(fn (mixed $owner): bool => is_array($owner)
                && ($owner['kind'] ?? '') === 'cooperative_block'
                && ($owner['key'] ?? '') === ($row['block_key'] ?? ''))
                ->pluck('seat')->values()->all();
            if ($seats !== ($row['seat_numbers'] ?? null) || count($seats) !== (int) ($row['seat_count'] ?? 0)) {
                $reasons[] = 'ALLOCATION_BLOCK_SEAT_MISMATCH';
                break;
            }
        }
        $activationIndexes = array_map(fn (int $index): int => $index - 1,
            (array) ($feedback['activation_replaced_block_indexes'] ?? []));
        $phaseProbeIndexes = array_map(fn (int $index): int => $index - 1,
            (array) ($feedback['phase_probe_replaced_block_indexes'] ?? []));
        if ($activationIndexes !== [] && $phaseProbeIndexes !== []) {
            $reasons[] = 'ALLOCATION_FRONTIER_REPLACEMENT_CONFLICT';
        }
        $frontierReplacement = $activationIndexes !== [] ? $activationIndexes : $phaseProbeIndexes;
        $manifest = $this->digest([
            self::PROTOCOL, $generation->ai_laboratory_id, (int) $generation->generation,
            $feedback['settlement_digest'] ?? null, $feedback['decision'] ?? null,
            $frontierReplacement, $this->manifestBlocks($blocks), $contract['seat_ownership_digest'] ?? null,
        ]);
        if (! hash_equals((string) $contract['allocation_manifest_hash'], $manifest)) {
            $reasons[] = 'ALLOCATION_MANIFEST_HASH_INVALID';
        }

        return array_values(array_unique($reasons));
    }

    /** @return array<int,string> */
    private function blockTypes(bool $steppingStone, int $seatBudget): array
    {
        if ($seatBudget === 20) {
            return $steppingStone
                ? ['replication', 'factorial', 'transfer', 'descendant', 'repair_pair', 'novelty_pair', 'coverage_guard']
                : ['repair_pair', 'repair_pair', 'repair_pair', 'repair_pair', 'repair_pair', 'repair_pair',
                    'novelty_pair', 'novelty_pair', 'novelty_pair', 'adversarial_guard'];
        }
        if ($seatBudget === 16) {
            return $steppingStone
                ? ['replication', 'factorial', 'descendant', 'repair_pair', 'novelty_pair', 'coverage_guard']
                : ['repair_pair', 'repair_pair', 'repair_pair', 'repair_pair', 'repair_pair',
                    'novelty_pair', 'novelty_pair', 'adversarial_guard'];
        }

        return array_fill(0, intdiv($seatBudget, 2), $steppingStone ? 'replication' : 'repair_pair');
    }

    /** Snapshot only the immediate terminal predecessor; old or incomplete rows cannot steer a successor. */
    private function allocationFeedback(AiLaboratory $lab, int $generationCursor): array
    {
        $predecessor = $lab->generations()->where('generation', $generationCursor - 1)->first();
        $base = ['protocol' => self::FEEDBACK_PROTOCOL,
            'source_generation_id' => $predecessor?->id,
            'source_generation_number' => $predecessor?->generation,
            'source_generation_status' => $predecessor?->status,
            'settlement_receipts' => [], 'valid_settlement_ids' => [],
            'technical_invalid_settlement_ids' => []];
        if (! $predecessor || ! Schema::hasTable('cooperative_experiment_settlements')) {
            return [...$base, 'status' => 'no_terminal_predecessor',
                'settlement_digest' => $this->digest([self::FEEDBACK_PROTOCOL, $base])];
        }
        if (! in_array((string) $predecessor->status, ['screened', 'completed', 'technical_quarantine'], true)) {
            return [...$base, 'status' => 'predecessor_not_terminal',
                'settlement_digest' => $this->digest([self::FEEDBACK_PROTOCOL, $base])];
        }
        $receipts = [];
        foreach (CooperativeExperimentSettlement::query()->where('lab_generation_id', $predecessor->id)
            ->orderBy('id')->get() as $settlement) {
            $arms = (array) $settlement->arm_results;
            $type = (string) $settlement->block_type;
            $outcome = (string) $settlement->outcome_status;
            $identityValid = hash_equals(hash('sha256', implode('|', [
                CooperativeExperimentSettlementService::PROTOCOL, $predecessor->id, $settlement->block_key,
            ])), (string) $settlement->settlement_key);
            $complete = $identityValid && (bool) $settlement->evidence_complete
                && isset(self::BLOCK_SEATS[$type])
                && count($arms) === self::BLOCK_SEATS[$type]
                && collect($arms)->every(fn (mixed $arm): bool => is_array($arm)
                    && (string) ($arm['evidence_status'] ?? '') === 'eligible'
                    && (string) ($arm['evidence_run_id'] ?? '') !== '');
            $classification = match (true) {
                $complete && $outcome === 'underpowered_activation' && $type === 'activation_factorial' => 'underpowered_activation',
                $complete && $outcome === 'settled_negative_or_null' && $type === 'repair_pair' => 'negative_repair',
                $complete && $outcome === 'settled_negative_or_null' && $type === 'novelty_pair' => 'negative_novelty',
                $complete && $outcome === 'settled_positive_signal' => 'positive_signal_only',
                $identityValid && $outcome === 'invalid_arm_evidence' => 'technical_invalid',
                default => 'not_actionable',
            };
            $receipts[] = [
                'settlement_id' => (int) $settlement->id,
                'settlement_key' => (string) $settlement->settlement_key,
                'block_key' => (string) $settlement->block_key,
                'block_type' => $type, 'outcome_status' => $outcome,
                'evidence_complete' => (bool) $settlement->evidence_complete,
                'classification' => $classification,
                'receipt_hash' => $this->digest([
                    $settlement->id, $settlement->settlement_key, $type, $outcome,
                    (bool) $settlement->evidence_complete, $arms,
                    (array) $settlement->component_effects,
                ]),
            ];
        }
        $feedback = [...$base, 'status' => count($receipts) <= 10 ? 'sealed' : 'invalid_settlement_count',
            'settlement_receipts' => $receipts,
            'valid_settlement_ids' => collect($receipts)->filter(fn (array $row): bool =>
                in_array($row['classification'], ['underpowered_activation', 'negative_repair',
                    'negative_novelty', 'positive_signal_only'], true))->pluck('settlement_id')->all(),
            'technical_invalid_settlement_ids' => collect($receipts)->filter(fn (array $row): bool =>
                $row['classification'] === 'technical_invalid')->pluck('settlement_id')->all()];

        return [...$feedback, 'settlement_digest' => $this->digest([self::FEEDBACK_PROTOCOL, $feedback])];
    }

    /** Move at most one research-only pair; no settlement signal changes the credit/authority gate. */
    private function feedbackBlockTypes(array $types, array $feedback, bool $steppingStone): array
    {
        $unchanged = ['types' => $types, 'decision' => ['status' => 'unchanged',
            'reason' => $steppingStone ? 'confirmed_authority_constitution' : (string) $feedback['status']]];
        if ($steppingStone || $feedback['status'] !== 'sealed') {
            return $unchanged;
        }
        $rules = [
            'technical_invalid' => ['novelty_pair', 'coverage_guard', 'technical_invalid_diagnostic_guard'],
            'underpowered_activation' => ['repair_pair', 'coverage_guard', 'underpowered_activation_coverage'],
            'negative_repair' => ['repair_pair', 'novelty_pair', 'negative_repair_diversification'],
            'negative_novelty' => ['novelty_pair', 'repair_pair', 'negative_novelty_refocus'],
        ];
        foreach ($rules as $classification => [$from, $to, $reason]) {
            $source = collect((array) $feedback['settlement_receipts'])->first(fn (array $row): bool =>
                $row['classification'] === $classification);
            $index = array_search($from, $types, true);
            if (! $source || $index === false) {
                continue;
            }
            $types[$index] = $to;

            return ['types' => $types, 'decision' => [
                'status' => 'reallocated_research_only', 'reason' => $reason,
                'source_settlement_id' => $source['settlement_id'],
                'source_receipt_hash' => $source['receipt_hash'],
                'source_settlement_digest' => $feedback['settlement_digest'],
                'block_index' => $index + 1, 'from_block_type' => $from,
                'to_block_type' => $to, 'credit_allowed' => false,
            ]];
        }

        return $unchanged;
    }

    /** Every one of the twenty seats has exactly one auditable scientific owner. */
    private function seatOwnership(array $plan, int $generationCursor): array
    {
        $owners = [];
        foreach ($plan as $index => $slot) {
            $block = (array) data_get($slot, 'niche.cooperative_experiment_block', []);
            $causal = (array) data_get($slot, 'niche.causal_learning_cohort', []);
            $abstain = (array) data_get($slot, 'niche.uncertainty_abstain_contract', []);
            $claimed = (int) ($block !== []) + (int) ($causal !== []) + (int) ($abstain !== []);
            if ($claimed !== 1) {
                throw new \LogicException('Every allocation seat requires exactly one owner.');
            }
            if ($block !== []) {
                $owner = ['kind' => 'cooperative_block', 'key' => (string) ($block['block_key'] ?? ''),
                    'role' => (string) ($block['arm'] ?? '')];
            } elseif ($causal !== []) {
                $experimentKey = (string) ($causal['experiment_key'] ?? $causal['experiment_id'] ?? '');
                if ($experimentKey === '') {
                    throw new \LogicException('Protected causal seat requires an experiment identity.');
                }
                $owner = ['kind' => 'protected_causal_proof',
                    'key' => $this->digest([self::PROTOCOL, $generationCursor, $experimentKey,
                        (string) ($causal['role'] ?? ''),
                        (int) ($causal['source_pair_id'] ?? 0),
                        (int) ($causal['source_control_agent_id'] ?? 0),
                        (int) ($causal['baseline_model_version_id'] ?? 0)]),
                    'role' => (string) ($causal['role'] ?? '')];
            } else {
                $owner = ['kind' => 'uncertainty_abstain',
                    'key' => $this->digest([self::UNCERTAINTY_ABSTAIN_PROTOCOL, $generationCursor,
                        $index + 1, (string) ($abstain['status'] ?? ''), (string) ($abstain['action'] ?? '')]),
                    'role' => (string) ($abstain['action'] ?? '')];
            }
            if ($owner['key'] === '' || $owner['role'] === '') {
                throw new \LogicException('Allocation seat owner is not sealed.');
            }
            $owners[] = ['seat' => $index + 1, ...$owner];
        }
        if (count($owners) !== 20) {
            throw new \LogicException('Cooperative allocation must own all twenty seats.');
        }

        return $owners;
    }

    private function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** Stable allocation identity; mutable score presentation is not a seat owner. */
    private function manifestBlocks(array $rows): array
    {
        return array_map(fn (mixed $row): array => [
            ...(! is_array($row) ? ['invalid_row' => true] : []),
            'block_key' => (string) ($row['block_key'] ?? ''),
            'block_type' => (string) ($row['block_type'] ?? ''),
            'seat_count' => (int) ($row['seat_count'] ?? 0),
            'seat_numbers' => (array) ($row['seat_numbers'] ?? []),
            'source_context_cell_key' => (string) ($row['source_context_cell_key'] ?? ''),
            'target_context_cell_key' => (string) ($row['target_context_cell_key'] ?? ''),
        ], $rows);
    }

    /** @return array<string,mixed> */
    private function uncertaintyAbstainSpec(array $template, array $cell): array
    {
        $niche = (array) data_get($template, 'niche', []);
        unset(
            $niche['declared_gene'], $niche['declared_value'], $niche['declared_values'],
            $niche['learning_evolution'], $niche['control_pair_contract'],
            $niche['cooperative_experiment_block'], $niche['learning_method_contract']
        );
        $niche = [...$niche,
            'control_only' => true,
            'uncertainty_abstain' => true,
            'uncertainty_abstain_contract' => [
                'protocol' => self::UNCERTAINTY_ABSTAIN_PROTOCOL,
                'status' => 'sealed',
                'action' => 'WAIT',
                'replay_required' => false,
                'causal_credit_allowed' => false,
                'economic_credit_allowed' => false,
                'promotion_evidence' => false,
            ],
            'contextual_specialist_cell' => $cell,
            'outside_scope_action' => 'WAIT',
        ];

        return [...$template,
            'target' => 'uncertainty_abstain',
            'evolution_mode' => 'uncertainty_abstain',
            'research_group' => 'uncertainty_abstain',
            'group_axis' => 'cooperative_causal_block',
            'group_search_mode' => 'fail_closed',
            'group_search_role' => 'uncertainty_abstain',
            'niche' => $niche,
        ];
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
            'phase_scope_probe' => [
                ['role' => 'phase_control', 'template' => $a, 'intervention' => null],
                ['role' => 'diagnostic_candidate', 'template' => $a,
                    'intervention' => (array) data_get($a, 'niche.phase_scope_probe.diagnostic_intervention')],
            ],
            'activation_factorial' => [
                ['role' => 'control', 'template' => $a, 'intervention' => null],
                ['role' => 'a_only', 'template' => $a, 'intervention' => $this->intervention($a)],
                ['role' => 'b_only', 'template' => $a, 'intervention' => $this->intervention($b), 'pair_baseline_intervention' => true],
                ['role' => 'a_plus_b', 'template' => $a, 'intervention' => $this->intervention($a), 'baseline_arm' => 'b_only'],
            ],
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

    /** The historical model is a parameter baseline, never a genetic parent. */
    private function phaseScopeTemplate(array $proposal): array
    {
        $source = LabAgent::query()->with('modelVersion')->find((int) data_get($proposal, 'source_agent_id', 0));
        $metadata = (array) $source?->modelVersion?->metadata;
        $passport = (array) data_get($metadata, 'smart_composition.composition_passport', []);
        if (! $source?->modelVersion
            || (int) $source->model_version_id !== (int) data_get($proposal, 'source_model_version_id', 0)
            || (string) data_get($passport, 'composition_id') !== (string) data_get($proposal, 'source_composition_id')
            || (array) data_get($passport, 'components', []) !== (array) data_get($proposal, 'components', [])) {
            throw new \LogicException('Prospective phase probe source identity changed.');
        }
        if ((string) data_get($proposal, 'protocol') === ProofFrontierService::PHASE_PROBE_REFREEZE_PROTOCOL) {
            $runtimeBase = app(StrategyParameterSchemaService::class)->runtimeBaseStrategy(
                (string) $source->modelVersion->strategy,
                data_get($metadata, 'base_strategy'), (string) $source->strategy_family,
            );
            $passport = app(CompositionAuthorityKernelService::class)
                ->refreezeHistoricalHypothesis($passport, $runtimeBase);
            $passportHash = hash('sha256', json_encode($passport,
                JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            if ((string) data_get($proposal, 'prospective_composition_id')
                    !== (string) data_get($passport, 'composition_id')
                || ! hash_equals((string) data_get($proposal, 'prospective_passport_hash', ''),
                    $passportHash)) {
                throw new \LogicException('Prospective phase probe passport drifted before construction.');
            }
        }
        $smart = (array) data_get($metadata, 'smart_composition', []);
        $regimes = (array) data_get($passport, 'strategy_contract.strategy_spec.regime.allowed', []);

        return [
            'family' => (string) $source->strategy_family,
            'origin' => 'phase_scope_probe', 'target' => 'phase_scope_probe',
            'niche' => [
                'composition_lane' => 'strategy_composition',
                'composition_passport' => $passport,
                'composition_architecture' => (string) data_get($metadata, 'strategy_architecture', ''),
                'strategy_library_id' => data_get($smart, 'strategy_library_id',
                    data_get($passport, 'components.strategy_id')),
                'strategy_library_contract' => data_get($smart, 'strategy_library_contract'),
                'tactic_library_key' => data_get($smart, 'tactic_library_key',
                    data_get($passport, 'components.tactic_id')),
                'risk_library_id' => data_get($smart, 'risk_library_id',
                    data_get($passport, 'components.risk_id')),
                'risk_library_contract' => data_get($smart, 'risk_library_contract'),
                'regime' => (string) ($regimes[0] ?? 'unknown'),
                'volatility' => 'normal_volatility',
                'phase_scope_probe' => $proposal,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function armSpec(array $template, array $cell, array $arm, string $blockKey, string $blockType, int $blockIndex, array $arms, array $learning, array $priority, ?array $idea, array $activationProposal = []): array
    {
        $niche = (array) data_get($template, 'niche', []);
        if (in_array($blockType, ['activation_factorial', 'phase_scope_probe'], true)) {
            // The source passport owns execution. An unrelated planner seat
            // may carry role baselines, repair targets or extra interventions;
            // none may leak into the four-arm frozen contrast.
            $niche = array_intersect_key($niche, array_flip([
                'composition_lane', 'composition_passport', 'composition_architecture',
                'strategy_library_id', 'strategy_library_contract',
                'tactic_library_key', 'risk_library_id', 'risk_library_contract',
            ]));
        }
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
        if ($blockType === 'activation_factorial') {
            // Exact scalar/structural values belong to the pre-registered
            // arm, not to generic novelty or learned mutation selection.
            unset($niche['learning_evolution'], $niche['shadow_mutation_gene'], $niche['declared_values']);
            $niche['shadow_only'] = false;
            $niche['structural_research'] = $intervention !== [];
            $niche['activation_factorial'] = [
                'protocol' => ProofFrontierService::PROTOCOL,
                'source_agent_id' => (int) $activationProposal['source_agent_id'],
                'source_model_version_id' => (int) $activationProposal['source_model_version_id'],
                'source_parameter_hash' => (string) $activationProposal['source_parameter_hash'],
                'source_run_id' => (string) $activationProposal['source_run_id'],
                'source_response_hash' => (string) $activationProposal['source_response_hash'],
                'source_data_hash' => (string) $activationProposal['source_data_hash'],
                'source_execution_hash' => (string) $activationProposal['source_execution_hash'],
                'source_mtf_bundle_hash' => (string) $activationProposal['source_mtf_bundle_hash'],
                'hypothesis_key' => (string) $activationProposal['hypothesis_key'],
                'validation_plan' => (array) $activationProposal['validation_plan'],
                'max_discovery_trials' => 1,
                'minimum_paired_opportunities' => ProofFrontierService::MIN_PAIRED_OPPORTUNITIES,
                'minimum_signal_opportunities' => ProofFrontierService::MIN_SIGNAL_OPPORTUNITIES,
                'factor_a' => (array) $activationProposal['a'],
                'factor_b' => (array) $activationProposal['b'],
                'arm' => (string) $arm['role'],
                'holdout_status' => 'reserved_awaiting_authorized_research_epoch',
                'credit_allowed' => false,
                'promotion_evidence' => false,
            ];
        }
        if ($blockType === 'phase_scope_probe') {
            $niche['shadow_only'] = false;
            $niche['structural_research'] = $intervention !== [];
            $niche['control_only'] = $intervention === [];
            $niche['phase_scope_probe'] = [
                ...(array) $activationProposal,
                'arm' => (string) $arm['role'],
                'research_only' => true, 'credit_allowed' => false,
                'promotion_evidence' => false,
            ];
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
            'intervention' => $intervention !== [] ? $intervention : null,
            'activation_manifest_hash' => $blockType === 'activation_factorial'
                ? hash('sha256', json_encode([ProofFrontierService::PROTOCOL, $blockKey,
                    $activationProposal['source_agent_id'], $activationProposal['source_response_hash'],
                    $activationProposal['source_model_version_id'], $activationProposal['source_parameter_hash'],
                    $activationProposal['source_data_hash'], $activationProposal['hypothesis_key'],
                    data_get($activationProposal, 'validation_plan.plan_hash'),
                    $activationProposal['components'], $activationProposal['a'], $activationProposal['b'],
                    ProofFrontierService::MIN_PAIRED_OPPORTUNITIES,
                    ProofFrontierService::MIN_SIGNAL_OPPORTUNITIES,
                    data_get($cell, 'cell_hash')], JSON_UNESCAPED_SLASHES)) : null,
            'phase_probe_manifest_hash' => $blockType === 'phase_scope_probe'
                ? hash('sha256', json_encode([(string) data_get($activationProposal,
                    'protocol', ProofFrontierService::PHASE_PROBE_PROTOCOL),
                    $blockKey, $activationProposal['probe_key'], $activationProposal['source_agent_id'],
                    $activationProposal['source_model_version_id'], $activationProposal['source_parameter_hash'],
                    $activationProposal['source_run_id'], $activationProposal['source_response_hash'],
                    $activationProposal['source_data_hash'], $activationProposal['source_execution_hash'],
                    $activationProposal['source_mtf_bundle_hash'], $activationProposal['source_composition_id'],
                    ...((string) data_get($activationProposal, 'protocol')
                        === ProofFrontierService::PHASE_PROBE_REFREEZE_PROTOCOL
                        ? [$activationProposal['prospective_composition_id'],
                            $activationProposal['prospective_passport_hash']] : []),
                    $activationProposal['components'], $activationProposal['venue_phase'],
                    $activationProposal['diagnostic_intervention'], data_get($cell, 'cell_hash'),
                ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) : null,
            'factor_a' => $blockType === 'activation_factorial' ? $activationProposal['a'] : null,
            'factor_b' => $blockType === 'activation_factorial' ? $activationProposal['b'] : null,
            'changed_species' => $this->species->speciesForGene(
                $intervention !== [] ? (string) data_get($intervention, 'gene') : null
            ),
            'candidate_control_same_context_required' => $blockType !== 'transfer',
            'settlement_required' => true, 'research_nursery_only' => true, 'promotion_evidence' => false,
            'idea_design' => data_get($idea, 'executable_contract'),
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
            'origin' => in_array($blockType, ['activation_factorial', 'phase_scope_probe'], true)
                ? $blockType : data_get($template, 'origin'),
            'target' => ! in_array($blockType, ['activation_factorial', 'phase_scope_probe'], true)
                && (bool) data_get($template, 'niche.failure_directed_repair')
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
            'activation_factorial' => 'activation_factorial',
            'phase_scope_probe' => 'phase_scope_probe',
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
