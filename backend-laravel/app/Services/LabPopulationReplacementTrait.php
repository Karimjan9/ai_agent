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
trait LabPopulationReplacementTrait
{
    private function canReplaceMutationConstruction(array $spec, ?string $failureReason): bool
    {
        $unavailableDependency = $failureReason === 'REPAIR_ANCHOR_NOT_FOUND'
            || str_starts_with((string) $failureReason, 'CAUSAL_');
        if ($unavailableDependency) {
            // This includes an orphaned causal frozen-control seat. Replacing
            // it with an explicit non-credit diagnostic control preserves the
            // population boundary without pretending the broken triplet ran.
            return true;
        }
        $dependencyBlocked = in_array($failureReason, [
            'RISK_MUTATION_BEFORE_EDGE_CONFIRMATION',
            'RISK_MUTATION_LOCKED_UNTIL_EDGE_ATTRIBUTED',
            'RISK_MUTATION_REQUIRES_POSITIVE_AFTER_COST_EDGE',
            'MANAGEMENT_MUTATION_LOCKED_UNTIL_RISK_SHAPING',
            'MANAGEMENT_MUTATION_REQUIRES_MFE_CAPTURE_GAP',
        ], true);
        $shadowContractBlocked = $failureReason === 'SHADOW_MUTATION_CONTRACT_FAILED';
        if (! in_array($failureReason, [
            'NO_LEGAL_NONZERO_MUTATION',
            'HISTORICAL_GENE_EXHAUSTED',
            'CONSTRUCTOR_MUTATION_INVARIANT_FAILED',
        ], true) && ! $dependencyBlocked && ! $shadowContractBlocked && ! $unavailableDependency) {
            return false;
        }

        $niche = (array) ($spec['niche'] ?? []);
        $origin = (string) ($spec['origin'] ?? '');
        $replaceableCouncilEscape = in_array($origin, ['g98_council', 'causal_isolation'], true);
        $shadowFrozenControl = (bool) data_get($niche, 'shadow_only', false)
            && ($shadowContractBlocked || $dependencyBlocked);
        if ((bool) data_get($niche, 'control_only', false)
            || (bool) data_get($niche, 'root_experiment_portfolio', false)
            || ((bool) data_get($niche, 'architecture_experiment', false)
                && ! $replaceableCouncilEscape
                && ! $shadowFrozenControl)) {
            return false;
        }

        $anchoredRepair = $origin === 'targeted_failure_profile'
            && (int) data_get($niche, 'repair_anchor_id', 0) > 0;
        $boundedTargetedCurriculum = $origin === 'targeted_failure_profile'
            && (string) data_get($niche, 'protocol') === self::TARGETED_RESCUE_PROFILE_PROTOCOL;
        $boundedExploration = $origin === 'curiosity_probe'
            && (bool) data_get($niche, 'volume_shadow', false);
        // G98 council seats are deliberately brave, bounded research slots.
        // When their finite declared gene surface is exhausted, an explicit
        // shadow architecture escape is safer than aborting the whole
        // 20-seat constructor and silently losing the remaining experiments.
        // The escape remains diagnostic-only and still enters every unchanged
        // screening/full/forward gate.
        $councilArchitectureEscape = in_array($origin, ['g98_council', 'causal_isolation'], true);

        // A targeted curriculum can legitimately request risk/management
        // repair before its source has earned Edge authority. Preserve that
        // denial as causal evidence, but spend the seat on an admitted Edge
        // prerequisite instead of shrinking the mandatory population.
        $dependencyPrerequisite = $dependencyBlocked;

        // Shadow cohorts are fixed twenty-seat experiment sets. If an exact
        // declared mutation is not executable, or a risk/management gene is
        // correctly denied before Edge authority, retain the seat only as an
        // explicitly frozen diagnostic control. This preserves population
        // integrity without fabricating mutation or promotion evidence.
        return $anchoredRepair || $boundedTargetedCurriculum || $boundedExploration
            || $councilArchitectureEscape || $dependencyPrerequisite || $shadowFrozenControl;
    }

    /**
     * @param  array<int, array<string, mixed>>  $plan
     * @param  array<int, mixed>  $previousFailures
     * @return array<string, mixed>|null
     */
    private function repeatedFailureReplacementSpec(
        array $plan,
        int $failedIndex,
        array $spec,
        array $previousFailures,
    ): ?array {
        $slot = $failedIndex + 1;
        $failureReason = collect($previousFailures)
            ->first(fn (mixed $failure): bool => is_array($failure)
                && (int) ($failure['slot'] ?? 0) === $slot
                && (string) ($failure['reason'] ?? '') !== '');
        $failureReason = is_array($failureReason) ? (string) ($failureReason['reason'] ?? '') : '';
        if ($failureReason === '' || ! $this->canReplaceMutationConstruction($spec, $failureReason)) {
            return null;
        }

        $replacementModes = (bool) data_get($spec, 'niche.shadow_only', false)
            ? ['frozen_shadow_control', 'frozen_dependency_control']
            : ['frozen_dependency_control'];

        return collect($this->zeroDiffReplacementSpecs(
            $plan,
            $failedIndex,
            $spec,
            $failureReason,
            true,
        ))->first(fn (array $candidate): bool => (string) data_get(
            $candidate,
            'niche.replacement_contract.replacement_mode',
            '',
        ) !== '' && in_array((string) data_get(
            $candidate,
            'niche.replacement_contract.replacement_mode',
            '',
        ), $replacementModes, true));
    }

    /**
     * Compile deterministic replacement seats for an exhausted one-gene
     * mutation. The replacement keeps the same anchor, target and cohort
     * contract; only the declared gene changes. Architecture escape is the
     * final bounded option when the target gene surface is exhausted.
     *
     * @return array<int, array<string, mixed>>
     */
    private function zeroDiffReplacementSpecs(
        array $plan,
        int $failedIndex,
        array $spec,
        ?string $failureReason = null,
        bool $repeatedFailure = false,
    ): array {
        $family = (string) ($spec['family'] ?? '');
        $target = (string) ($spec['target'] ?? '');
        $niche = (array) ($spec['niche'] ?? []);
        $originalGene = (string) data_get($niche, 'declared_gene', '');
        $usedGenes = collect($plan)
            ->except($failedIndex)
            ->map(fn (array $candidate): string => (string) data_get($candidate, 'niche.declared_gene', ''))
            ->filter()
            ->values()
            ->all();
        $exhausted = array_values(array_filter(array_map(
            'strval',
            (array) data_get($niche, 'temporal_mutation_hypothesis.historically_exhausted_controls', []),
        )));
        $dependencyBlocked = in_array($failureReason, [
            'RISK_MUTATION_BEFORE_EDGE_CONFIRMATION',
            'RISK_MUTATION_LOCKED_UNTIL_EDGE_ATTRIBUTED',
            'RISK_MUTATION_REQUIRES_POSITIVE_AFTER_COST_EDGE',
            'MANAGEMENT_MUTATION_LOCKED_UNTIL_RISK_SHAPING',
            'MANAGEMENT_MUTATION_REQUIRES_MFE_CAPTURE_GAP',
        ], true);
        $unavailableDependency = $failureReason === 'REPAIR_ANCHOR_NOT_FOUND'
            || str_starts_with((string) $failureReason, 'CAUSAL_');
        if ($unavailableDependency) {
            return [$this->unavailableDependencyControlSpec($spec, (string) $failureReason)];
        }
        $shadowFrozenControl = (bool) data_get($niche, 'shadow_only', false)
            && ($failureReason === 'SHADOW_MUTATION_CONTRACT_FAILED' || $dependencyBlocked);
        if ($shadowFrozenControl) {
            return [$this->shadowFrozenControlSpec($spec, $failureReason)];
        }
        $candidates = $dependencyBlocked
            ? $this->edgePrerequisiteReplacementGenes()
            : [
                ...(array) data_get($niche, 'fallback_declared_genes', []),
                ...(array) data_get($niche, 'temporal_mutation_hypothesis.declared_genes', []),
                ...(array) data_get($niche, 'failure_specific_plan.genes', []),
                ...$this->replacementGenesForTarget($target),
            ];
        $result = [];
        foreach (array_values(array_unique(array_map('strval', $candidates))) as $gene) {
            if ($gene === '' || $gene === $originalGene || in_array($gene, $usedGenes, true) || in_array($gene, $exhausted, true)) {
                continue;
            }
            if (! array_key_exists($gene, $this->schemas->schema($family))) {
                continue;
            }

            $replacementNiche = $niche;
            $replacementNiche['declared_gene'] = $gene;
            // The failed architecture recommendation did not produce an
            // observable topology change. Reset its planner-only flags so
            // this replacement is compiled as the declared executable gene,
            // not overwritten by the same zero-diff architecture branch.
            if ((bool) data_get($replacementNiche, 'architecture_experiment', false)) {
                $replacementNiche['architecture_experiment'] = false;
                $replacementNiche['architecture_escape'] = false;
                $replacementNiche['architecture_variant'] = null;
                $replacementNiche['state_machine_variant'] = null;
                $replacementNiche['entry_topology_variant'] = 'frozen';
                $replacementNiche['regime_classifier_variant'] = 'frozen';
                $replacementNiche['architecture_interaction_variant'] = 'frozen';
            }
            // A replacement changes the gene, so a value declared for the
            // failed gene is no longer type-safe.  Normal mutation compilers
            // derive the replacement value from the schema; root portfolios
            // are intentionally non-replaceable above.
            unset($replacementNiche['declared_value']);
            $replacementDirection = data_get(
                $niche,
                'temporal_mutation_hypothesis.direction_rule.'.$gene,
                $this->replacementDirectionForGene($gene),
            );
            if (is_string($replacementDirection) && $replacementDirection !== '') {
                $replacementNiche['repair_direction'] = $replacementDirection;
            } else {
                unset($replacementNiche['repair_direction']);
            }
            $replacementNiche['replacement_contract'] = [
                'protocol' => 'zero_diff_replacement_compiler_v1',
                'replaced_gene' => $originalGene !== '' ? $originalGene : null,
                'replacement_gene' => $gene,
                'reason' => $dependencyBlocked
                    ? 'dependency_gate_deferred_risk_or_management_until_edge'
                    : 'declared_gene_exhausted_or_non_observable',
                'original_failure_reason' => $failureReason,
                'single_gene_required' => true,
                'promotion_evidence' => false,
            ];
            $replacement = $spec;
            if ($dependencyBlocked) {
                $replacement['target'] = 'profit_factor';
                $replacementNiche['deferred_failure_target'] = $target;
                $replacementNiche['failure_target'] = 'profit_factor';
                $replacementNiche['mutation_target'] = 'profit_factor';
                $replacementNiche['rescue_lane'] = 'edge_prerequisite_before_risk_management';
            }
            $replacement['niche'] = $replacementNiche;
            $result[] = $replacement;
        }

        if ($dependencyBlocked) {
            $controlNiche = [
                ...$niche,
                'declared_gene' => null,
                'control_only' => true,
                'architecture_experiment' => false,
                'architecture_escape' => false,
                'architecture_variant' => null,
                'repair_direction' => null,
                'deferred_failure_target' => $target,
                'failure_target' => 'profit_factor',
                'mutation_target' => 'profit_factor',
                'rescue_lane' => 'edge_prerequisite_frozen_control',
                'control_pair_contract' => [
                    ...(array) data_get($niche, 'control_pair_contract', []),
                    'required_for_candidate' => false,
                    'promotion_evidence' => false,
                ],
                'replacement_contract' => [
                    'protocol' => 'zero_diff_replacement_compiler_v1',
                    'replaced_gene' => $originalGene !== '' ? $originalGene : null,
                    'replacement_gene' => null,
                    'replacement_mode' => 'frozen_dependency_control',
                    'reason' => 'dependency_prerequisites_exhausted_frozen_control',
                    'original_failure_reason' => $failureReason,
                    'single_gene_required' => false,
                    'promotion_evidence' => false,
                ],
            ];
            $controlSpec = [
                ...$spec,
                // Do not feed an explicit frozen control back through the
                // targeted-repair compiler: that lane must produce a changed
                // gene and can reinterpret stored learning directives as a
                // hidden management mutation. Provenance remains complete in
                // the replacement/deferred-target contracts below.
                'origin' => 'dependency_control',
                'evolution_mode' => 'frozen_control',
                'target' => 'profit_factor',
                'allocation_lane' => 'frozen_control_replication',
                'niche' => $controlNiche,
            ];

            // A prior bounded run already attempted every safe prerequisite.
            // Repeating those expensive historical searches cannot add new
            // evidence, so the retry proceeds directly to the explicit
            // diagnostic control. A first attempt still tries each legal
            // Edge prerequisite before using the same final fallback.
            return $repeatedFailure ? [$controlSpec] : [...$result, $controlSpec];
        }

        $councilArchitectureEscape = in_array((string) ($spec['origin'] ?? ''), ['g98_council', 'causal_isolation'], true);
        $architectureEscapeAllowed = $councilArchitectureEscape;
        if ($result !== [] && ! $architectureEscapeAllowed) {
            return $result;
        }
        $geneReplacements = $result;
        if ($architectureEscapeAllowed) {
            // Council plans may have no declared gene until causal retrieval
            // runs inside createAgent(). Trying several unrelated parameter
            // replacements first repeats that expensive retrieval and can
            // push one ordinary 20-seat build beyond its worker budget. The
            // council's declared widening role is an architecture escape, so
            // try those bounded executable variants first and retain genes as
            // a final fallback.
            $result = [];
        }

        $architectures = array_values(array_filter(
            self::ARCHITECTURES[$family] ?? [],
            fn (string $architecture): bool => $architecture !== (string) data_get($niche, 'architecture_variant', ''),
        ));
        if ($architectures === []) {
            return $architectureEscapeAllowed ? $geneReplacements : [];
        }

        // Council seats often exhaust the family architecture labels while
        // still having a legal executable state-machine escape. Prefer that
        // one-gene escape for council replacements; serializing the parent
        // architecture label again makes the immutable architecture contract
        // reject the replacement as ARCHITECTURE_ESCAPE_INVALID.
        $temporalArchitectureEscape = $councilArchitectureEscape
            || in_array($target, ['temporal_stability', 'monthly_survival'], true);
        // Do not assume the first family architecture is different from the
        // parent topology. G98 council parents can already use that first
        // value; emitting only it makes every bounded architecture escape
        // fail with ARCHITECTURE_ESCAPE_INVALID. Try every declared
        // alternate in deterministic order and let createAgent's immutable
        // parent/topology contract admit exactly one valid variant.
        // Try the executable state-machine gene first, then every actual
        // family topology. A parent may already own the only state-machine
        // alternative; stopping after that zero-diff attempt used to leave
        // portfolio-router seats 18/20 unfilled.
        $architectureVariants = $temporalArchitectureEscape ? [null, ...$architectures] : $architectures;
        foreach ($architectureVariants as $architectureVariant) {
            $stateMachineCandidate = $temporalArchitectureEscape && $architectureVariant === null;
            $architectureNiche = [
                ...$niche,
                'declared_gene' => $stateMachineCandidate ? 'state_machine_variant' : null,
                'repair_direction' => 'architecture',
                'sibling_kind' => 'architecture_escape',
                'repair_anchor_sibling_kind' => 'architecture_escape',
                'architecture_experiment' => true,
                'architecture_escape' => true,
                // Keep the frozen base topology when the temporal state
                // machine is the sole declared causal change. Generic
                // escapes enumerate all alternate topology candidates.
                'architecture_variant' => $stateMachineCandidate ? null : $architectureVariant,
                'state_machine_variant' => $stateMachineCandidate ? 'neutral_transition_cooldown_reentry_v1' : null,
                'shadow_only' => $temporalArchitectureEscape || $councilArchitectureEscape,
                'replacement_contract' => [
                    'protocol' => 'zero_diff_replacement_compiler_v1',
                    'replaced_gene' => $originalGene !== '' ? $originalGene : null,
                    'replacement_gene' => $stateMachineCandidate ? 'state_machine_variant' : '__architecture',
                    'replacement_mode' => 'architecture_escape',
                    'reason' => 'all_target_genes_exhausted',
                    'promotion_evidence' => false,
                ],
            ];
            $architectureSpec = $spec;
            if ($dependencyBlocked) {
                $architectureSpec['target'] = 'profit_factor';
                $architectureNiche['deferred_failure_target'] = $target;
                $architectureNiche['failure_target'] = 'profit_factor';
                $architectureNiche['mutation_target'] = 'profit_factor';
                $architectureNiche['rescue_lane'] = 'edge_prerequisite_before_risk_management';
            }
            $architectureSpec['niche'] = $architectureNiche;
            $result[] = $architectureSpec;
        }

        return $councilArchitectureEscape
            ? [...$result, ...$geneReplacements]
            : [...$geneReplacements, ...$result];
    }

    /**
     * Preserve the mandatory population size when a pre-registered external
     * dependency has gone stale. The replacement is deliberately a frozen,
     * non-credit control: it may exercise transport/data health but cannot be
     * settled as the failed causal experiment or repair-anchor hypothesis.
     */
    private function unavailableDependencyControlSpec(array $spec, string $failureReason): array
    {
        $niche = (array) ($spec['niche'] ?? []);
        $sourceLessonId = data_get($niche, 'causal_learning_cohort.source_lesson_id');
        $sourceAnchorId = data_get($niche, 'repair_anchor_id');
        unset(
            $niche['causal_learning_cohort'],
            $niche['learning_memory_required'],
            $niche['learning_memory_blinded'],
            $niche['learning_receipt_injection'],
            $niche['repair_anchor_id'],
            $niche['repair_anchor_protocol'],
            $niche['repair_anchor_parameter_fingerprint'],
            $niche['repair_anchor_parameter_diff_keys'],
            $niche['repair_anchor_sibling_kind'],
            $niche['sibling_kind'],
            $niche['declared_value'],
        );
        if (isset($niche['composition_passport']) && is_array($niche['composition_passport'])) {
            unset(
                $niche['composition_passport']['learning_experiment'],
                $niche['composition_passport']['learning_directive'],
                $niche['composition_passport']['consumed_learning_receipt_ids'],
            );
        }
        $niche['declared_gene'] = null;
        $niche['control_only'] = true;
        $niche['architecture_experiment'] = false;
        $niche['architecture_escape'] = false;
        $niche['architecture_variant'] = null;
        $niche['repair_direction'] = null;
        $niche['composition_lane'] = 'construction_dependency_control';
        $niche['control_pair_contract'] = [
            ...(array) data_get($niche, 'control_pair_contract', []),
            'required_for_candidate' => false,
            'promotion_evidence' => false,
        ];
        $niche['replacement_contract'] = [
            'protocol' => 'constructor_unavailable_dependency_control_v1',
            'replacement_mode' => 'frozen_dependency_control',
            'reason' => 'stale_or_unexecutable_dependency_replaced_by_non_credit_control',
            'original_failure_reason' => $failureReason,
            'source_lesson_id' => $sourceLessonId,
            'source_repair_anchor_id' => $sourceAnchorId,
            'causal_experiment_removed' => $sourceLessonId !== null,
            'repair_anchor_removed' => $sourceAnchorId !== null,
            'single_gene_required' => false,
            'promotion_evidence' => false,
        ];

        return [
            ...$spec,
            'origin' => 'dependency_control',
            'evolution_mode' => 'frozen_control',
            'allocation_lane' => 'frozen_control_replication',
            'niche' => $niche,
        ];
    }

    /** @return array<string, mixed> */
    private function shadowFrozenControlSpec(array $spec, ?string $failureReason): array
    {
        $niche = (array) data_get($spec, 'niche', []);
        $shadowLane = (array) data_get($niche, 'shadow_research_lane', []);
        $originalShadowRole = (string) data_get($shadowLane, 'role', '');
        $originalGene = (string) data_get(
            $niche,
            'shadow_mutation_gene',
            data_get($niche, 'declared_gene', ''),
        );
        unset(
            $niche['declared_value'],
            $niche['shadow_mutation_gene'],
            $niche['shadow_mutation_contract'],
        );
        $niche = [
            ...$niche,
            'declared_gene' => null,
            'control_only' => true,
            'architecture_experiment' => false,
            'architecture_escape' => false,
            'architecture_variant' => null,
            'entry_topology_variant' => 'frozen',
            'state_machine_variant' => null,
            'repair_direction' => null,
            'shadow_research_lane' => [
                ...$shadowLane,
                'role' => 'frozen_control',
                'replaced_role' => $originalShadowRole !== '' ? $originalShadowRole : null,
                'constructor_fallback' => true,
                'promotion_evidence' => false,
            ],
            'replacement_contract' => [
                'protocol' => 'zero_diff_replacement_compiler_v1',
                'replaced_gene' => $originalGene !== '' ? $originalGene : null,
                'replacement_gene' => null,
                'replacement_mode' => 'frozen_shadow_control',
                'reason' => 'shadow_experiment_not_executable_frozen_control',
                'original_failure_reason' => $failureReason,
                'single_gene_required' => false,
                'mutation_credit' => false,
                'promotion_evidence' => false,
            ],
        ];

        return [
            ...$spec,
            'origin' => 'shadow_control',
            'allocation_lane' => 'frozen_control_replication',
            'niche' => $niche,
        ];
    }

    /** @return array<int, string> */
    private function edgePrerequisiteReplacementGenes(): array
    {
        return [
            'minimum_signal_confidence', 'minimum_confidence',
            'session_filter_enabled', 'avoid_high_volatility',
            'max_spread_atr_ratio', 'transition_firewall_enabled',
            'confidence_calibration_enabled', 'temporal_survival_enabled',
            'adaptive_signal_expiry_enabled', 'drift_abstention_enabled',
            'meta_label_enabled', 'range_reentry_required',
            'high_volatility_wait', 'differential_target_min_signal_confidence',
            'differential_target_session_filter_enabled', 'range_low_volatility_only',
            'trend_roc_threshold', 'trend_ema_period', 'range_lookback',
            'range_deviation', 'breakout_atr_threshold', 'breakout_lookback',
        ];
    }

    /**
     * The allocation contract assigns 0% to targeted rescue after its circuit
     * breaker trips. Convert executable risk/management requests into the
     * prerequisite signal/context work that the Edge foundry can admit, and
     * make every retained control/regime/architecture seat report its real
     * allocation lane. The historical failure target remains explicit.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reallocateBlockedTargetedPrerequisites(array $plan, bool $targetedRescueBlocked): array
    {
        if (! $targetedRescueBlocked) {
            return $plan;
        }

        $usedGenes = collect($plan)
            ->map(fn (array $slot): string => (string) data_get($slot, 'niche.declared_gene', ''))
            ->filter()
            ->values()
            ->all();
        foreach ($plan as $index => $slot) {
            if ((string) data_get($slot, 'origin') !== 'targeted_failure_profile') {
                continue;
            }
            $niche = (array) data_get($slot, 'niche', []);
            $control = (bool) data_get($niche, 'control_only', false)
                || data_get($slot, 'evolution_mode') === 'frozen_control';
            $architecture = (bool) data_get($niche, 'architecture_experiment', false);
            $target = (string) data_get($slot, 'target', '');
            $gene = (string) data_get($niche, 'declared_gene', '');
            $replacementGene = null;

            if (! $control && ! $architecture && $this->dependencyOrderedGene($gene)) {
                $family = (string) data_get($slot, 'family', '');
                $schema = $this->schemas->schema($family);
                $replacementGene = collect($this->edgePrerequisiteReplacementGenes())
                    ->first(fn (string $candidate): bool => array_key_exists($candidate, $schema)
                        && ! in_array($candidate, $usedGenes, true)
                        && ! $this->dependencyOrderedGene($candidate));
                if (is_string($replacementGene) && $replacementGene !== '') {
                    $niche['declared_gene'] = $replacementGene;
                    unset($niche['declared_value']);
                    $niche['deferred_failure_target'] = $target;
                    $niche['failure_target'] = 'profit_factor';
                    $niche['mutation_target'] = 'profit_factor';
                    $niche['rescue_lane'] = 'edge_prerequisite_before_risk_management';
                    $slot['target'] = 'profit_factor';
                    $usedGenes[] = $replacementGene;
                }
            }

            $reallocated = ! $this->dependencyOrderedGene((string) data_get($niche, 'declared_gene', ''));
            $niche['dependency_prerequisite_reallocation'] = [
                'protocol' => 'dependency_prerequisite_reallocation_v1',
                'status' => $reallocated ? 'reallocated' : 'unavailable',
                'original_target' => $target,
                'original_gene' => $gene !== '' ? $gene : null,
                'replacement_gene' => $replacementGene,
                'targeted_rescue_blocked' => true,
                'promotion_evidence' => false,
            ];
            $slot['allocation_lane'] = $reallocated
                ? ($control
                    ? 'frozen_control_replication'
                    : (($architecture || in_array((string) data_get($slot, 'target'), ['profit_factor', 'stress_cost'], true))
                        ? 'architecture_signal'
                        : 'regime_abstention'))
                : 'targeted_rescue';
            $slot['niche'] = $niche;
            $plan[$index] = $slot;
        }

        return array_values($plan);
    }

    private function dependencyOrderedGene(string $gene): bool
    {
        $normalized = strtolower($gene);

        return in_array($gene, DependencyAwareEdgeGenesisFoundryService::RISK_GENES, true)
            || in_array($gene, DependencyAwareEdgeGenesisFoundryService::MANAGEMENT_GENES, true)
            || str_contains($normalized, 'risk_')
            || str_contains($normalized, 'cooldown')
            || str_contains($normalized, 'trailing')
            || str_contains($normalized, 'take_profit');
    }

    /** @return array<int, string> */
    private function replacementGenesForTarget(string $target): array
    {
        return match ($target) {
            'temporal_stability', 'monthly_survival' => [
                // Guard-aware ordering: a wait threshold is not an
                // experiment when its firewall is disabled, an all-day
                // session toggle is not an experiment, and increasing a
                // dynamic cooldown already capped at two candles is not an
                // experiment. These genes remain legal elsewhere, but this
                // compiler must not spend a temporal rescue slot on a
                // parameter-only change.
                'transition_firewall_enabled', 'max_loss_streak_before_wait',
                'loss_cooldown_candles', 'loss_streak_wait_candles',
                'weak_regime_wait_candles', 'state_machine_variant',
                'confidence_calibration_enabled', 'dynamic_cooldown_enabled',
            ],
            'train_forward_robustness' => [
                'confidence_calibration_min_samples', 'weak_regime_min_samples',
                'meta_label_min_history', 'cooldown_shadow_min_samples',
                'confidence_calibration_enabled', 'meta_label_enabled',
            ],
            'stress_cost' => [
                'atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier',
                'time_stop_candles', 'max_spread_atr_ratio',
            ],
            'regime_coverage' => [
                'trend_up_strength_min', 'trend_down_strength_min', 'trend_up_roc_period',
                'trend_down_roc_period', 'trend_up_ema_period', 'trend_down_ema_period',
            ],
            'drawdown_risk' => [
                'time_stop_candles', 'max_loss_streak_before_wait', 'loss_cooldown_candles',
                'high_volatility_risk_multiplier', 'partial_target_atr_multiplier',
            ],
            default => ['minimum_signal_confidence', 'minimum_confidence', 'time_stop_candles'],
        };
    }

    private function replacementDirectionForGene(string $gene): ?string
    {
        return match ($gene) {
            'max_loss_streak_before_wait' => 'decrease',
            'loss_cooldown_candles', 'loss_streak_wait_candles', 'weak_regime_wait_candles',
            'transition_wait_candles', 'session_start', 'session_end' => 'increase',
            'transition_firewall_enabled', 'session_filter_enabled',
            'confidence_calibration_enabled', 'dynamic_cooldown_enabled', 'meta_label_enabled' => 'enable',
            default => null,
        };
    }
}
