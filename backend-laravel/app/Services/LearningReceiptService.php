<?php

namespace App\Services;

use App\Models\AgentLearningMutationIntent;
use App\Models\LabAgent;
use App\Models\LabLearningLanePair;

/**
 * Canonical proof-carrying evolution receipt.
 *
 * The experiment intent is sealed on the newborn model; a later settlement
 * is appended beside it.  This is learning evidence only and never changes a
 * screening, paper, parent or champion gate.
 */
class LearningReceiptService
{
    public const PROTOCOL = 'learning_receipt_v2';

    /** @return array<string, mixed> */
    public function issue(LabAgent $agent, array $packet = []): array
    {
        $agent->loadMissing(['modelVersion', 'generation']);
        $model = $agent->modelVersion;
        if (! $model) {
            return [];
        }

        $metadata = (array) $model->metadata;
        $existing = (array) data_get($metadata, 'learning_receipt', []);
        if (data_get($existing, 'protocol') === self::PROTOCOL && filled(data_get($existing, 'intent_hash'))) {
            return $existing;
        }

        $inheritance = (array) data_get($metadata, 'parent_inheritance_protocol', []);
        $mutationIntent = AgentLearningMutationIntent::query()
            ->where('lab_agent_id', $agent->id)
            ->orWhere('model_version_id', $agent->model_version_id)
            ->latest('id')
            ->first();
        $packet = $packet ?: (array) data_get($metadata, 'agent_knowledge_contract.learning_packet', []);
        $changedGenes = array_values(array_map('strval', array_keys((array) $agent->parameter_diff)));
        $changedGene = count($changedGenes) === 1 ? $changedGenes[0] : null;
        $retrievedLessonIds = $mutationIntent
            ? (array) $mutationIntent->retrieved_lesson_ids
            : $this->lessonIds($packet);
        $selectedLessonIds = $mutationIntent
            ? (array) $mutationIntent->selected_lesson_ids
            : $this->selectedLessonIds($packet, $changedGene);
        $causallyAppliedLessonIds = $mutationIntent ? (array) $mutationIntent->causally_applied_lesson_ids : [];
        $rejectedLessonIds = $mutationIntent
            ? (array) $mutationIntent->rejected_lesson_ids
            : array_values(array_diff($retrievedLessonIds, $selectedLessonIds));
        $declaredGene = data_get($metadata, 'hypothesis_contract.changed_gene');
        $geneMatch = ! filled($declaredGene) || (string) $declaredGene === (string) $changedGene;
        $mutationHash = $this->hash((array) $agent->parameter_diff);
        $intentHashMatches = ! $mutationIntent || hash_equals((string) $mutationIntent->mutation_hash, $mutationHash);
        $order = (array) data_get($mutationIntent?->metadata, 'causal_order', []);
        $causalOrderValid = ! $mutationIntent || (
            (int) data_get($order, 'retrieval_sequence') < (int) data_get($order, 'mutation_seal_sequence')
            && (int) data_get($order, 'mutation_seal_sequence') < (int) data_get($order, 'agent_persistence_sequence')
        );
        $integrityValid = $geneMatch && $intentHashMatches && $causalOrderValid
            && (! $mutationIntent || in_array((string) $mutationIntent->status, ['bound', 'settled'], true));
        $intent = [
            'protocol' => self::PROTOCOL,
            'status' => $integrityValid ? 'issued' : 'invalid_intent',
            'agent_id' => (int) $agent->id,
            'generation_id' => (int) $agent->lab_generation_id,
            'baseline' => [
                'promotion_parent_model_version_id' => $agent->parent_a_model_version_id,
                'research_baseline_model_version_id' => data_get($inheritance, 'frozen_research_seed_model_version_id'),
                'control_root_model_version_id' => data_get($inheritance, 'control_root_seed_model_version_id'),
                'explorer_root' => $agent->parent_a_model_version_id === null
                    && ! filled(data_get($inheritance, 'frozen_research_seed_model_version_id'))
                    && ! filled(data_get($inheritance, 'control_root_seed_model_version_id')),
            ],
            // Compatibility alias. Unlike v1 this contains only lessons
            // selected for the actual changed gene, never every packet row.
            'consumed_lesson_ids' => $selectedLessonIds,
            'retrieved_lesson_ids' => $retrievedLessonIds,
            'selected_lesson_ids' => $selectedLessonIds,
            'causally_applied_lesson_ids' => $causallyAppliedLessonIds,
            'rejected_lesson_ids' => $rejectedLessonIds,
            'retrieval_packet_id' => data_get($packet, 'packet_id', data_get($metadata, 'learning_decision.packet_id')),
            'declared_target' => (string) data_get($metadata, 'generation_target', 'profit_factor'),
            'changed_gene' => $changedGene,
            'changed_genes' => $changedGenes,
            'causal_influence' => $mutationIntent instanceof AgentLearningMutationIntent
                ? $mutationIntent->influence_type
                : 'unsealed_legacy_or_test',
            'causal_intent_id' => $mutationIntent instanceof AgentLearningMutationIntent ? (int) $mutationIntent->id : null,
            'integrity' => [
                'valid' => $integrityValid,
                'receipt_gene_matches_parameter_diff' => $geneMatch,
                'sealed_mutation_hash_matches' => $intentHashMatches,
                'causal_order_valid' => $causalOrderValid,
                'declared_gene' => $declaredGene,
                'actual_gene' => $changedGene,
            ],
            'expected_behavioral_delta' => data_get($metadata, 'hypothesis_contract.expected_behavioral_delta'),
            'falsification_condition' => data_get($metadata, 'hypothesis_contract.falsifiable_statement'),
            'non_target_invariants' => data_get($metadata, 'hypothesis_contract.non_target_invariants', []),
            'control_agent_id' => null,
            'dataset_hash' => (string) ($agent->generation?->data_fingerprint ?? data_get($agent->generation?->trigger_context, 'dataset_manifest.snapshot_sha256', '')),
            'execution_hash' => $this->executionHash((array) data_get($metadata, 'execution_contract', [])),
            'receipt_class' => $mutationIntent instanceof AgentLearningMutationIntent
                ? $mutationIntent->influence_type
                : 'evolution_child',
            'promotion_evidence' => false,
        ];
        if (! filled($intent['changed_gene']) && $intent['baseline']['explorer_root']) {
            $intent['receipt_class'] = 'explorer';
        }
        $intent['intent_hash'] = hash('sha256', json_encode($intent, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $metadata['learning_receipt'] = $intent;
        $model->update(['metadata' => $metadata]);
        // The receipt and mutation action are issued from the same immutable
        // child diff. The action ledger is research-only and does not alter
        // the chosen gene, parent, control or any promotion gate.
        $intent['mutation_brain_action'] = app(MutationBrainService::class)->issue($agent->fresh(['modelVersion']));

        return $intent;
    }

    /** @return array<string, mixed> */
    public function settle(LabAgent $agent, array $result, ?LabLearningLanePair $pair = null): array
    {
        $agent->loadMissing('modelVersion');
        $model = $agent->modelVersion;
        $receipt = (array) data_get($model?->metadata, 'learning_receipt', []);
        if (data_get($receipt, 'protocol') !== self::PROTOCOL) {
            return [];
        }

        $observability = (array) data_get($result, 'mutation_observability', data_get($model?->metadata, 'mutation_observability', []));
        $delta = data_get($pair?->target_delta, 'delta', data_get($observability, 'control_delta', data_get($observability, 'gate_margin.normalized_delta')));
        $hasNumericDelta = is_numeric($delta);
        $controlVerified = $pair?->isVerifiedControlPair() ?? false;
        $observable = (bool) data_get($observability, 'observable_effect', data_get($observability, 'classification') === 'observable_effect');
        $safe = ! (bool) data_get($observability, 'non_target_regression.failed', false)
            && (bool) data_get($observability, 'non_target_regression.safe', true);
        $observedTarget = (string) data_get($observability, 'observed_target_delta.dominant_target', data_get($observability, 'target', ''));
        $declaredTarget = (string) data_get($receipt, 'declared_target', 'profit_factor');
        $receiptValid = data_get($receipt, 'integrity.valid', false) === true;
        $targetDrift = $observedTarget !== '' && $observedTarget !== $declaredTarget;
        $status = match (true) {
            ! $receiptValid => 'invalid_intent',
            ! filled(data_get($result, 'evidence_run_id')) => 'technical_incomplete',
            ! $controlVerified => 'context_mismatch',
            ! $observable => 'no_effect',
            $targetDrift => 'context_mismatch',
            ! $safe || ($hasNumericDelta && (float) $delta < 0) => 'harmful',
            $hasNumericDelta && (float) $delta > 0 => 'provisional',
            default => 'context_mismatch',
        };
        $settlement = [
            'status' => $status,
            'settled_at' => now()->utc()->toIso8601String(),
            'evidence_run_id' => data_get($result, 'evidence_run_id'),
            'control_agent_id' => $pair?->control_agent_id,
            'dataset_hash' => $pair?->candidate_data_hash ?: data_get($result, 'data_manifest.sha256', data_get($receipt, 'dataset_hash')),
            'execution_hash' => $pair?->candidate_execution_hash ?: data_get($result, 'execution_contract.execution_hash', data_get($receipt, 'execution_hash')),
            'target_delta' => $hasNumericDelta ? (float) $delta : null,
            'declared_target' => $declaredTarget,
            'observed_target' => $observedTarget !== '' ? $observedTarget : $declaredTarget,
            'target_drift_diagnostic_only' => $targetDrift,
            'promotion_evidence' => false,
        ];
        $metadata = (array) $model->metadata;
        $metadata['learning_receipt'] = [...$receipt, 'status' => $status, 'settlement' => $settlement];
        $model->update(['metadata' => $metadata]);

        return $metadata['learning_receipt'];
    }

    /** @return array<int, int> */
    private function lessonIds(array $packet): array
    {
        return collect(['positive_lessons', 'harmful_lessons', 'uncertainty_lessons'])
            ->flatMap(fn (string $key) => (array) data_get($packet, $key, []))
            ->pluck('lesson_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    /** @return array<int, int> */
    private function selectedLessonIds(array $packet, ?string $gene): array
    {
        if (! filled($gene)) {
            return [];
        }

        return collect(['positive_lessons', 'harmful_lessons', 'uncertainty_lessons'])
            ->flatMap(fn (string $key) => (array) data_get($packet, $key, []))
            ->filter(fn (array $row): bool => (string) data_get($row, 'parameter_key') === $gene)
            ->pluck('lesson_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    private function hash(array $payload): string
    {
        return app(CausalLearningMutationIntentService::class)->mutationHash($payload);
    }

    private function executionHash(array $contract): string
    {
        return $contract === [] ? '' : hash('sha256', json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
