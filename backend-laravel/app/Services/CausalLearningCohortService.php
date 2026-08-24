<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningMutationIntent;
use App\Models\LabAgent;
use Illuminate\Support\Facades\Schema;

/** Binds constructed agents to the pre-registered counterfactual triplet. */
class CausalLearningCohortService
{
    /** @return array<string, mixed> */
    public function enroll(LabAgent $agent, AgentLearningMutationIntent|array $intent, ?array $niche): array
    {
        $contract = (array) data_get($niche, 'causal_learning_cohort', []);
        if ($contract === [] || ! Schema::hasTable('agent_learning_causal_experiments')) {
            return ['status' => 'not_applicable', 'promotion_evidence' => false];
        }
        $role = (string) data_get($contract, 'role');
        $field = match ($role) {
            'memory_guided' => 'guided_agent_id',
            'blinded' => 'blinded_agent_id',
            'frozen_control' => 'control_agent_id',
            default => null,
        };
        if ($field === null) {
            return ['status' => 'invalid_role', 'promotion_evidence' => false];
        }
        $experiment = AgentLearningCausalExperiment::query()->firstOrCreate(
            ['experiment_key' => (string) data_get($contract, 'experiment_key')],
            [
                'lab_generation_id' => $agent->lab_generation_id,
                'symbol' => $agent->symbol,
                'timeframe' => $agent->timeframe,
                'strategy_family' => $agent->strategy_family,
                'target' => data_get($agent->modelVersion?->metadata, 'generation_target'),
                'gene_key' => (string) data_get($contract, 'gene'),
                'source_lesson_id' => data_get($contract, 'source_lesson_id'),
                'status' => 'awaiting_counterfactuals',
                'evidence' => ['protocol' => CausalLearningCohortPlannerService::PROTOCOL, 'roles' => [], 'promotion_evidence' => false],
            ],
        );
        $evidence = (array) $experiment->evidence;
        $evidence['roles'][$role] = [
            'agent_id' => (int) $agent->id,
            'model_version_id' => (int) $agent->model_version_id,
            'intent_id' => $intent instanceof AgentLearningMutationIntent ? (int) $intent->id : null,
            'influence_type' => $intent instanceof AgentLearningMutationIntent ? $intent->influence_type : 'unavailable',
            'parent_a_model_version_id' => $agent->parent_a_model_version_id,
            'parameter_diff_hash' => $this->hash((array) $agent->parameter_diff),
            'dataset_hash' => $agent->generation?->data_fingerprint,
        ];
        $experiment->update([$field => $agent->id, 'evidence' => $evidence]);
        $experiment = $experiment->fresh();
        $status = $this->validate($experiment);
        $experiment->update(['status' => $status['status'], 'evidence' => [
            ...((array) $experiment->evidence),
            'construction_validation' => $status,
            'promotion_evidence' => false,
        ]]);
        $metadata = (array) $agent->modelVersion?->metadata;
        if ($agent->modelVersion) {
            $metadata['causal_learning_cohort'] = [
                'protocol' => CausalLearningCohortPlannerService::PROTOCOL,
                'experiment_id' => (int) $experiment->id,
                'experiment_key' => $experiment->experiment_key,
                'role' => $role,
                'status' => $status['status'],
                'promotion_evidence' => false,
            ];
            $agent->modelVersion->update(['metadata' => $metadata]);
        }

        return ['experiment_id' => (int) $experiment->id, 'role' => $role, ...$status];
    }

    /** @return array<string, mixed> */
    private function validate(AgentLearningCausalExperiment $experiment): array
    {
        if (! $experiment->guided_agent_id || ! $experiment->blinded_agent_id || ! $experiment->control_agent_id) {
            return ['status' => 'awaiting_counterfactuals', 'promotion_evidence' => false];
        }
        $agents = LabAgent::query()->whereIn('id', [
            $experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id,
        ])->with('modelVersion')->get()->keyBy('id');
        $guided = $agents->get($experiment->guided_agent_id);
        $blinded = $agents->get($experiment->blinded_agent_id);
        $control = $agents->get($experiment->control_agent_id);
        $guidedIntent = AgentLearningMutationIntent::query()->where('lab_agent_id', $guided?->id)->first();
        $blindedIntent = AgentLearningMutationIntent::query()->where('lab_agent_id', $blinded?->id)->first();
        $reasons = [];
        if (! $guided || ! $blinded || ! $control) {
            $reasons[] = 'COHORT_AGENT_MISSING';
        }
        if ($guidedIntent?->influence_type !== 'memory_guided'
            || ! in_array((int) $experiment->source_lesson_id, (array) $guidedIntent?->causally_applied_lesson_ids, true)) {
            $reasons[] = 'GUIDED_CAUSAL_MEMORY_MISSING';
        }
        if ($blindedIntent?->influence_type !== 'blinded_counterfactual') {
            $reasons[] = 'BLINDED_INTENT_INVALID';
        }
        if ((array) $control?->parameter_diff !== []) {
            $reasons[] = 'CONTROL_NOT_FROZEN';
        }
        if ($this->hash((array) $guided?->parameter_diff) !== $this->hash((array) $blinded?->parameter_diff)) {
            $reasons[] = 'GUIDED_BLINDED_MUTATION_MISMATCH';
        }
        if ($guided?->parent_a_model_version_id !== $blinded?->parent_a_model_version_id
            || $guided?->parent_a_model_version_id !== $control?->parent_a_model_version_id) {
            $reasons[] = 'PARENT_MISMATCH';
        }

        return [
            'status' => $reasons === [] ? 'ready_for_replay' : 'invalid_counterfactual_contract',
            'reason_codes' => $reasons,
            'promotion_evidence' => false,
        ];
    }

    private function hash(array $payload): string
    {
        ksort($payload);

        return hash('sha512', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
