<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningLesson;
use App\Models\AgentLearningSettlement;
use App\Models\LabLearningLanePair;
use Illuminate\Support\Facades\Schema;

/** Reserves one memory-guided/blinded/frozen-control triplet per generation. */
class CausalLearningCohortPlannerService
{
    public const PROTOCOL = 'causal_learning_counterfactual_cohort_v1';

    /** @return array<int, array<string, mixed>> */
    public function seedPlan(AgentLearningLesson $lesson): array
    {
        return collect(['memory_guided', 'blinded', 'frozen_control'])
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

        return $query->latest('observed_at')->latest('id')->get()
            ->first(function (AgentLearningLesson $lesson): bool {
                $gene = (string) $lesson->parameter_key;
                if (! array_key_exists($gene, app(StrategyParameterSchemaService::class)->schema((string) $lesson->strategy_family))) {
                    return false;
                }
                if (! $this->canonicalPositive($lesson) || $this->lessonValue($lesson) === null) {
                    return false;
                }
                // Admission and construction must agree on executability.
                // A positive lesson without its exact paired cartridge caused
                // a guided slot to persist without a seal and invalidated the
                // whole counterfactual cohort.
                if (data_get(app(CanonicalSkillCartridgeService::class)->retrieveForLesson($lesson), 'status')
                    !== 'compatible_cartridge_found') {
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
    }

    /** @return array{plan: array<int, array<string, mixed>>, contract: array<string, mixed>} */
    public function materialize(array $plan, string $symbol, string $timeframe, int $generationId): array
    {
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
            'status' => 'no_eligible_canonical_memory',
            'roles' => ['memory_guided', 'blinded', 'frozen_control'],
            'required_independent_windows' => (int) config('services.learning_lane.causal_fold_count', 9),
            'minimum_powered_windows' => (int) config('services.learning_lane.causal_minimum_powered_windows', 6),
            'minimum_positive_windows' => (int) config('services.learning_lane.causal_minimum_positive_windows', 4),
            'promotion_evidence' => false,
        ];
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
            $passport = (array) data_get($pair->controlAgent->modelVersion->metadata, 'smart_composition.composition_passport', []);
            if ($passport === []) {
                $passport = app(StrategyTacticRiskCompositionPlannerService::class)->freezeConfirmationBaseline(
                    $family,
                    $timeframe,
                    (string) $pair->control_data_hash,
                    (string) $pair->control_execution_hash,
                );
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
            foreach (['memory_guided', 'blinded', 'frozen_control'] as $offset => $role) {
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
                        'role' => $role,
                        'source_lesson_id' => (int) $lesson->id,
                        'gene' => $gene,
                        'value' => $value,
                        'source_pair_id' => (int) $pair->id,
                        'source_candidate_agent_id' => (int) $pair->candidate_agent_id,
                        'source_control_agent_id' => (int) $pair->control_agent_id,
                        'baseline_model_version_id' => (int) $pair->controlAgent->model_version_id,
                        'baseline_old_value' => $this->lessonOldValue($lesson),
                        'construction_protocol' => CausalRepairFrontierService::CONSTRUCTION_PROTOCOL,
                        'blinded_selector' => $blindedMutation,
                        'same_parent_required' => true,
                        'same_dataset_required' => true,
                        'same_execution_contract_required' => true,
                        'promotion_evidence' => false,
                        // Only the guided arm may receive executable memory.
                        // Blinded/control metadata must not carry the donor
                        // intervention even when their mutations are frozen.
                        'skill_cartridge' => $role === 'memory_guided' ? $skillCartridge : null,
                    ],
                    'learning_memory_required' => $role === 'memory_guided',
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
                } elseif ($role === 'memory_guided') {
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
        if (! in_array($role, ['memory_guided', 'repair_guided', 'blinded', 'frozen_control'], true)) {
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

        if (in_array($role, ['memory_guided', 'repair_guided'], true)) {
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
        $pairId = (int) data_get($lesson->evidence, 'pair_id', 0);
        $pair = $pairId > 0 ? LabLearningLanePair::query()->with([
            'controlResponseMap', 'candidateAgent.modelVersion', 'controlAgent.modelVersion',
        ])->find($pairId) : null;
        if (! $pair || ! $pair->isVerifiedControlPair()) {
            return false;
        }

        $gene = (string) $lesson->parameter_key;
        $change = (array) data_get($pair->candidateAgent?->parameter_diff, $gene, []);
        $old = $this->lessonOldValue($lesson);
        $new = $this->lessonValue($lesson);
        if ($gene === '' || count((array) $pair->candidateAgent?->parameter_diff) !== 1
            || $change === [] || ! $this->same($old, data_get($change, 'old'))
            || ! $this->same($new, data_get($change, 'new'))
            || ! $this->same($old, data_get($pair->controlAgent?->modelVersion?->parameters, $gene))
            || ! $this->same($new, data_get($pair->candidateAgent?->modelVersion?->parameters, $gene))) {
            return false;
        }

        return AgentLearningSettlement::query()
            ->where('source_type', LabLearningLanePair::class)
            ->where('source_id', $pairId)
            ->where('evidence_state', 'positive')
            ->where('hard_failure', false)
            ->exists();
    }

    private function lessonValue(AgentLearningLesson $lesson): mixed
    {
        $value = data_get($lesson->evidence, 'new_value', data_get($lesson->evidence, 'failure_signature.new_value'));

        return is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
    }

    private function lessonOldValue(AgentLearningLesson $lesson): mixed
    {
        $value = data_get($lesson->evidence, 'old_value', data_get($lesson->evidence, 'failure_signature.old_value'));

        return is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
    }

    private function same(mixed $left, mixed $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            return abs((float) $left - (float) $right) < 0.000000001;
        }

        return json_encode($left, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
            === json_encode($right, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
