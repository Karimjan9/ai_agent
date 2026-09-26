<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningSettlement;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabLearningLanePair;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\Schema;

/**
 * Turns a falsified causal confirmation into one bounded repair experiment.
 *
 * A failed guided model is never a production parent. A guided component that
 * beat both counterfactuals with explicit non-target safety may nevertheless
 * become the frozen baseline of the next research-only repair. This causal
 * ratchet allows beneficial components to compound without granting parent or
 * promotion authority. Unproven steps reset to the original frozen control.
 */
class CausalRepairFrontierService
{
    public const PROTOCOL = 'causal_repair_frontier_v1';

    public const ARCHITECTURE_PROTOCOL = 'causal_architecture_escape_v1';

    public const ARCHITECTURE_INTERACTION_PROTOCOL = 'causal_architecture_interaction_v1';

    public const CONSTRUCTION_PROTOCOL = 'causal_triplet_constructor_v4';

    public const ARCHITECTURE_GENES = [
        'state_machine_variant',
        'entry_topology_variant',
        'regime_classifier_variant',
        'architecture_interaction_variant',
    ];

    private const MAX_SCALAR_DEPTH = 3;

    private const MAX_ARCHITECTURE_DEPTH = 3;

    /** @var array{gene:string,value:string,operation:string,components:array<int,string>} */
    private const ARCHITECTURE_INTERACTION = [
        'gene' => 'architecture_interaction_variant',
        'value' => 'state_classifier_coherence_v1',
        'operation' => 'closed_state_classifier_coherence',
        'components' => ['state_machine_variant', 'regime_classifier_variant'],
    ];

    /** @var array<int, array{gene: string, value: string, operation: string}> */
    private const ARCHITECTURE_ESCAPES = [
        [
            'gene' => 'state_machine_variant',
            'value' => 'neutral_transition_cooldown_reentry_v1',
            'operation' => 'transition_state_machine_reentry',
        ],
        [
            'gene' => 'entry_topology_variant',
            'value' => 'transition_hazard_v1',
            'operation' => 'regime_conditioned_entry_topology',
        ],
        [
            'gene' => 'regime_classifier_variant',
            'value' => 'adx_hysteresis_v1',
            'operation' => 'closed_regime_classifier_hysteresis',
        ],
    ];

    /** @var array<string, array<int, string>> */
    private const TARGET_GENES = [
        'drawdown_risk' => [
            'high_volatility_risk_multiplier',
            'max_loss_streak_before_wait',
            'loss_cooldown_candles',
            'avoid_high_volatility',
            'atr_stop_multiplier',
            'time_stop_candles',
            'partial_take_profit_fraction',
        ],
        'stress_cost' => [
            'max_spread_atr_ratio', 'atr_stop_multiplier',
            'atr_target_multiplier', 'trailing_atr_multiplier',
        ],
        'profit_factor' => [
            'minimum_signal_confidence', 'atr_target_multiplier',
            'atr_stop_multiplier', 'trailing_atr_multiplier',
        ],
        'edge_quality' => [
            'minimum_signal_confidence', 'atr_target_multiplier',
            'atr_stop_multiplier', 'trailing_atr_multiplier',
        ],
        'selection_quality' => [
            'minimum_signal_confidence', 'confirmation_candles',
            'max_spread_atr_ratio', 'transition_wait_candles',
        ],
        'execution_quality' => [
            'max_spread_atr_ratio', 'atr_stop_multiplier',
            'time_stop_candles', 'entry_topology_variant',
        ],
        'management_quality' => [
            'partial_take_profit_fraction', 'time_stop_candles',
            'trailing_atr_multiplier', 'atr_target_multiplier',
        ],
        'trade_frequency' => [
            'minimum_signal_confidence', 'confirmation_candles',
            'lookback', 'loss_cooldown_candles',
        ],
        'temporal_stability' => [
            'transition_firewall_enabled', 'transition_wait_candles',
            'loss_cooldown_candles', 'weak_regime_wait_candles',
        ],
        'monthly_survival' => [
            'transition_firewall_enabled', 'session_filter_enabled',
            'loss_cooldown_candles', 'weak_regime_wait_candles',
        ],
        'regime_coverage' => [
            'trend_down_strength_min', 'trend_up_strength_min',
            'minimum_signal_confidence', 'lookback',
        ],
    ];

    /** @return array<string, mixed>|null */
    public function eligible(string $symbol, string $timeframe, ?int $sourceExperimentId = null): ?array
    {
        if (! Schema::hasTable('agent_learning_causal_experiments')
            || ! Schema::hasTable('agent_learning_settlements')) {
            return null;
        }

        $query = AgentLearningCausalExperiment::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('status', 'provisional');
        if ($sourceExperimentId !== null) {
            $query->whereKey($sourceExperimentId);
        }

        foreach ($query->latest('id')->limit(50)->get() as $experiment) {
            $candidate = $this->compile($experiment);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    public function seedPlan(array $frontier): array
    {
        $kind = (string) data_get($frontier, 'experiment_kind');
        $architecture = in_array($kind, [
            'causal_architecture_escape', 'causal_architecture_interaction',
        ], true);

        return collect(['repair_guided', 'blinded', 'frozen_control'])
            ->map(fn (string $role, int $index): array => [
                'family' => (string) data_get($frontier, 'strategy_family'),
                'origin' => $kind === 'causal_architecture_interaction'
                    ? 'causal_arch_bundle'
                    : ($architecture ? 'causal_arch_escape' : 'causal_repair'),
                'target' => (string) data_get($frontier, 'target'),
                'evolution_mode' => $role === 'frozen_control'
                    ? 'frozen_control'
                    : 'causal_repair_counterfactual',
                'niche' => [
                    'slot' => $index + 1,
                    'data_lane' => 'price',
                    'causal_repair_source_experiment_id' => (int) data_get($frontier, 'source_experiment_id'),
                    'promotion_evidence' => false,
                ],
            ])->all();
    }

    /** @return array{plan: array<int, array<string, mixed>>, contract: array<string, mixed>} */
    public function materialize(array $plan, string $symbol, string $timeframe, int $generationId): array
    {
        $sourceId = collect($plan)->map(
            fn (array $slot): int => (int) data_get($slot, 'niche.causal_repair_source_experiment_id', 0),
        )->filter()->unique()->first();
        $frontier = $sourceId > 0 ? $this->eligible($symbol, $timeframe, $sourceId) : null;
        $experimentKind = (string) data_get($frontier, 'experiment_kind', 'causal_repair');
        $interaction = $experimentKind === 'causal_architecture_interaction';
        $architecture = $experimentKind === 'causal_architecture_escape' || $interaction;
        $protocol = $interaction
            ? self::ARCHITECTURE_INTERACTION_PROTOCOL
            : ($architecture ? self::ARCHITECTURE_PROTOCOL : self::PROTOCOL);
        $base = [
            'protocol' => $protocol,
            'status' => $frontier ? 'pending_materialization' : 'source_not_eligible',
            'generation_id' => $generationId,
            'source_experiment_id' => $sourceId ?: null,
            'required_independent_windows' => (int) config('services.learning_lane.causal_fold_count', 9),
            'minimum_powered_windows' => (int) config('services.learning_lane.causal_minimum_powered_windows', 6),
            'minimum_positive_windows' => (int) config('services.learning_lane.causal_minimum_positive_windows', 4),
            'promotion_evidence' => false,
        ];
        if (! $frontier) {
            return ['plan' => array_values($plan), 'contract' => $base];
        }

        $family = (string) $frontier['strategy_family'];
        $indexes = collect($plan)->keys()->filter(fn (int $index): bool => (string) data_get($plan[$index], 'family') === $family
        )->take(3)->values();
        if ($indexes->count() !== 3) {
            return ['plan' => array_values($plan), 'contract' => [...$base, 'status' => 'triplet_slots_missing']];
        }

        /** @var LabAgent|null $sourceControl */
        $sourceControl = LabAgent::query()->with('modelVersion')->find((int) $frontier['source_control_agent_id']);
        $sourceModel = $sourceControl?->modelVersion;
        if (! $sourceModel
            || (int) $sourceModel->id !== (int) $frontier['baseline_model_version_id']
            || (string) $sourceControl->symbol !== strtoupper($symbol)
            || (string) $sourceControl->timeframe !== strtoupper($timeframe)
            || (string) $sourceControl->strategy_family !== $family) {
            return ['plan' => array_values($plan), 'contract' => [...$base, 'status' => 'frozen_baseline_missing']];
        }
        $sourceArchitecture = (string) data_get(
            $sourceModel->metadata,
            'strategy_architecture',
            data_get($sourceModel->metadata, 'semantic_group.architecture', ''),
        );
        $sourceTactic = (string) data_get($sourceModel->metadata, 'tactic_contract.architecture', $sourceArchitecture);
        $passport = app(StrategyTacticRiskCompositionPlannerService::class)->freezeConfirmationBaseline(
            $family,
            $timeframe,
            (string) data_get($frontier, 'data_hash', ''),
            (string) data_get($frontier, 'execution_hash', ''),
            $sourceArchitecture,
            $sourceTactic,
            (array) data_get($sourceModel->metadata, 'smart_composition.composition_passport', []),
        );
        if ($passport === []) {
            return ['plan' => array_values($plan), 'contract' => [
                ...$base,
                'status' => 'source_composition_identity_unavailable',
                'source_family' => $family,
                'source_architecture' => $sourceArchitecture,
            ]];
        }
        $semantic = (array) data_get($sourceModel->metadata, 'semantic_group', []);
        $sourceContext = (array) data_get($frontier, 'source_context_scope', []);
        $sourceContextHash = data_get($frontier, 'source_context_hash');
        $frontierProtocol = $protocol;
        $experimentKey = hash('sha512', json_encode([
            $frontierProtocol,
            $experimentKind,
            $generationId,
            (int) $frontier['source_experiment_id'],
            $family,
            $frontier['gene'],
            $frontier['value'],
            (int) $frontier['repair_depth'],
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $blindedMutation = app(CausalBlindedMutationSelectorService::class)->select(
            $family,
            (string) $frontier['target'],
            (array) $sourceModel->parameters,
            'repair:'.$frontier['source_experiment_id'],
            (string) $frontier['gene'],
            $frontier['value'],
            (array) data_get($frontier, 'activation_manifest', []),
            $architecture ? self::ARCHITECTURE_GENES : null,
        );
        if ($blindedMutation === null) {
            return ['plan' => array_values($plan), 'contract' => [
                ...$base,
                'status' => 'blinded_single_gene_unavailable',
                'construction_protocol' => self::CONSTRUCTION_PROTOCOL,
            ]];
        }

        foreach (['repair_guided', 'blinded', 'frozen_control'] as $offset => $role) {
            $index = (int) $indexes[$offset];
            $slot = (array) $plan[$index];
            $niche = (array) data_get($slot, 'niche', []);
            $contract = [
                'protocol' => CausalLearningCohortPlannerService::PROTOCOL,
                'experiment_kind' => $experimentKind,
                'experiment_key' => $experimentKey,
                'role' => $role,
                'source_lesson_id' => null,
                'source_causal_experiment_id' => (int) $frontier['source_experiment_id'],
                'gene' => (string) $frontier['gene'],
                'value' => $frontier['value'],
                'source_pair_id' => (int) $frontier['source_pair_id'],
                'root_source_pair_id' => (int) data_get($frontier, 'root_source_pair_id', $frontier['source_pair_id']),
                'source_candidate_agent_id' => (int) $frontier['source_candidate_agent_id'],
                'source_control_agent_id' => (int) $frontier['source_control_agent_id'],
                'baseline_model_version_id' => (int) $frontier['baseline_model_version_id'],
                'baseline_policy' => (string) data_get($frontier, 'baseline_policy', 'original_frozen_control'),
                'baseline_old_value' => $frontier['old_value'],
                'repair_depth' => (int) $frontier['repair_depth'],
                'attempted_genes' => (array) $frontier['attempted_genes'],
                'architecture_depth' => (int) data_get($frontier, 'architecture_depth', 0),
                'attempted_architecture_genes' => (array) data_get($frontier, 'attempted_architecture_genes', []),
                'interaction_depth' => (int) data_get($frontier, 'interaction_depth', 0),
                'interaction_components' => (array) data_get($frontier, 'interaction_components', []),
                'structural_operation' => data_get($frontier, 'structural_operation'),
                'activation_screen' => (array) data_get($frontier, 'activation_screen', []),
                'research_ratchet' => (array) data_get($frontier, 'research_ratchet', []),
                'source_context_scope' => $sourceContext,
                'source_context_hash' => $sourceContextHash,
                'construction_protocol' => self::CONSTRUCTION_PROTOCOL,
                'blinded_selector' => $blindedMutation,
                'same_parent_required' => true,
                'same_dataset_required' => true,
                'same_execution_contract_required' => true,
                'promotion_evidence' => false,
            ];
            $niche = [
                ...$niche,
                ...($semantic !== [] ? [
                    'role' => data_get($semantic, 'role'),
                    'specialist_role' => data_get($semantic, 'role'),
                    'regime' => data_get($semantic, 'regime'),
                    'volatility' => data_get($semantic, 'volatility'),
                    'direction' => data_get($semantic, 'direction'),
                ] : []),
                'causal_learning_cohort' => $contract,
                'learning_memory_required' => false,
                'learning_memory_blinded' => $role === 'blinded',
                'control_only' => $role === 'frozen_control',
                'composition_lane' => 'causal_repair_frontier',
                'composition_passport' => $passport,
            ];
            if ($role === 'repair_guided') {
                $niche['declared_gene'] = $frontier['gene'];
                $niche['declared_value'] = $frontier['value'];
                $niche['causal_repair_exact_value'] = $frontier['value'];
                if ($architecture) {
                    // Structural genes are executable runtime parameters as
                    // well as causal declarations. LabPopulationService uses
                    // the typed niche value to enable its architecture safety
                    // path before it applies the exact cohort mutation.
                    $niche[(string) $frontier['gene']] = $frontier['value'];
                    $niche['shadow_mutation_gene'] = (string) $frontier['gene'];
                    $niche['structural_research'] = true;
                    $niche['architecture_experiment'] = true;
                    $niche['architecture_escape'] = true;
                    $niche['architecture_interaction'] = $interaction;
                    $niche['structural_hypothesis_protocol'] = $frontierProtocol;
                    $niche['structural_operation'] = $frontier['structural_operation'];
                    $niche['shadow_only'] = true;
                }
                $slot['evolution_mode'] = 'causal_repair_counterfactual';
            } elseif ($role === 'blinded') {
                foreach (['declared_gene', 'declared_value', 'shadow_mutation_gene'] as $key) {
                    unset($niche[$key]);
                }
                $niche['selector_policy'] = 'cold_start_memory_blinded_selector';
                $slot['evolution_mode'] = 'causal_learning_selector_counterfactual';
            } else {
                foreach (['declared_gene', 'declared_value', 'shadow_mutation_gene'] as $key) {
                    unset($niche[$key]);
                }
                $slot['evolution_mode'] = 'frozen_control';
            }
            $slot['family'] = $family;
            $slot['target'] = (string) $frontier['target'];
            $slot['niche'] = $niche;
            $plan[$index] = $slot;
        }

        return ['plan' => array_values($plan), 'contract' => [
            ...$base,
            'status' => 'materialized',
            'experiment_kind' => $experimentKind,
            'experiment_key' => $experimentKey,
            'strategy_family' => $family,
            'target' => (string) $frontier['target'],
            'gene' => (string) $frontier['gene'],
            'value' => $frontier['value'],
            'old_value' => $frontier['old_value'],
            'repair_depth' => (int) $frontier['repair_depth'],
            'attempted_genes' => (array) $frontier['attempted_genes'],
            'architecture_depth' => (int) data_get($frontier, 'architecture_depth', 0),
            'attempted_architecture_genes' => (array) data_get($frontier, 'attempted_architecture_genes', []),
            'interaction_depth' => (int) data_get($frontier, 'interaction_depth', 0),
            'interaction_components' => (array) data_get($frontier, 'interaction_components', []),
            'structural_operation' => data_get($frontier, 'structural_operation'),
            'activation_screen' => (array) data_get($frontier, 'activation_screen', []),
            'research_ratchet' => (array) data_get($frontier, 'research_ratchet', []),
            'source_context_scope' => $sourceContext,
            'source_context_hash' => $sourceContextHash,
            'construction_protocol' => self::CONSTRUCTION_PROTOCOL,
            'blinded_selector' => $blindedMutation,
            'baseline_model_version_id' => (int) $frontier['baseline_model_version_id'],
            'baseline_policy' => (string) data_get($frontier, 'baseline_policy', 'original_frozen_control'),
            'root_source_pair_id' => (int) data_get($frontier, 'root_source_pair_id', $frontier['source_pair_id']),
            'slots' => $indexes->map(fn (int $index): int => $index + 1)->all(),
            'rule' => $interaction
                ? 'Single structural axes are exhausted; one fixed macro-gene measures their pre-registered interaction against a blinded selector and the exact original frozen control over nine disjoint folds.'
                : ($architecture
                ? 'Scalar repair is exhausted; one executable structural gene is tested against a blinded selector and the exact original frozen control over nine disjoint folds.'
                : 'The failed gene is not inherited; one target-owning repair gene is tested against a blinded selector and the exact frozen control over nine disjoint folds.'),
        ]];
    }

    public function linkDispatched(AgentLearningCausalExperiment $child): void
    {
        $sourceId = (int) data_get($child->evidence, 'source_causal_experiment_id', 0);
        if ($sourceId <= 0 || (string) $child->status !== 'ready_for_replay') {
            return;
        }
        $source = AgentLearningCausalExperiment::query()->find($sourceId);
        if (! $source) {
            return;
        }
        $evidence = (array) $source->evidence;
        $frontier = (array) data_get($evidence, 'repair_frontier', []);
        if (in_array((string) data_get($frontier, 'status'), [
            'bounded_repair_required', 'architecture_escape_required', 'architecture_escape_retry_required',
            'architecture_portfolio_required',
        ], true)
            || (int) data_get($frontier, 'child_experiment_id', 0) === (int) $child->id) {
            $frontier['dispatched_from_status'] ??= (string) data_get($frontier, 'status');
            $frontier['status'] = 'dispatched';
            $frontier['child_experiment_id'] = (int) $child->id;
            $frontier['dispatched_at'] ??= now()->utc()->toIso8601String();
            $frontier['promotion_evidence'] = false;
            $evidence['repair_frontier'] = $frontier;
            $source->update(['evidence' => $evidence]);
        }
    }

    /**
     * Re-open a frontier whose child never reached replay. This is a
     * transport/construction retry, not a negative architecture observation;
     * therefore it must not consume repair depth or skip to another gene.
     *
     * @param  array<int, string>  $reasonCodes
     */
    public function releaseInvalidChild(AgentLearningCausalExperiment $child, array $reasonCodes = []): bool
    {
        $sourceId = (int) data_get($child->evidence, 'source_causal_experiment_id', 0);
        if ($sourceId <= 0) {
            return false;
        }
        $source = AgentLearningCausalExperiment::query()->find($sourceId);
        if (! $source) {
            return false;
        }
        $evidence = (array) $source->evidence;
        $frontier = (array) data_get($evidence, 'repair_frontier', []);
        if ((int) data_get($frontier, 'child_experiment_id', 0) !== (int) $child->id
            || (string) data_get($frontier, 'status') !== 'dispatched') {
            return false;
        }

        $previous = (string) data_get($frontier, 'dispatched_from_status', '');
        if (! in_array($previous, [
            'bounded_repair_required', 'architecture_escape_required', 'architecture_escape_retry_required',
            'architecture_portfolio_required',
        ], true)) {
            $sourceKind = (string) data_get($source->evidence, 'experiment_kind');
            $childKind = (string) data_get($child->evidence, 'experiment_kind');
            $previous = $childKind === 'causal_architecture_interaction'
                ? 'architecture_portfolio_required'
                : ($childKind === 'causal_architecture_escape'
                ? ($sourceKind === 'causal_architecture_escape'
                    ? 'architecture_escape_retry_required'
                    : 'architecture_escape_required')
                : 'bounded_repair_required');
        }
        $attempts = (array) data_get($frontier, 'invalid_dispatches', []);
        $attempts[] = [
            'child_experiment_id' => (int) $child->id,
            'child_generation_id' => (int) $child->lab_generation_id,
            'reason_codes' => array_values(array_unique(array_map('strval', $reasonCodes))),
            'released_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ];
        $frontier['status'] = $previous;
        $frontier['child_experiment_id'] = null;
        $frontier['invalid_dispatches'] = $attempts;
        $frontier['promotion_evidence'] = false;
        $evidence['repair_frontier'] = $frontier;
        $source->update(['evidence' => $evidence]);

        return true;
    }

    /** @return array<string, mixed>|null */
    private function compile(AgentLearningCausalExperiment $experiment): ?array
    {
        $frontier = (array) data_get($experiment->evidence, 'repair_frontier', []);
        $frontierStatus = (string) data_get($frontier, 'status');
        $architecture = in_array($frontierStatus, [
            'architecture_escape_required', 'architecture_escape_retry_required',
        ], true);
        $interaction = $frontierStatus === 'architecture_portfolio_required';
        if ((! $architecture && ! $interaction && $frontierStatus !== 'bounded_repair_required')
            || data_get($frontier, 'inherit_gene') !== false
            || (! $architecture && ! $interaction && (string) data_get($frontier, 'next_experiment') !== 'one_gene_paired_replay')
            || ($interaction && (string) data_get($frontier, 'next_experiment') !== 'bounded_architecture_interaction_paired_replay')
            || filled(data_get($frontier, 'child_experiment_id'))) {
            return null;
        }
        $invalidConstructionAttempts = AgentLearningCausalExperiment::query()
            ->where('symbol', $experiment->symbol)
            ->where('timeframe', $experiment->timeframe)
            ->where('strategy_family', $experiment->strategy_family)
            ->where('status', 'invalid_counterfactual_contract')
            ->latest('id')
            ->limit(50)
            ->get()
            ->filter(fn (AgentLearningCausalExperiment $child): bool => (int) data_get($child->evidence, 'source_causal_experiment_id', 0) === (int) $experiment->id
                && data_get($child->evidence, 'construction_protocol') === self::CONSTRUCTION_PROTOCOL
            );
        $repeatedFailure = $invalidConstructionAttempts
            ->groupBy(function (AgentLearningCausalExperiment $child): string {
                $reasons = array_values(array_unique(array_map(
                    'strval',
                    (array) data_get($child->evidence, 'construction_validation.reason_codes', []),
                )));
                sort($reasons);

                return hash('sha256', json_encode($reasons, JSON_UNESCAPED_SLASHES));
            })
            ->first(fn ($attempts): bool => $attempts->count() >= 2);
        if ($repeatedFailure) {
            $reasonCodes = array_values(array_unique(array_map(
                'strval',
                (array) data_get($repeatedFailure->first()?->evidence, 'construction_validation.reason_codes', []),
            )));
            $frontier['status'] = 'construction_quarantined';
            $frontier['construction_quarantine'] = [
                'protocol' => self::CONSTRUCTION_PROTOCOL,
                'identical_failure_count' => $repeatedFailure->count(),
                'reason_codes' => $reasonCodes,
                'child_experiment_ids' => $repeatedFailure->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                'rule' => 'Two identical failures under one constructor version stop automatic cohort creation until code or contract version changes.',
                'quarantined_at' => now()->utc()->toIso8601String(),
                'promotion_evidence' => false,
            ];
            $frontier['promotion_evidence'] = false;
            $evidence = (array) $experiment->evidence;
            $evidence['repair_frontier'] = $frontier;
            $experiment->update(['evidence' => $evidence]);

            return null;
        }
        $depth = max(1, (int) data_get($experiment->evidence, 'repair_lineage.depth', 0) + 1);
        if (! $architecture && ! $interaction && $depth > self::MAX_SCALAR_DEPTH) {
            return null;
        }

        $guidedPair = LabLearningLanePair::query()
            ->with(['candidateAgent.modelVersion', 'controlAgent.modelVersion', 'controlResponseMap'])
            ->where('candidate_agent_id', $experiment->guided_agent_id)
            ->where('control_agent_id', $experiment->control_agent_id)
            ->latest('id')->first();
        $settlement = $guidedPair ? AgentLearningSettlement::query()
            ->where('source_type', LabLearningLanePair::class)
            ->where('source_id', $guidedPair->id)
            ->latest('id')->first() : null;
        if (! $guidedPair || ! $settlement || ! (bool) $settlement->hard_failure
            || (string) $settlement->evidence_state !== 'negative') {
            return null;
        }

        $sourcePairId = (int) data_get(
            $experiment->evidence,
            'root_source_pair_id',
            data_get($experiment->evidence, 'source_pair_id', 0),
        );
        $rootSourcePair = $sourcePairId > 0 ? LabLearningLanePair::query()
            ->with(['controlAgent.modelVersion'])->find($sourcePairId) : null;
        $sourceControl = $rootSourcePair?->controlAgent;
        $baseline = $sourceControl?->modelVersion;
        if (! $rootSourcePair || ! $rootSourcePair->isVerifiedControlPair() || ! $sourceControl || ! $baseline) {
            return null;
        }

        $ratchet = (array) data_get($frontier, 'research_ratchet', []);
        $ratchetAllowed = data_get($ratchet, 'protocol') === 'causal_research_ratchet_v1'
            && data_get($ratchet, 'allowed') === true;
        $baselineSourcePair = $rootSourcePair;
        if ($ratchetAllowed) {
            $ratchetAgentId = (int) data_get($ratchet, 'research_baseline_agent_id', 0);
            $ratchetModelId = (int) data_get($ratchet, 'research_baseline_model_version_id', 0);
            $candidate = $guidedPair?->candidateAgent;
            if (! $guidedPair || ! $guidedPair->isVerifiedControlPair()
                || ! $candidate || ! $candidate->modelVersion
                || $ratchetAgentId !== (int) $candidate->id
                || $ratchetModelId !== (int) $candidate->model_version_id
                || data_get($ratchet, 'production_parent_allowed') !== false
                || data_get($ratchet, 'promotion_evidence') !== false) {
                // A stale or forged ratchet marker must never silently select
                // a different baseline. Leave the frontier pending for audit.
                return null;
            }
            $baselineSourcePair = $guidedPair;
            $sourceControl = $candidate;
            $baseline = $candidate->modelVersion;
        }

        $target = $this->normalizeTarget((string) data_get($frontier, 'target', $settlement->failure_class));
        $attempted = array_values(array_unique(array_filter(array_map('strval', [
            ...((array) data_get($experiment->evidence, 'repair_lineage.attempted_genes', [])),
            (string) $experiment->gene_key,
        ]))));
        $architectureAttempted = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) data_get($experiment->evidence, 'architecture_lineage.attempted_genes', []),
        ))));
        $architectureDepth = $architecture
            ? max(1, (int) data_get($experiment->evidence, 'architecture_lineage.depth', 0) + 1)
            : ($interaction ? (int) data_get($experiment->evidence, 'architecture_lineage.depth', self::MAX_ARCHITECTURE_DEPTH) : 0);
        if ($architecture && $architectureDepth > self::MAX_ARCHITECTURE_DEPTH) {
            return null;
        }
        $mutation = $interaction
            ? $this->architectureInteractionMutation($experiment->strategy_family, $baseline)
            : ($architecture
                ? $this->architectureMutation($experiment->strategy_family, $baseline, $architectureAttempted)
                : $this->mutation($experiment->strategy_family, $target, $baseline, $attempted, $guidedPair));
        if ($mutation === null) {
            return null;
        }

        // A failed edge cannot be repaired by polishing its risk or exit
        // surface.  The global Edge Genesis dependency gate is consulted at
        // planning time, before a generation or child model is persisted.
        // Previously this check existed only in LabPopulationService, so the
        // planner could reserve a three-seat causal cohort whose guided seat
        // was guaranteed to be rejected during construction.  Persist the
        // redirect on the source frontier so subsequent scheduler ticks open
        // an architecture/entry experiment instead of retrying the same
        // impossible risk mutation.
        $edgeAdmission = app(DependencyAwareEdgeGenesisFoundryService::class)
            ->mutationAdmission($baseline, (string) $mutation['gene']);
        if (! (bool) data_get($edgeAdmission, 'allowed', false)) {
            $this->redirectToEdgeGenesis($experiment, $frontier, $baseline, $mutation, $edgeAdmission);

            return null;
        }

        // Every repair constructor receives the same frozen-control
        // feasibility evidence. Scalar repairs already attach it while
        // choosing a gene; structural/interaction repairs previously dropped
        // it, leaving the memory-blinded arm to choose from an avoidably
        // unbounded hypothesis space. This manifest is scheduling evidence
        // only and never grants performance or promotion credit.
        $activationManifest = array_key_exists('activation_manifest', $mutation)
            ? (array) $mutation['activation_manifest']
            : $this->activationManifest($guidedPair);
        $activationScreen = (array) ($mutation['activation_screen'] ?? []);
        if ($activationScreen === []) {
            $selectedActivation = $this->activationStatus(
                (string) $mutation['gene'],
                $mutation['old_value'],
                $mutation['value'],
                $activationManifest,
                (array) $baseline->parameters,
            );
            $activationScreen = [
                'protocol' => 'repair_gene_activation_screen_v1',
                'status' => (string) $selectedActivation['status'],
                'selected_gene' => (string) $mutation['gene'],
                'selected_gene_evidence' => $selectedActivation,
                'skipped_genes' => [],
                'source_control_run_id' => $guidedPair->control_evidence_run_id,
                'performance_credit' => false,
                'promotion_evidence' => false,
            ];
        }

        $experimentKind = $interaction
            ? 'causal_architecture_interaction'
            : ($architecture ? 'causal_architecture_escape' : 'causal_repair');
        $protocol = $interaction
            ? self::ARCHITECTURE_INTERACTION_PROTOCOL
            : ($architecture ? self::ARCHITECTURE_PROTOCOL : self::PROTOCOL);

        $sourceContext = app(ContextContractV2Service::class)->canonicalDeclaredAxes((array) data_get(
            $experiment->evidence,
            'source_context_scope',
            data_get($baselineSourcePair->metadata, 'context_scope', data_get($baselineSourcePair->failure_signature, 'state', [])),
        ));

        return [
            'protocol' => $protocol,
            'experiment_kind' => $experimentKind,
            'source_experiment_id' => (int) $experiment->id,
            'source_pair_id' => (int) $baselineSourcePair->id,
            'root_source_pair_id' => (int) $rootSourcePair->id,
            'source_candidate_agent_id' => (int) $baselineSourcePair->candidate_agent_id,
            'source_control_agent_id' => (int) $sourceControl->id,
            'baseline_model_version_id' => (int) $baseline->id,
            'baseline_policy' => $ratchetAllowed
                ? 'causal_positive_research_ratchet'
                : 'original_frozen_control',
            'research_ratchet' => $ratchetAllowed ? $ratchet : [
                'protocol' => 'causal_research_ratchet_v1',
                'status' => 'not_applied',
                'allowed' => false,
                'production_parent_allowed' => false,
                'promotion_evidence' => false,
            ],
            'source_context_scope' => $sourceContext,
            'source_context_hash' => $sourceContext === [] ? null : hash('sha256', json_encode(
                $sourceContext,
                JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            )),
            'strategy_family' => (string) $experiment->strategy_family,
            'target' => $target,
            'gene' => $mutation['gene'],
            'old_value' => $mutation['old_value'],
            'value' => $mutation['value'],
            'repair_depth' => ($architecture || $interaction)
                ? (int) data_get($experiment->evidence, 'repair_lineage.depth', self::MAX_SCALAR_DEPTH)
                : $depth,
            'attempted_genes' => ($architecture || $interaction) ? $attempted : [...$attempted, $mutation['gene']],
            'architecture_depth' => $architectureDepth,
            'attempted_architecture_genes' => ($architecture || $interaction)
                ? [...$architectureAttempted, $mutation['gene']]
                : [],
            'interaction_depth' => $interaction ? 1 : 0,
            'interaction_components' => (array) ($mutation['components'] ?? []),
            'structural_operation' => $mutation['operation'] ?? null,
            'activation_screen' => $activationScreen,
            'activation_manifest' => $activationManifest,
            'data_hash' => (string) $baselineSourcePair->control_data_hash,
            'execution_hash' => (string) $baselineSourcePair->control_execution_hash,
            'promotion_evidence' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $frontier
     * @param  array<string,mixed>  $mutation
     * @param  array<string,mixed>  $admission
     */
    private function redirectToEdgeGenesis(
        AgentLearningCausalExperiment $experiment,
        array $frontier,
        ModelVersion $baseline,
        array $mutation,
        array $admission,
    ): void {
        $previousStatus = (string) data_get($frontier, 'status', 'bounded_repair_required');
        $frontier['status'] = 'edge_genesis_required';
        $frontier['previous_status'] = $previousStatus;
        $frontier['next_experiment'] = 'dependency_aware_edge_genesis';
        $frontier['inherit_gene'] = false;
        $frontier['edge_genesis_redirect'] = [
            'protocol' => DependencyAwareEdgeGenesisFoundryService::PROTOCOL,
            'reason_code' => (string) data_get($admission, 'reason', 'EDGE_GENESIS_MUTATION_ADMISSION_DENIED'),
            'blocked_gene' => (string) data_get($mutation, 'gene'),
            'blocked_value' => data_get($mutation, 'value'),
            'target' => (string) data_get($frontier, 'target', $experiment->target),
            'baseline_model_version_id' => (int) $baseline->id,
            'baseline_phase' => (string) data_get($baseline->metadata, 'edge_genesis.phase', 'EDGE_DISCOVERY'),
            'rule' => 'Fundamental entry/context edge must be established and attributed before risk or management repair may consume replay budget.',
            'redirected_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ];
        $frontier['promotion_evidence'] = false;
        $evidence = (array) $experiment->evidence;
        $evidence['repair_frontier'] = $frontier;
        $experiment->update(['evidence' => $evidence]);
    }

    /** @return array{gene: string, old_value: mixed, value: mixed, operation: string}|null */
    private function architectureMutation(string $family, ModelVersion $baseline, array $attempted): ?array
    {
        $schema = app(StrategyParameterSchemaService::class)->schema($family);
        $parameters = (array) $baseline->parameters;
        foreach (self::ARCHITECTURE_ESCAPES as $escape) {
            $gene = $escape['gene'];
            if (in_array($gene, $attempted, true)
                || ! array_key_exists($gene, $schema)
                || ! array_key_exists($gene, $parameters)
                || json_encode($parameters[$gene], JSON_PRESERVE_ZERO_FRACTION) === json_encode($escape['value'], JSON_PRESERVE_ZERO_FRACTION)) {
                continue;
            }

            return [
                'gene' => $gene,
                'old_value' => $parameters[$gene],
                'value' => $escape['value'],
                'operation' => $escape['operation'],
            ];
        }

        return null;
    }

    /** @return array{gene:string,old_value:mixed,value:mixed,operation:string,components:array<int,string>}|null */
    private function architectureInteractionMutation(string $family, ModelVersion $baseline): ?array
    {
        $schema = app(StrategyParameterSchemaService::class);
        $gene = self::ARCHITECTURE_INTERACTION['gene'];
        $definition = (array) data_get($schema->schema($family), $gene, []);
        $old = data_get(
            (array) $baseline->parameters,
            $gene,
            data_get($schema->defaults($family), $gene),
        );
        if ($definition === [] || $old === null
            || json_encode($old, JSON_PRESERVE_ZERO_FRACTION) === json_encode(self::ARCHITECTURE_INTERACTION['value'], JSON_PRESERVE_ZERO_FRACTION)) {
            return null;
        }

        return [
            ...self::ARCHITECTURE_INTERACTION,
            'old_value' => $old,
        ];
    }

    /** @return array{gene: string, old_value: mixed, value: mixed}|null */
    private function mutation(
        string $family,
        string $target,
        ModelVersion $baseline,
        array $attempted,
        ?LabLearningLanePair $latestPair = null,
    ): ?array {
        $schema = app(StrategyParameterSchemaService::class)->schema($family);
        $parameters = (array) $baseline->parameters;
        $keys = self::TARGET_GENES[$target] ?? [];
        $activation = $this->activationManifest($latestPair);
        $skipped = [];
        foreach ($keys as $gene) {
            if (in_array($gene, $attempted, true)
                || ! array_key_exists($gene, $schema)
                || ! array_key_exists($gene, $parameters)) {
                continue;
            }
            $old = $parameters[$gene];
            $new = $this->boundedValue($gene, $target, (array) $schema[$gene], $old);
            if ($new !== null && json_encode($old, JSON_PRESERVE_ZERO_FRACTION) !== json_encode($new, JSON_PRESERVE_ZERO_FRACTION)) {
                $screen = $this->activationStatus($gene, $old, $new, $activation, $parameters);
                if ((string) $screen['status'] === 'unsupported') {
                    $skipped[] = $screen;

                    continue;
                }

                return [
                    'gene' => $gene,
                    'old_value' => $old,
                    'value' => $new,
                    'activation_manifest' => $activation,
                    'activation_screen' => [
                        'protocol' => 'repair_gene_activation_screen_v1',
                        'status' => (string) $screen['status'],
                        'selected_gene' => $gene,
                        'selected_gene_evidence' => $screen,
                        'skipped_genes' => $skipped,
                        'source_control_run_id' => $latestPair?->control_evidence_run_id,
                        'performance_credit' => false,
                        'promotion_evidence' => false,
                    ],
                ];
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function activationManifest(?LabLearningLanePair $pair): array
    {
        $runId = trim((string) $pair?->control_evidence_run_id);
        if ($runId === '') {
            return [];
        }
        $run = LabEvaluationRun::query()
            ->where('run_id', $runId)
            ->where('phase', 'full_validation')
            ->where('status', 'completed')
            ->latest('id')
            ->first();

        return (array) data_get($run?->metrics, 'agent_result.parameter_activation_manifest', []);
    }

    /** @return array<string,mixed> */
    private function activationStatus(string $gene, mixed $old, mixed $new, array $manifest, array $baseline = []): array
    {
        return app(CausalParameterActivationService::class)->status($gene, $old, $new, $manifest, $baseline);
    }

    private function boundedValue(string $gene, string $target, array $definition, mixed $old): mixed
    {
        [$type, $min, $max] = array_pad($definition, 3, null);
        if ($type === 'boolean') {
            return in_array($gene, ['avoid_high_volatility', 'transition_firewall_enabled', 'session_filter_enabled'], true)
                ? true : ! (bool) $old;
        }
        if (! in_array($type, ['integer', 'number', 'float', 'numeric'], true)
            || ! is_numeric($old) || ! is_numeric($min) || ! is_numeric($max)) {
            return null;
        }
        $decrease = in_array($gene, [
            'high_volatility_risk_multiplier', 'max_loss_streak_before_wait',
            'minimum_signal_confidence', 'confirmation_candles', 'lookback',
            'max_spread_atr_ratio', 'trend_down_strength_min', 'trend_up_strength_min',
        ], true);
        if ($target === 'trade_frequency') {
            $decrease = true;
        }
        $step = $type === 'integer' ? 1.0 : max(.0001, ((float) $max - (float) $min) * .1);
        $value = max((float) $min, min((float) $max, (float) $old + ($decrease ? -$step : $step)));

        return $type === 'integer' ? (int) round($value) : round($value, 6);
    }

    private function normalizeTarget(string $target): string
    {
        $target = strtolower(trim($target));

        return match ($target) {
            'drawdown', 'max_drawdown', 'risk', 'ruin_risk', 'risk_of_ruin', 'non_target_regression' => 'drawdown_risk',
            'calendar_stability' => 'monthly_survival',
            'temporal_instability' => 'temporal_stability',
            'strategy_error' => 'edge_quality',
            'selection_error', 'abstention_failure', 'entry_quality' => 'selection_quality',
            'execution_error' => 'execution_quality',
            'risk_error' => 'drawdown_risk',
            'management_error' => 'management_quality',
            'analysis_error' => 'regime_coverage',
            'process_integrity_error', 'adaptation_error', 'evidence_completion',
            'technical_repair', 'data_failure', 'execution_failure' => 'evidence_completion',
            default => array_key_exists($target, self::TARGET_GENES) ? $target : 'evidence_completion',
        };
    }
}
