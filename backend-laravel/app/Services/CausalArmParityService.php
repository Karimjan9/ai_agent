<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\LabAgent;

/**
 * Scientific trust boundary for a guided/blinded/frozen-control triplet.
 *
 * ExactCausalBaselineService proves parameter identity.  This service proves
 * that the rest of the executable organism is the same.  Role labels and the
 * pre-registered one-gene selector are experiment dimensions and are removed
 * from the core-composition hash; instruments, activation scope, temporal
 * roles and the executable typed program are not.
 */
class CausalArmParityService
{
    public const PROTOCOL = 'causal_arm_parity_v2';

    public function __construct(
        private ExactCausalBaselineService $baselines,
        private LabInstrumentResearchService $instruments,
    ) {}

    /** @return array<string,mixed> */
    public function assess(AgentLearningCausalExperiment $experiment): array
    {
        $experiment->loadMissing('generation');
        $agents = LabAgent::query()->whereIn('id', array_filter([
            $experiment->guided_agent_id,
            $experiment->blinded_agent_id,
            $experiment->control_agent_id,
        ]))->with(['modelVersion', 'generation'])->get()->keyBy('id');
        $guided = $agents->get($experiment->guided_agent_id);
        $blinded = $agents->get($experiment->blinded_agent_id);
        $control = $agents->get($experiment->control_agent_id);
        $reasons = [];
        if (! $guided || ! $blinded || ! $control) {
            $reasons[] = 'CAUSAL_ARM_PARITY_AGENT_MISSING';

            return $this->result($experiment, $reasons, []);
        }

        if (! $this->baselines->matches($guided, $control)) {
            $reasons[] = 'GUIDED_EXACT_PARAMETER_BASELINE_MISMATCH';
        }
        if (! $this->baselines->matches($blinded, $control)) {
            $reasons[] = 'BLINDED_EXACT_PARAMETER_BASELINE_MISMATCH';
        }

        $arms = collect([
            'guided' => $guided,
            'blinded' => $blinded,
            'control' => $control,
        ])->map(function (LabAgent $agent): array {
            $assignment = $this->instruments->assignment($agent);
            $metadata = (array) $agent->modelVersion?->metadata;

            return [
                'agent_id' => (int) $agent->id,
                'dataset_hash' => (string) ($agent->generation?->data_fingerprint ?? ''),
                'base_strategy' => (string) data_get($metadata, 'base_strategy', $agent->strategy_family),
                'composition_core_hash' => $this->hash($this->compositionCore(
                    (array) data_get($metadata, 'smart_composition.composition_passport', []),
                )),
                'instrument_key_role_hash' => (string) data_get($assignment, 'instrument_key_role_hash', ''),
                'activation_context_hash' => (string) data_get($assignment, 'activation_context_hash', ''),
                'temporal_roles_hash' => $this->hash((array) data_get($assignment, 'temporal_roles', [])),
                'instrument_assignment_status' => (string) data_get($assignment, 'status', ''),
                'sealed_treatment_gene' => (string) data_get($assignment, 'sealed_treatment_gene', ''),
                'session_scope_hash' => $this->hash($this->sessionScope($metadata, $assignment)),
            ];
        })->all();

        foreach (['dataset_hash', 'base_strategy', 'composition_core_hash', 'instrument_key_role_hash',
            'activation_context_hash', 'temporal_roles_hash', 'sealed_treatment_gene', 'session_scope_hash'] as $field) {
            $values = collect($arms)->pluck($field)->map(fn (mixed $value): string => (string) $value)->unique()->values();
            if ($values->count() !== 1 || (string) $values->first() === '') {
                $reasons[] = 'CAUSAL_ARM_'.strtoupper($field).'_MISMATCH';
            }
        }
        if (collect($arms)->contains(fn (array $arm): bool => $arm['instrument_assignment_status'] !== 'assigned')) {
            $reasons[] = 'CAUSAL_ARM_INSTRUMENT_ASSIGNMENT_NOT_READY';
        }
        if ((string) $experiment->gene_key === ''
            || collect($arms)->contains(fn (array $arm): bool => $arm['sealed_treatment_gene'] !== (string) $experiment->gene_key)) {
            $reasons[] = 'CAUSAL_ARM_DECLARED_TREATMENT_MISMATCH';
        }

        return $this->result($experiment, array_values(array_unique($reasons)), $arms);
    }

    /** @return array<string,mixed> */
    private function result(AgentLearningCausalExperiment $experiment, array $reasons, array $arms): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'status' => $reasons === [] ? 'passed' : 'failed',
            'passed' => $reasons === [],
            'causal_experiment_id' => (int) $experiment->id,
            'declared_treatment' => ['gene' => (string) $experiment->gene_key],
            'reason_codes' => $reasons,
            'arms' => $arms,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function compositionCore(array $passport): array
    {
        if ($passport === []) {
            return ['status' => 'missing'];
        }
        foreach ([
            'composition_id', 'learning_experiment', 'learning_directive',
            'learning_receipt_ids', 'consumed_learning_receipt_ids',
        ] as $field) {
            unset($passport[$field]);
        }

        return $passport;
    }

    /** @return array<string,mixed> */
    private function sessionScope(array $metadata, array $assignment): array
    {
        return [
            'specialist_context' => data_get($metadata, 'specialist_council_membership.contextual_cell'),
            'activation_contexts' => collect((array) data_get($assignment, 'selected', []))
                ->mapWithKeys(fn (array $item): array => [
                    (string) ($item['instrument_key'] ?? '') => data_get($item, 'activation_contract.context.declared_context'),
                ])->all(),
        ];
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
