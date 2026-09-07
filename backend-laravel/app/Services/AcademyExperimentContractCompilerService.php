<?php

namespace App\Services;

/**
 * Converts Academy planner operators into the exact runtime parameter map.
 * Planner-only values are never silently added to the Python schema.
 */
class AcademyExperimentContractCompilerService
{
    public const PROTOCOL = 'academy_experiment_contract_compiler_v1';

    public function __construct(private StrategyParameterSchemaService $schemas) {}

    /** @return array<string,mixed> */
    public function compile(array $trial, array $baselineParameters, array $identity): array
    {
        $axis = (string) ($trial['axis'] ?? '');
        $arms = (array) ($trial['arms'] ?? []);
        if ($axis === '' || $arms === []) return $this->blocked('ACADEMY_TRIAL_AXIS_AND_ARMS_REQUIRED');
        if (($identity['symbol'] ?? null) !== 'XAUUSD' || ($identity['laboratory_timeframe'] ?? null) !== 'H1'
            || ($identity['execution_timeframe'] ?? null) !== 'M5') return $this->blocked('XAUUSD_H1_M5_ACADEMY_SCOPE_REQUIRED');
        if (! array_key_exists($axis, $baselineParameters)) return $this->blocked('ACADEMY_BASELINE_AXIS_VALUE_REQUIRED');
        $compiled = [];
        foreach ($arms as $arm) {
            $role = (string) ($arm['role'] ?? '');
            $value = $arm['value'] ?? null;
            if (! in_array($role, ['frozen_control', 'candidate', 'blinded_control', 'ablation', 'counterfactual'], true)) return $this->blocked('EXPLICIT_VALID_ACADEMY_ARM_ROLE_REQUIRED');
            $resolved = $this->resolve($axis, $value, $baselineParameters[$axis], $role);
            if (($resolved['ok'] ?? false) !== true) return $this->blocked((string) $resolved['reason']);
            $parameters = [...$baselineParameters, $axis => $resolved['value']];
            try { $parameters = $this->schemas->validate('confirmation_entry_mtf', $parameters); }
            catch (\Throwable $error) { return $this->blocked('ACADEMY_RUNTIME_SCHEMA_REJECTED:'.$error->getMessage()); }
            $compiled[] = ['role' => $role, 'planner_value' => $value, 'runtime_value' => $resolved['value'],
                'runtime_parameters' => $parameters, 'parameter_hash' => $this->hash($parameters),
                'control_identity' => in_array($role, ['frozen_control', 'blinded_control'], true),
                'planner_operator_resolved' => $resolved['operator'] ?? null];
        }
        return ['protocol' => self::PROTOCOL, 'status' => 'compiled',
            'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'scope' => $identity, 'axis' => $axis, 'arms' => $compiled,
            'baseline_parameter_hash' => $this->hash($baselineParameters),
            'promotion_evidence' => false];
    }

    private function resolve(string $axis, mixed $value, mixed $baseline, string $role): array
    {
        if (! is_string($value) || $value === '') return ['ok' => false, 'reason' => 'ACADEMY_ARM_VALUE_REQUIRED'];
        // Controls describe experimental role, not a new executable policy.
        if ($value === 'frozen_current' || str_ends_with($value, '_blinded_control')) {
            return ['ok' => true, 'value' => $baseline, 'operator' => $value];
        }
        // These old planner labels have no exact executable equivalent. A
        // plausible substitution would falsify the registered hypothesis.
        if (in_array($value, ['reaction_plus_participation', 'state_adaptive'], true)) {
            return ['ok' => false, 'reason' => 'ACADEMY_PLANNER_OPERATOR_REQUIRES_EXPLICIT_RUNTIME_SEMANTICS'];
        }
        return ['ok' => true, 'value' => $value, 'operator' => null];
    }

    private function blocked(string $reason): array { return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => $reason, 'promotion_evidence' => false]; }
    private function hash(array $value): string { ksort($value); return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)); }
}
