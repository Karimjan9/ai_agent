<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningLesson;
use App\Models\LabLearningLanePair;
use Illuminate\Support\Facades\Schema;

/** Reserves one memory-guided/blinded/frozen-control triplet per generation. */
class CausalLearningCohortPlannerService
{
    public const PROTOCOL = 'causal_learning_counterfactual_cohort_v1';

    /** @return array<int, array<string, mixed>> */
    public function seedPlan(AgentLearningLesson $lesson): array
    {
        $authority = (string) data_get(
            app(CausalLessonAdmissionService::class)->assess($lesson),
            'source_authority',
            'quarantined',
        );
        $guidedRole = $authority === 'canonical_causal_source' ? 'memory_guided' : 'hypothesis_guided';

        return collect([$guidedRole, 'blinded', 'frozen_control'])
            ->map(fn (string $role, int $index): array => [
                'family' => (string) $lesson->strategy_family,
                // lab_agents.origin is an intentionally compact indexed
                // provenance label (24 chars in the production schema).
                // The full protocol remains in the causal cohort contract.
                'origin' => 'causal_confirm',
                'target' => (string) ($lesson->failure_class ?: 'causal_learning'),
                'evolution_mode' => $role === 'frozen_control'
                    ? 'frozen_control'
                    : 'causal_learning_counterfactual',
                'niche' => [
                    'slot' => $index + 1,
                    'data_lane' => 'price',
                    'causal_confirmation_source_lesson_id' => (int) $lesson->id,
                    'promotion_evidence' => false,
                ],
            ])->all();
    }

    public function eligibleLesson(
        string $symbol,
        string $timeframe,
        ?string $family = null,
        ?int $lessonId = null,
    ): ?AgentLearningLesson {
        if (! Schema::hasTable('agent_learning_lessons')
            || ! Schema::hasTable('agent_learning_settlements')
            || ! Schema::hasTable('agent_learning_causal_experiments')) {
            return null;
        }
        $query = AgentLearningLesson::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('lesson_type', 'skill_lesson')
            ->whereIn('status', ['provisional', 'confirmed'])
            ->where('outcome', 'beneficial')
            ->whereNotNull('parameter_key');
        if (filled($family)) {
            $query->where('strategy_family', $family);
        }
        if ($lessonId !== null) {
            $query->whereKey($lessonId);
        }

        $eligible = $query->latest('observed_at')->latest('id')->get()
            ->filter(function (AgentLearningLesson $lesson): bool {
                $gene = (string) $lesson->parameter_key;
                // Planner and salvage ranking deliberately share this exact
                // fail-closed predicate. A ranked hypothesis may never be
                // reported ready when cohort construction would reject it.
                if (data_get(app(CausalLessonAdmissionService::class)->assess($lesson), 'source_ready') !== true) {
                    return false;
                }

                $pairId = (int) data_get($lesson->evidence, 'pair_id', 0);
                $attempts = AgentLearningCausalExperiment::query()
                    ->where('symbol', strtoupper((string) $lesson->symbol))
                    ->where('timeframe', strtoupper((string) $lesson->timeframe))
                    ->where('strategy_family', $lesson->strategy_family)
                    ->where('gene_key', $gene)
                    ->get()
                    ->filter(fn ($experiment): bool => (int) data_get($experiment->evidence, 'source_pair_id', 0) === $pairId);
                // Never open a duplicate while any generation still owns this
                // lesson, including a legacy cohort. Completed pre-v2 attempts
                // remain immutable audit evidence, but cannot exhaust the new
                // target-aligned protocol before it has run even once.
                if ($attempts->contains(fn ($experiment): bool => in_array((string) $experiment->status, [
                    'awaiting_counterfactuals', 'ready_for_replay', 'outcomes_pending',
                ], true))) {
                    return false;
                }

                $currentProtocolAttempts = $attempts->filter(fn ($experiment): bool => data_get($experiment->evidence, 'confirmation_evidence_protocol')
                        === CausalLearningConfirmationService::EVIDENCE_PROTOCOL
                );
                if ($currentProtocolAttempts->contains(fn ($experiment): bool => (string) $experiment->status === 'confirmed'
                )) {
                    return false;
                }

                return $currentProtocolAttempts->count()
                    < max(1, (int) config('services.learning_lane.confirmation_max_attempts', 3));
            });
        if ($eligible->isEmpty()) {
            return null;
        }
        // Use the deterministic salvage score instead of "latest wins".
        // This sends compute to the signal closest to its next causal gate;
        // the selected legacy row is still only a hypothesis and must run a
        // fresh guided/blinded/frozen-control triplet below.
        $salvagePlan = app(EvidenceSalvageConveyorService::class)
            ->plan($symbol, $timeframe, max(20, $eligible->count()));
        $selectedLessonId = (int) data_get($salvagePlan, 'selected.lesson_id', 0);
        if ($selectedLessonId > 0) {
            $selected = $eligible->first(fn (AgentLearningLesson $lesson): bool => (int) $lesson->id === $selectedLessonId);
            if ($selected) {
                return $selected;
            }
        }
        $priority = collect($salvagePlan['ranked'] ?? [])
            ->mapWithKeys(fn (array $row): array => [(int) $row['lesson_id'] => (float) $row['priority']]);

        return $eligible->sortByDesc(fn (AgentLearningLesson $lesson): float => (float) ($priority[(int) $lesson->id] ?? 0))->first();
    }

    /** @return array{plan: array<int, array<string, mixed>>, contract: array<string, mixed>} */
    public function materialize(array $plan, string $symbol, string $timeframe, int $generationId): array
    {
        if (collect($plan)->contains(fn ($slot): bool => (int) data_get($slot, 'niche.prospective_repair_source_pair_id', 0) > 0)) {
            return app(ProspectiveRepairExperimentService::class)->materialize($plan, $symbol, $timeframe, $generationId);
        }
        // A falsified confirmation owns the next learning budget.  Delegate
        // its explicitly seeded plan before looking for another positive
        // lesson, otherwise the scheduler can skip the repair frontier and
        // keep confirming unrelated memory while the known failure remains.
        if (collect($plan)->contains(fn (array $slot): bool => (int) data_get($slot, 'niche.causal_repair_source_experiment_id', 0) > 0
        )) {
            return app(CausalRepairFrontierService::class)->materialize(
                $plan,
                $symbol,
                $timeframe,
                $generationId,
            );
        }

        $base = [
            'protocol' => self::PROTOCOL,
            'generation_id' => $generationId,
            'status' => 'no_eligible_reproduction_source',
            'roles' => ['memory_guided_or_hypothesis_guided', 'blinded', 'frozen_control'],
            'required_independent_windows' => (int) config('services.learning_lane.causal_fold_count', 9),
            'minimum_powered_windows' => (int) config('services.learning_lane.causal_minimum_powered_windows', 6),
            'minimum_positive_windows' => (int) config('services.learning_lane.causal_minimum_positive_windows', 4),
            'promotion_evidence' => false,
        ];
        $base['evidence_salvage'] = app(EvidenceSalvageConveyorService::class)->plan($symbol, $timeframe, 10);
        if (! Schema::hasTable('agent_learning_lessons') || ! Schema::hasTable('agent_learning_settlements')) {
            return ['plan' => array_values($plan), 'contract' => $base];
        }
        $familyGroups = collect($plan)->keys()->groupBy(function (int $index) use ($plan): string {
            $slot = (array) $plan[$index];
            $volume = (bool) data_get($slot, 'niche.volume_shadow', false)
                || (string) data_get($slot, 'niche.data_lane', 'price') === 'volume';

            return $volume ? '' : (string) data_get($slot, 'family', '');
        })->filter(fn ($indexes, string $family): bool => $family !== '' && $indexes->count() >= 3);

        foreach ($familyGroups as $family => $indexes) {
            $requestedLessonId = collect($indexes)->map(
                fn (int $index): int => (int) data_get($plan[$index], 'niche.causal_confirmation_source_lesson_id', 0),
            )->filter(fn (int $id): bool => $id > 0)->unique()->first();
            $lesson = $this->eligibleLesson(
                $symbol,
                $timeframe,
                $family,
                $requestedLessonId > 0 ? $requestedLessonId : null,
            );
            if (! $lesson) {
                continue;
            }
            $sourceAdmission = app(CausalLessonAdmissionService::class)->assess($lesson);
            if (data_get($sourceAdmission, 'research_reproduction_ready') !== true) {
                continue;
            }
            $guidedRole = data_get($sourceAdmission, 'canonical_positive') === true
                ? 'memory_guided'
                : 'hypothesis_guided';
            $skillCartridge = app(CanonicalSkillCartridgeService::class)->retrieveForLesson($lesson);
            if (data_get($skillCartridge, 'status') !== 'compatible_cartridge_found') {
                continue;
            }
            $pair = LabLearningLanePair::query()
                ->with(['candidateAgent.modelVersion', 'controlAgent.modelVersion', 'controlResponseMap'])
                ->find((int) data_get($lesson->evidence, 'pair_id', 0));
            if (! $pair || ! $pair->controlAgent?->modelVersion) {
                continue;
            }
            $sourceControl = $pair->controlAgent;
            if ((string) $sourceControl->symbol !== strtoupper($symbol)
                || (string) $sourceControl->timeframe !== strtoupper($timeframe)
                || (string) $sourceControl->strategy_family !== $family) {
                // The lesson, pair and frozen control must name one executable
                // identity before a composition passport can be issued.
                continue;
            }
            $sourceContext = $this->sourceContext($lesson, $pair, $skillCartridge);
            $sourceContextHash = $sourceContext === [] ? null : hash('sha256', json_encode(
                $sourceContext,
                JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            ));
            $sourceModel = $pair->controlAgent->modelVersion;
            $sourceArchitecture = (string) data_get(
                $sourceModel->metadata,
                'strategy_architecture',
                data_get($sourceModel->metadata, 'semantic_group.architecture', ''),
            );
            $sourceTactic = (string) data_get($sourceModel->metadata, 'tactic_contract.architecture', $sourceArchitecture);
            $passport = app(StrategyTacticRiskCompositionPlannerService::class)->freezeConfirmationBaseline(
                $family,
                $timeframe,
                (string) $pair->control_data_hash,
                (string) $pair->control_execution_hash,
                $sourceArchitecture,
                $sourceTactic,
                (array) data_get($sourceModel->metadata, 'smart_composition.composition_passport', []),
            );
            if ($passport === []) {
                // The exact source runtime has no composition adapter. Keep
                // searching for another eligible family; a requested learning
                // confirmation will fail planning before any agent is created.
                continue;
            }
            $baselineSemanticGroup = (array) data_get(
                $pair->controlAgent->modelVersion->metadata,
                'semantic_group',
                [],
            );
            $chosen = $indexes->sortBy(function (int $index) use ($plan): int {
                $slot = (array) $plan[$index];
                if (in_array((string) data_get($slot, 'niche.learning_evolution.experiment_role'), ['exploit', 'repair'], true)) {
                    return 5;
                }
                if ((bool) data_get($slot, 'niche.validated_parent_required', false)) {
                    return 4;
                }
                if ((bool) data_get($slot, 'niche.control_only', false)) {
                    return 3;
                }
                if ((bool) data_get($slot, 'niche.structural_research', false)) {
                    return 2;
                }

                return 1;
            })->take(3)->values();
            if ($chosen->count() !== 3) {
                continue;
            }
            $gene = (string) $lesson->parameter_key;
            $value = $this->lessonValue($lesson);
            $target = (string) data_get($lesson->evidence, 'failure_signature.failure_target', $lesson->failure_class ?: 'causal_learning');
            $experimentKey = hash('sha512', json_encode([
                self::PROTOCOL, $generationId, $lesson->id, $family, $gene, $value,
            ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            $blindedMutation = app(CausalBlindedMutationSelectorService::class)->select(
                $family,
                $target,
                (array) $pair->controlAgent->modelVersion->parameters,
                'lesson:'.$lesson->id,
                $gene,
                $value,
            );
            if ($blindedMutation === null) {
                continue;
            }
            foreach ([$guidedRole, 'blinded', 'frozen_control'] as $offset => $role) {
                $index = (int) $chosen[$offset];
                $slot = (array) $plan[$index];
                $niche = (array) data_get($slot, 'niche', []);
                $niche = [
                    ...$niche,
                    // Counterfactual role is an experiment dimension, not a
                    // trading semantic cell. Copy the source baseline's exact
                    // cell for all three arms so the same-parent requirement
                    // does not manufacture a cross-cell genetic edge.
                    ...($baselineSemanticGroup !== [] ? [
                        'role' => data_get($baselineSemanticGroup, 'role'),
                        'specialist_role' => data_get($baselineSemanticGroup, 'role'),
                        'regime' => data_get($baselineSemanticGroup, 'regime'),
                        'volatility' => data_get($baselineSemanticGroup, 'volatility'),
                        'direction' => data_get($baselineSemanticGroup, 'direction'),
                    ] : []),
                    'regime' => $baselineSemanticGroup !== []
                        ? data_get($baselineSemanticGroup, 'regime')
                        : ($lesson->regime ?: data_get($niche, 'regime')),
                    'volatility' => $baselineSemanticGroup !== []
                        ? data_get($baselineSemanticGroup, 'volatility')
                        : ($lesson->volatility ?: data_get($niche, 'volatility')),
                    'transition_state' => $lesson->transition_state ?: data_get($niche, 'transition_state'),
                    'state_cluster' => $lesson->state_cluster_id ?: data_get($niche, 'state_cluster'),
                    'causal_learning_cohort' => [
                        'protocol' => self::PROTOCOL,
                        'experiment_key' => $experimentKey,
                        'experiment_kind' => $guidedRole === 'hypothesis_guided'
                            ? 'legacy_hypothesis_reproduction'
                            : 'memory_confirmation',
                        'role' => $role,
                        'source_lesson_id' => (int) $lesson->id,
                        'gene' => $gene,
                        'value' => $value,
                        'source_pair_id' => (int) $pair->id,
                        'source_candidate_agent_id' => (int) $pair->candidate_agent_id,
                        'source_control_agent_id' => (int) $pair->control_agent_id,
                        'source_authority' => (string) data_get($sourceAdmission, 'source_authority'),
                        'source_authority_blockers' => (array) data_get($sourceAdmission, 'authority_blockers', []),
                        'source_context_scope' => $sourceContext,
                        'source_context_hash' => $sourceContextHash,
                        'legacy_hypothesis_grants_credit' => false,
                        'baseline_model_version_id' => (int) $pair->controlAgent->model_version_id,
                        'baseline_old_value' => $this->lessonOldValue($lesson),
                        'construction_protocol' => CausalRepairFrontierService::CONSTRUCTION_PROTOCOL,
                        'blinded_selector' => $blindedMutation,
                        'same_parent_required' => true,
                        'same_dataset_required' => true,
                        'same_execution_contract_required' => true,
                        'confirmation_route' => app(ResearchPaperEpochContractService::class)->confirmationRoute(
                            'historical_causal_confirmation', [
                                'experiment_key' => $experimentKey, 'source_lesson_id' => (int) $lesson->id,
                                'source_pair_id' => (int) $pair->id,
                                'baseline_model_version_id' => (int) $pair->controlAgent->model_version_id,
                                'source_context_hash' => $sourceContextHash,
                            ],
                        ),
                        'promotion_evidence' => false,
                        // Only the guided arm may receive executable memory.
                        // Blinded/control metadata must not carry the donor
                        // intervention even when their mutations are frozen.
                        'skill_cartridge' => in_array($role, ['memory_guided', 'hypothesis_guided'], true)
                            ? $skillCartridge : null,
                    ],
                    'learning_memory_required' => $role === 'memory_guided',
                    'legacy_hypothesis_reproduction' => $role === 'hypothesis_guided',
                    'learning_memory_blinded' => $role === 'blinded',
                    'control_only' => $role === 'frozen_control',
                    'composition_lane' => 'causal_learning_confirmation',
                    'composition_passport' => $passport,
                ];
                if ($role === 'frozen_control') {
                    foreach (['declared_gene', 'declared_value', 'shadow_mutation_gene', 'state_machine_variant', 'regime_classifier_variant', 'entry_topology_variant'] as $key) {
                        unset($niche[$key]);
                    }
                    if ($baselineSemanticGroup === []) {
                        $niche['role'] = 'frozen_control';
                        $niche['specialist_role'] = 'frozen_control';
                    }
                    $slot['evolution_mode'] = 'frozen_control';
                } elseif (in_array($role, ['memory_guided', 'hypothesis_guided'], true)) {
                    $niche['declared_gene'] = $gene;
                    $niche['declared_value'] = $value;
                    $niche['causal_learning_exact_value'] = $value;
                    $niche['control_only'] = false;
                    $slot['evolution_mode'] = 'causal_learning_counterfactual';
                } else {
                    // The blinded arm tests the selector, not a duplicate of
                    // the guided mutation. It receives the same baseline and
                    // budget but runs the ordinary cold-start one-gene policy.
                    foreach (['declared_gene', 'declared_value', 'causal_learning_exact_value', 'shadow_mutation_gene'] as $key) {
                        unset($niche[$key]);
                    }
                    $niche['control_only'] = false;
                    $niche['selector_policy'] = 'cold_start_memory_blinded_selector';
                    $slot['evolution_mode'] = 'causal_learning_selector_counterfactual';
                }
                $niche = $this->isolateCausalNiche($niche);
                $slot['family'] = $family;
                $slot['target'] = $target;
                $slot['niche'] = $niche;
                $plan[$index] = $slot;
            }

            return ['plan' => array_values($plan), 'contract' => [
                ...$base,
                'status' => 'materialized',
                'experiment_key' => $experimentKey,
                'source_lesson_id' => (int) $lesson->id,
                'strategy_family' => $family,
                'target' => $target,
                'gene' => $gene,
                'value' => $value,
                'source_pair_id' => (int) $pair->id,
                'guided_role' => $guidedRole,
                'source_authority' => (string) data_get($sourceAdmission, 'source_authority'),
                'source_context_scope' => $sourceContext,
                'source_context_hash' => $sourceContextHash,
                'legacy_hypothesis_grants_credit' => false,
                'skill_cartridge_id' => (int) data_get($skillCartridge, 'cartridge_id'),
                'baseline_model_version_id' => (int) $pair->controlAgent->model_version_id,
                'blinded_policy' => 'cold_start_memory_blinded_selector',
                'construction_protocol' => CausalRepairFrontierService::CONSTRUCTION_PROTOCOL,
                'blinded_selector' => $blindedMutation,
                'slots' => $chosen->map(fn (int $index): int => $index + 1)->all(),
            ]];
        }

        return ['plan' => array_values($plan), 'contract' => $base];
    }

    /** @return array<string,string> */
    private function sourceContext(
        AgentLearningLesson $lesson,
        LabLearningLanePair $pair,
        array $skillCartridge,
    ): array {
        $raw = (array) data_get($skillCartridge, 'trait_capsule.activation_context.predicate', []);
        if ($raw === []) {
            $raw = (array) data_get($skillCartridge, 'context', []);
        }
        if ($raw === []) {
            $raw = (array) data_get($lesson->evidence, 'context_scope', []);
        }
        if ($raw === []) {
            $raw = (array) data_get($pair->metadata, 'context_scope', data_get($pair->failure_signature, 'state', []));
        }

        return app(ContextContractV2Service::class)->canonicalDeclaredAxes($raw);
    }

    /**
     * Remove the discovery seat's mutation contract after that seat is
     * reserved for a causal arm. The original group remains useful for
     * allocation accounting, but it cannot impose a second structural gene
     * on a guided/blinded/frozen single-variable experiment.
     *
     * @param  array<string, mixed>  $niche
     * @return array<string, mixed>
     */
    public function isolateCausalNiche(array $niche): array
    {
        $role = (string) data_get($niche, 'causal_learning_cohort.role', '');
        if (! in_array($role, ['memory_guided', 'hypothesis_guided', 'repair_guided', 'blinded', 'frozen_control'], true)) {
            return $niche;
        }

        $displaced = [
            'structural_research' => (bool) data_get($niche, 'structural_research', false),
            'declared_gene' => data_get($niche, 'declared_gene'),
            'hybrid_evolution_lane' => data_get($niche, 'hybrid_evolution_lane'),
            'promotion_evidence' => false,
        ];
        foreach ([
            'structural_hypothesis_protocol', 'structural_operation', 'structural_hypothesis_id',
            'state_machine_variant', 'regime_classifier_variant', 'entry_topology_variant',
            'architecture_interaction_variant', 'declared_gene', 'declared_value',
            'declared_genes', 'declared_values', 'hybrid_evolution_lane',
            'hybrid_evolution_contract', 'architecture_experiment', 'architecture_escape',
        ] as $key) {
            unset($niche[$key]);
        }
        $niche['causal_displaced_slot_contract'] = $displaced;
        $niche['structural_research'] = false;
        $niche['structural_mutation_required'] = false;
        $niche['control_only'] = $role === 'frozen_control';

        if (in_array($role, ['memory_guided', 'hypothesis_guided', 'repair_guided'], true)) {
            $niche['declared_gene'] = data_get($niche, 'causal_learning_cohort.gene');
            $niche['declared_value'] = data_get($niche, 'causal_learning_cohort.value');
            $niche['causal_learning_exact_value'] = data_get($niche, 'causal_learning_cohort.value');
        } elseif ($role === 'blinded') {
            unset($niche['causal_learning_exact_value']);
            $niche['selector_policy'] = 'cold_start_memory_blinded_selector';
        } else {
            unset($niche['causal_learning_exact_value'], $niche['selector_policy']);
        }

        return $niche;
    }

    private function canonicalPositive(AgentLearningLesson $lesson): bool
    {
        return data_get(app(CausalLessonAdmissionService::class)->assess($lesson), 'canonical_positive') === true;
    }

    private function lessonValue(AgentLearningLesson $lesson): mixed
    {
        return app(CausalLessonAdmissionService::class)->newValue($lesson);
    }

    private function lessonOldValue(AgentLearningLesson $lesson): mixed
    {
        return app(CausalLessonAdmissionService::class)->oldValue($lesson);
    }
}
