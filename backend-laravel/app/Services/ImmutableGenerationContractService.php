<?php

namespace App\Services;

use App\Models\LabGeneration;

/**
 * Seals the final population plan after every planner has finished.
 *
 * Planners may compose a draft in memory.  Once this contract is persisted,
 * dispatchers are validators only: changing a family, role, intervention,
 * control identity, context, or budget changes the canonical plan hash and
 * closes screening admission.
 */
class ImmutableGenerationContractService
{
    public const PROTOCOL = 'immutable_generation_contract_v1';

    /** @param array<int,array<string,mixed>> $plan @param array<string,mixed> $requirements */
    public function compile(LabGeneration $generation, array $plan, array $requirements = []): array
    {
        $plan = array_values($plan);
        $dataHash = (string) ($requirements['data_hash'] ?? $generation->data_fingerprint ?? '');
        $executionHash = (string) ($requirements['execution_hash'] ?? '');
        if ($plan === [] || $dataHash === '' || $executionHash === '') {
            throw new \RuntimeException('IMMUTABLE_GENERATION_CONTRACT_INPUTS_MISSING');
        }

        $seats = collect($plan)->map(function (array $spec, int $index) use ($generation, $dataHash, $executionHash): array {
            $specHash = $this->hash($spec);
            $identity = [
                'generation_id' => (int) $generation->id,
                'generation' => (int) $generation->generation,
                'seat' => $index + 1,
                'symbol' => strtoupper((string) $generation->laboratory?->symbol),
                'laboratory_timeframe' => strtoupper((string) $generation->laboratory?->timeframe),
                'family' => (string) ($spec['family'] ?? ''),
                'origin' => (string) ($spec['origin'] ?? ''),
                'target' => (string) ($spec['target'] ?? ''),
                'research_group' => (string) ($spec['research_group'] ?? ''),
                'group_seat' => (int) ($spec['group_seat'] ?? 0),
                'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
                // The complete canonical spec includes every nested control,
                // intervention, MTF, composition, parent-policy and instrument
                // directive even when a future planner adds another field.
                'spec_hash' => $specHash,
            ];

            return [
                ...$identity,
                'seat_identity_hash' => $this->hash($identity),
            ];
        })->all();
        $seatHashes = array_column($seats, 'seat_identity_hash');
        if (count($seatHashes) !== count(array_unique($seatHashes))) {
            throw new \RuntimeException('IMMUTABLE_GENERATION_SEAT_IDENTITY_COLLISION');
        }

        $boundedRequirements = [
            'expected_population' => count($plan),
            'normal_population' => (int) ($requirements['normal_population'] ?? 20),
            'control_pairing_required' => (bool) ($requirements['control_pairing_required'] ?? false),
            'causal_proof_required' => (bool) ($requirements['causal_proof_required'] ?? false),
            'mtf_roles' => array_values((array) ($requirements['mtf_roles'] ?? [])),
            'new_work_owner' => (string) ($requirements['new_work_owner'] ?? ResearchLoopArbiterService::class),
        ];
        $planHash = $this->hash($plan);
        $contractCore = [
            'protocol' => self::PROTOCOL,
            'state' => 'sealed',
            'generation_id' => (int) $generation->id,
            'generation' => (int) $generation->generation,
            'symbol' => strtoupper((string) $generation->laboratory?->symbol),
            'laboratory_timeframe' => strtoupper((string) $generation->laboratory?->timeframe),
            'data_hash' => $dataHash,
            'execution_hash' => $executionHash,
            'population_size' => count($plan),
            'plan_hash' => $planHash,
            'requirements' => $boundedRequirements,
            'requirements_hash' => $this->hash($boundedRequirements),
            'seats' => $seats,
            'post_seal_mutation_forbidden' => true,
            'promotion_evidence' => false,
        ];

        return [
            ...$contractCore,
            'contract_hash' => $this->hash($contractCore),
            'sealed_at' => now()->utc()->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function validate(LabGeneration $generation): array
    {
        $generation = $generation->fresh('laboratory');
        $context = (array) $generation->trigger_context;
        $plan = array_values((array) data_get($context, 'generation_plan', []));
        $contract = (array) data_get($context, 'immutable_generation_contract', []);
        if ($contract === []) {
            // Absence means a genuinely historical row. An explicitly
            // declared but unsealed contract is produced by the current
            // constructor while it is incomplete and must fail dispatch.
            $declared = array_key_exists('immutable_generation_contract', $context);

            return $this->result(! $declared,
                $declared ? ['IMMUTABLE_GENERATION_CONTRACT_NOT_SEALED'] : [],
                ['legacy_contract' => ! $declared]);
        }

        $reasons = [];
        if ((string) ($contract['protocol'] ?? '') !== self::PROTOCOL) {
            $reasons[] = 'IMMUTABLE_GENERATION_PROTOCOL_INVALID';
        }
        if ((int) ($contract['generation_id'] ?? 0) !== (int) $generation->id
            || (int) ($contract['generation'] ?? 0) !== (int) $generation->generation) {
            $reasons[] = 'IMMUTABLE_GENERATION_IDENTITY_MISMATCH';
        }
        if ((int) ($contract['population_size'] ?? 0) !== count($plan)
            || (int) $generation->population_size !== count($plan)) {
            $reasons[] = 'IMMUTABLE_GENERATION_POPULATION_MISMATCH';
        }
        if (! filled($contract['data_hash'] ?? null) || ! filled($contract['execution_hash'] ?? null)) {
            $reasons[] = 'IMMUTABLE_GENERATION_EVIDENCE_HASH_MISSING';
        }
        if (! hash_equals((string) ($contract['plan_hash'] ?? ''), $this->hash($plan))) {
            $reasons[] = 'IMMUTABLE_GENERATION_PLAN_HASH_MISMATCH';
        }
        $storedCore = $contract;
        unset($storedCore['contract_hash'], $storedCore['sealed_at']);
        if (! hash_equals((string) ($contract['contract_hash'] ?? ''), $this->hash($storedCore))) {
            $reasons[] = 'IMMUTABLE_GENERATION_CONTRACT_TAMPERED';
        }

        try {
            $expected = $this->compile($generation, $plan, [
                'data_hash' => (string) ($contract['data_hash'] ?? ''),
                'execution_hash' => (string) ($contract['execution_hash'] ?? ''),
                ...((array) ($contract['requirements'] ?? [])),
            ]);
        } catch (\Throwable) {
            return $this->result(false, [
                ...$reasons,
                'IMMUTABLE_GENERATION_CONTRACT_RECOMPILE_FAILED',
            ], [
                'legacy_contract' => false,
                'contract_hash' => $contract['contract_hash'] ?? null,
                'plan_hash' => $contract['plan_hash'] ?? null,
                'population_size' => count($plan),
            ]);
        }
        if (! hash_equals((string) ($contract['requirements_hash'] ?? ''), (string) $expected['requirements_hash'])) {
            $reasons[] = 'IMMUTABLE_GENERATION_REQUIREMENTS_MISMATCH';
        }
        if (! hash_equals((string) ($contract['contract_hash'] ?? ''), (string) $expected['contract_hash'])) {
            $reasons[] = 'IMMUTABLE_GENERATION_CONTRACT_HASH_MISMATCH';
        }
        if ((array) ($contract['seats'] ?? []) !== (array) $expected['seats']) {
            $reasons[] = 'IMMUTABLE_GENERATION_SEAT_IDENTITY_MISMATCH';
        }

        return $this->result($reasons === [], $reasons, [
            'legacy_contract' => false,
            'contract_hash' => $contract['contract_hash'] ?? null,
            'plan_hash' => $contract['plan_hash'] ?? null,
            'population_size' => count($plan),
        ]);
    }

    /** @param list<string> $reasons @param array<string,mixed> $extra */
    private function result(bool $valid, array $reasons, array $extra = []): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'valid' => $valid,
            'reason_codes' => array_values(array_unique($reasons)),
            ...$extra,
            'promotion_evidence' => false,
        ];
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
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
