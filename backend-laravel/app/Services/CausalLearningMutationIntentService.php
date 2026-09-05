<?php

namespace App\Services;

use App\Models\AgentLearningLesson;
use App\Models\AgentLearningMutationIntent;
use App\Models\AgentLearningRetrieval;
use App\Models\AgentLearningSettlement;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\ModelVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Seals the only durable proof that retrieved memory influenced a mutation.
 *
 * Retrieval may happen before the agent foreign key exists.  The immutable
 * intent is therefore sealed against the packet and final parameter diff
 * before LabAgent is inserted, then bound to the newborn agent afterwards.
 * A later reconciliation can add provenance, but can never turn an
 * independent mutation into a memory-guided mutation.
 */
class CausalLearningMutationIntentService
{
    public const PROTOCOL = 'causal_learning_mutation_intent_v1';

    /** @return array<string, mixed> */
    public function plan(
        LabGeneration $generation,
        array $packet,
        string $family,
        string $target,
        array $base,
        array $parameters,
        array $parameterDiff,
        ?string $cohortRole = null,
    ): array {
        $changedGenes = array_values(array_map('strval', array_keys($parameterDiff)));
        $selectedGene = count($changedGenes) === 1 ? $changedGenes[0] : null;
        $records = collect([
            ...((array) data_get($packet, 'positive_lessons', [])),
            ...((array) data_get($packet, 'harmful_lessons', [])),
            ...((array) data_get($packet, 'uncertainty_lessons', [])),
        ])->filter(fn ($row): bool => is_array($row) && (int) data_get($row, 'lesson_id', 0) > 0)->values();
        $retrievedLessonIds = $records->pluck('lesson_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $selectedRecords = $selectedGene === null
            ? collect()
            : $records->filter(fn (array $row): bool => (string) data_get($row, 'parameter_key') === $selectedGene)->values();
        $selectedLessonIds = $selectedRecords->pluck('lesson_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $lessons = AgentLearningLesson::query()->whereIn('id', $selectedLessonIds->all() ?: [0])->get()->keyBy('id');
        $causalLessonIds = collect();
        $causalRetrievalIds = collect();
        foreach ($selectedRecords as $record) {
            $lesson = $lessons->get((int) data_get($record, 'lesson_id'));
            if (! $lesson || (string) data_get($record, 'provenance') !== 'canonical_settled') {
                continue;
            }
            if (! $this->canonicalPositive($lesson) || ! $this->matchesMutation($lesson, $parameterDiff)) {
                continue;
            }
            $causalLessonIds->push((int) $lesson->id);
            if (filled(data_get($record, 'retrieval_id'))) {
                $causalRetrievalIds->push((string) data_get($record, 'retrieval_id'));
            }
        }
        $packetId = filled(data_get($packet, 'packet_id')) ? (string) data_get($packet, 'packet_id') : null;
        $retrievedAt = $packetId
            ? AgentLearningRetrieval::query()->where('packet_id', $packetId)->min('created_at')
            : null;
        $old = $selectedGene !== null ? data_get($parameterDiff, $selectedGene.'.old') : null;
        $new = $selectedGene !== null ? data_get($parameterDiff, $selectedGene.'.new') : null;
        $influence = match (true) {
            $cohortRole === 'blinded' => 'blinded_counterfactual',
            $cohortRole === 'frozen_control' => 'frozen_control',
            $cohortRole === 'repair_guided' => 'causal_repair_guided',
            $causalLessonIds->isNotEmpty() => 'memory_guided',
            default => 'independent_exploration',
        };
        $baselineHash = $this->hash($base);
        $parameterHash = $this->hash($parameters);
        $mutationHash = $this->hash($parameterDiff);
        $intentKey = hash('sha512', json_encode([
            self::PROTOCOL, $generation->id, data_get($packet, 'packet_id'), $family,
            $target, $selectedGene, $mutationHash, $cohortRole,
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        return [
            'protocol' => self::PROTOCOL,
            'intent_key' => $intentKey,
            'packet_id' => $packetId,
            'symbol' => strtoupper((string) $generation->laboratory?->symbol),
            'timeframe' => strtoupper((string) $generation->laboratory?->timeframe),
            'strategy_family' => $family,
            'target' => $target,
            'selected_gene' => $selectedGene,
            'changed_genes' => $changedGenes,
            'influence_type' => $influence,
            'retrieved_lesson_ids' => $retrievedLessonIds->all(),
            'selected_lesson_ids' => $selectedLessonIds->all(),
            'causally_applied_lesson_ids' => $causalLessonIds->unique()->values()->all(),
            'causally_applied_retrieval_ids' => $causalRetrievalIds->unique()->values()->all(),
            'rejected_lesson_ids' => $retrievedLessonIds->diff($selectedLessonIds)->values()->all(),
            'old_value' => ['value' => $old],
            'new_value' => ['value' => $new],
            'baseline_hash' => $baselineHash,
            'parameter_hash' => $parameterHash,
            'mutation_hash' => $mutationHash,
            'retrieved_at' => $retrievedAt ? Carbon::parse($retrievedAt) : null,
            'cohort_role' => $cohortRole,
            'causal_order' => [
                'retrieval_sequence' => 1,
                'mutation_seal_sequence' => 2,
                'agent_persistence_sequence' => 3,
            ],
            'promotion_evidence' => false,
        ];
    }

    public function seal(array $plan, LabGeneration $generation, ModelVersion $model): AgentLearningMutationIntent|array
    {
        if (! Schema::hasTable('agent_learning_mutation_intents')) {
            return ['status' => 'unavailable', 'protocol' => self::PROTOCOL, 'promotion_evidence' => false];
        }
        $influence = (string) data_get($plan, 'influence_type', 'independent_exploration');
        if (str_contains($influence, 'memory')) {
            $cartridge = (array) data_get($plan, 'skill_cartridge', []);
            if ($cartridge === []) {
                $cartridge = app(CanonicalSkillCartridgeService::class)->retrieve(
                    (string) data_get($plan, 'symbol'), (string) data_get($plan, 'timeframe'), (string) data_get($plan, 'strategy_family'),
                    (array) data_get($plan, 'context', data_get($plan, 'scope', [])), [(string) data_get($plan, 'selected_gene')],
                );
            }
            if (data_get($cartridge, 'status') !== 'compatible_cartridge_found') {
                return ['status' => 'blocked', 'reason' => 'MEMORY_GUIDED_CARTRIDGE_ABSTAINED', 'cartridge' => $cartridge, 'promotion_evidence' => false];
            }
            // Older callers stored the scalar directly while the canonical
            // planner stores it under `value`.  Both representations express
            // the same immutable intervention, so normalize before enforcing
            // exact replay rather than silently rejecting a valid cartridge.
            $plannedOld = data_get($plan, 'old_value.value', data_get($plan, 'old_value'));
            $plannedNew = data_get($plan, 'new_value.value', data_get($plan, 'new_value'));
            if (data_get($plan, 'selected_gene') !== data_get($cartridge, 'gene')
                || json_encode($plannedOld) !== json_encode(data_get($cartridge, 'old_value'))
                || json_encode($plannedNew) !== json_encode(data_get($cartridge, 'proposed_value'))) {
                return ['status' => 'blocked', 'reason' => 'EXACT_CARTRIDGE_REPLICATION_MISMATCH', 'cartridge' => $cartridge, 'promotion_evidence' => false];
            }
            $plan['skill_cartridge'] = $cartridge;
        }
        $sealedAt = now();
        $retrievedAt = data_get($plan, 'retrieved_at');
        if ($retrievedAt && Carbon::parse($retrievedAt)->greaterThanOrEqualTo($sealedAt)) {
            $sealedAt = Carbon::parse($retrievedAt)->addMicrosecond();
        }
        $intent = AgentLearningMutationIntent::query()->firstOrCreate(
            ['intent_key' => (string) $plan['intent_key']],
            [
                'intent_id' => (string) Str::uuid(),
                'packet_id' => data_get($plan, 'packet_id'),
                'lab_generation_id' => $generation->id,
                'model_version_id' => $model->id,
                'symbol' => (string) data_get($plan, 'symbol'),
                'timeframe' => (string) data_get($plan, 'timeframe'),
                'strategy_family' => (string) data_get($plan, 'strategy_family'),
                'target' => data_get($plan, 'target'),
                'selected_gene' => data_get($plan, 'selected_gene'),
                'influence_type' => (string) data_get($plan, 'influence_type', 'independent_exploration'),
                'status' => 'sealed',
                'retrieved_lesson_ids' => (array) data_get($plan, 'retrieved_lesson_ids', []),
                'selected_lesson_ids' => (array) data_get($plan, 'selected_lesson_ids', []),
                'causally_applied_lesson_ids' => (array) data_get($plan, 'causally_applied_lesson_ids', []),
                'rejected_lesson_ids' => (array) data_get($plan, 'rejected_lesson_ids', []),
                'causally_applied_retrieval_ids' => (array) data_get($plan, 'causally_applied_retrieval_ids', []),
                'old_value' => data_get($plan, 'old_value'),
                'new_value' => data_get($plan, 'new_value'),
                'baseline_hash' => (string) data_get($plan, 'baseline_hash'),
                'parameter_hash' => (string) data_get($plan, 'parameter_hash'),
                'mutation_hash' => (string) data_get($plan, 'mutation_hash'),
                'retrieved_at' => $retrievedAt,
                'sealed_at' => $sealedAt,
                'metadata' => [
                    'protocol' => self::PROTOCOL,
                    'cohort_role' => data_get($plan, 'cohort_role'),
                    'causal_order' => data_get($plan, 'causal_order'),
                    'skill_cartridge' => data_get($plan, 'skill_cartridge'),
                    'post_hoc_upgrade_forbidden' => true,
                    'promotion_evidence' => false,
                ],
            ],
        );
        $metadata = (array) $model->metadata;
        $metadata['causal_learning_intent'] = $this->contract($intent);
        $model->update(['metadata' => $metadata]);

        return $intent;
    }

    /** @return array<string, mixed> */
    public function bind(AgentLearningMutationIntent|array $intent, LabAgent $agent, ?int $episodeId = null): array
    {
        if (! $intent instanceof AgentLearningMutationIntent) {
            return ['status' => 'unavailable', 'protocol' => self::PROTOCOL, 'promotion_evidence' => false];
        }
        $actualMutationHash = $this->hash((array) $agent->parameter_diff);
        $actualGene = count((array) $agent->parameter_diff) === 1
            ? (string) array_key_first((array) $agent->parameter_diff)
            : null;
        if ($actualMutationHash !== $intent->mutation_hash || $actualGene !== $intent->selected_gene) {
            $intent->update([
                'status' => 'invalid', 'invalid_reason' => 'SEALED_MUTATION_MISMATCH',
                'invalidated_at' => now(), 'lab_agent_id' => $agent->id,
            ]);

            return [
                'status' => 'invalid',
                'reason' => 'SEALED_MUTATION_MISMATCH',
                'sealed_gene' => $intent->selected_gene,
                'actual_gene' => $actualGene,
                'sealed_mutation_hash' => $intent->mutation_hash,
                'actual_mutation_hash' => $actualMutationHash,
                'sealed_change' => ['old' => data_get($intent->old_value, 'value'), 'new' => data_get($intent->new_value, 'value')],
                'actual_change' => $actualGene !== null ? data_get((array) $agent->parameter_diff, $actualGene) : null,
                'promotion_evidence' => false,
            ];
        }
        $intent->update([
            'lab_agent_id' => $agent->id,
            'bound_at' => now(),
            'status' => 'bound',
            'metadata' => [
                ...((array) $intent->metadata),
                'episode_id' => $episodeId,
                'agent_created_at' => $agent->created_at?->toIso8601String(),
                'bound_after_agent_persistence' => true,
                'promotion_evidence' => false,
            ],
        ]);
        $model = $agent->modelVersion?->fresh() ?: $agent->modelVersion;
        if ($model) {
            $metadata = (array) $model->metadata;
            $metadata['causal_learning_intent'] = $this->contract($intent->fresh());
            $model->update(['metadata' => $metadata]);
        }

        return ['status' => 'bound', 'intent_id' => $intent->id, ...$this->contract($intent->fresh())];
    }

    /** @return array<string, mixed> */
    public function contract(AgentLearningMutationIntent $intent): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'intent_id' => (int) $intent->id,
            'intent_uuid' => (string) $intent->intent_id,
            'packet_id' => $intent->packet_id,
            'status' => $intent->status,
            'influence_type' => $intent->influence_type,
            'selected_gene' => $intent->selected_gene,
            'retrieved_lesson_ids' => (array) $intent->retrieved_lesson_ids,
            'selected_lesson_ids' => (array) $intent->selected_lesson_ids,
            'causally_applied_lesson_ids' => (array) $intent->causally_applied_lesson_ids,
            'rejected_lesson_ids' => (array) $intent->rejected_lesson_ids,
            'baseline_hash' => $intent->baseline_hash,
            'parameter_hash' => $intent->parameter_hash,
            'mutation_hash' => $intent->mutation_hash,
            'retrieved_at' => $intent->retrieved_at?->toIso8601String(),
            'sealed_at' => $intent->sealed_at?->toIso8601String(),
            'bound_at' => $intent->bound_at?->toIso8601String(),
            'causal_order' => data_get($intent->metadata, 'causal_order'),
            'post_hoc_upgrade_forbidden' => true,
            'promotion_evidence' => false,
        ];
    }

    private function canonicalPositive(AgentLearningLesson $lesson): bool
    {
        $pairId = (int) data_get($lesson->evidence, 'pair_id', 0);
        if ($pairId <= 0 || $lesson->lesson_type !== 'skill_lesson' || $lesson->outcome !== 'beneficial') {
            return false;
        }
        $pair = LabLearningLanePair::query()->with('controlResponseMap')->find($pairId);
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

    private function matchesMutation(AgentLearningLesson $lesson, array $parameterDiff): bool
    {
        $gene = (string) $lesson->parameter_key;
        if ($gene === '' || count($parameterDiff) !== 1 || ! array_key_exists($gene, $parameterDiff)) {
            return false;
        }
        $change = (array) $parameterDiff[$gene];
        $expectedOld = $this->unwrap(data_get($lesson->evidence, 'old_value', data_get($lesson->evidence, 'failure_signature.old_value')));
        $expectedNew = $this->unwrap(data_get($lesson->evidence, 'new_value', data_get($lesson->evidence, 'failure_signature.new_value')));
        if ($expectedNew === null) {
            return false;
        }
        if ($expectedOld !== null && ! $this->same($expectedOld, data_get($change, 'old'))) {
            return false;
        }

        return $this->same($expectedNew, data_get($change, 'new'));
    }

    private function unwrap(mixed $value): mixed
    {
        return is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
    }

    private function same(mixed $left, mixed $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            return abs((float) $left - (float) $right) < 0.000000001;
        }

        return json_encode($left, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)
            === json_encode($right, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
    }

    private function hash(array $payload): string
    {
        return $this->mutationHash($payload);
    }

    public function mutationHash(array $payload): string
    {
        return hash('sha512', json_encode(
            $this->canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            // MariaDB/SQLite JSON round trips may turn 1.0 into 1.  Strategy
            // execution treats those values identically, so the immutable
            // seal must use one numeric representation on both sides.
            return rtrim(rtrim(sprintf('%.12F', (float) $value), '0'), '.');
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
