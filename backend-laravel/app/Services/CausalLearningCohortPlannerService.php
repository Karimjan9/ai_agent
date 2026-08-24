<?php

namespace App\Services;

use App\Models\AgentLearningLesson;
use App\Models\AgentLearningSettlement;
use App\Models\LabLearningLanePair;
use Illuminate\Support\Facades\Schema;

/** Reserves one memory-guided/blinded/frozen-control triplet per generation. */
class CausalLearningCohortPlannerService
{
    public const PROTOCOL = 'causal_learning_counterfactual_cohort_v1';

    /** @return array{plan: array<int, array<string, mixed>>, contract: array<string, mixed>} */
    public function materialize(array $plan, string $symbol, string $timeframe, int $generationId): array
    {
        $base = [
            'protocol' => self::PROTOCOL,
            'generation_id' => $generationId,
            'status' => 'no_eligible_canonical_memory',
            'roles' => ['memory_guided', 'blinded', 'frozen_control'],
            'required_independent_windows' => max(2, (int) config('services.learning_lane.independent_confirmations_required', 2)),
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
            $lesson = AgentLearningLesson::query()
                ->where('symbol', strtoupper($symbol))
                ->where('timeframe', strtoupper($timeframe))
                ->where('strategy_family', $family)
                ->where('lesson_type', 'skill_lesson')
                ->whereIn('status', ['provisional', 'confirmed'])
                ->where('outcome', 'beneficial')
                ->whereNotNull('parameter_key')
                ->latest('observed_at')
                ->get()
                ->first(fn (AgentLearningLesson $row): bool => $this->canonicalPositive($row)
                    && $this->lessonValue($row) !== null
                    && array_key_exists((string) $row->parameter_key, app(StrategyParameterSchemaService::class)->schema($family)));
            if (! $lesson) {
                continue;
            }
            $chosen = $indexes->sortBy(function (int $index) use ($plan): int {
                $slot = (array) $plan[$index];
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
            foreach (['memory_guided', 'blinded', 'frozen_control'] as $offset => $role) {
                $index = (int) $chosen[$offset];
                $slot = (array) $plan[$index];
                $niche = (array) data_get($slot, 'niche', []);
                $niche = [
                    ...$niche,
                    'regime' => $lesson->regime ?: data_get($niche, 'regime'),
                    'volatility' => $lesson->volatility ?: data_get($niche, 'volatility'),
                    'transition_state' => $lesson->transition_state ?: data_get($niche, 'transition_state'),
                    'state_cluster' => $lesson->state_cluster_id ?: data_get($niche, 'state_cluster'),
                    'causal_learning_cohort' => [
                        'protocol' => self::PROTOCOL,
                        'experiment_key' => $experimentKey,
                        'role' => $role,
                        'source_lesson_id' => (int) $lesson->id,
                        'gene' => $gene,
                        'value' => $value,
                        'same_parent_required' => true,
                        'same_dataset_required' => true,
                        'same_execution_contract_required' => true,
                        'promotion_evidence' => false,
                    ],
                    'learning_memory_required' => $role === 'memory_guided',
                    'learning_memory_blinded' => $role === 'blinded',
                    'control_only' => $role === 'frozen_control',
                ];
                if ($role === 'frozen_control') {
                    foreach (['declared_gene', 'declared_value', 'shadow_mutation_gene', 'state_machine_variant', 'regime_classifier_variant', 'entry_topology_variant'] as $key) {
                        unset($niche[$key]);
                    }
                    $niche['role'] = 'frozen_control';
                    $niche['specialist_role'] = 'frozen_control';
                    $slot['evolution_mode'] = 'frozen_control';
                } else {
                    $niche['declared_gene'] = $gene;
                    $niche['declared_value'] = $value;
                    $niche['causal_learning_exact_value'] = $value;
                    $niche['control_only'] = false;
                    $slot['evolution_mode'] = 'causal_learning_counterfactual';
                }
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
                'slots' => $chosen->map(fn (int $index): int => $index + 1)->all(),
            ]];
        }

        return ['plan' => array_values($plan), 'contract' => $base];
    }

    private function canonicalPositive(AgentLearningLesson $lesson): bool
    {
        $pairId = (int) data_get($lesson->evidence, 'pair_id', 0);
        $pair = $pairId > 0 ? LabLearningLanePair::query()->with('controlResponseMap')->find($pairId) : null;
        if (! $pair || ! $pair->isVerifiedControlPair()) {
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
}
