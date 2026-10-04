<?php

namespace App\Services;

use App\Models\LabGeneration;
use Illuminate\Support\Collection;

/** Proves that the four executed genomes represent C, A, B and A+B. */
class ActivationFactorialContractService
{
    public const PROTOCOL = 'activation_factorial_identity_v1';

    public function __construct(private StrategyParameterSchemaService $schemas) {}

    /** @return array<int,string> */
    public function reasons(LabGeneration $generation): array
    {
        $generation->loadMissing('agents.modelVersion');
        $groups = $generation->agents->filter(fn ($agent): bool =>
            data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block.block_type') === 'activation_factorial'
        )->groupBy(fn ($agent): string => (string) data_get($agent->modelVersion?->metadata,
            'cooperative_experiment_block.block_key', ''));
        $reasons = [];
        $plannedGroups = collect((array) data_get($generation->trigger_context, 'generation_plan', []))
            ->filter(fn (mixed $slot): bool => is_array($slot)
                && data_get($slot, 'niche.cooperative_experiment_block.block_type') === 'activation_factorial')
            ->groupBy(fn (array $slot): string => (string) data_get($slot,
                'niche.cooperative_experiment_block.block_key', ''));
        foreach ($plannedGroups as $plannedKey => $plannedArms) {
            if ($plannedKey === '' || $plannedArms->count() !== 4 || ! $groups->has($plannedKey)) {
                $reasons[] = 'ACTIVATION_FACTORIAL_PLANNED_BLOCK_MISSING';
            }
        }
        foreach ($groups as $blockKey => $agents) {
            if ($blockKey === '') {
                $reasons[] = 'ACTIVATION_FACTORIAL_BLOCK_KEY_MISSING';
                continue;
            }
            $reasons = [...$reasons, ...$this->blockReasons($agents)];
        }

        return array_values(array_unique($reasons));
    }

    /** @return array<int,string> */
    public function blockReasons(Collection $agents): array
    {
        $byArm = $agents->keyBy(fn ($agent): string => (string) data_get($agent->modelVersion?->metadata,
            'cooperative_experiment_block.arm', ''));
        $required = ['control', 'a_only', 'b_only', 'a_plus_b'];
        if ($agents->count() !== 4 || $byArm->count() !== 4
            || collect($required)->contains(fn (string $arm): bool => ! $byArm->has($arm))) {
            return ['ACTIVATION_FACTORIAL_FOUR_ARMS_REQUIRED'];
        }
        $first = $byArm->get('control');
        $block = (array) data_get($first->modelVersion?->metadata, 'cooperative_experiment_block', []);
        $a = (array) data_get($block, 'factor_a', []);
        $b = (array) data_get($block, 'factor_b', []);
        $aGene = (string) ($a['gene'] ?? '');
        $bGene = (string) ($b['gene'] ?? '');
        $manifest = (string) data_get($block, 'activation_manifest_hash', '');
        $activation = (array) data_get($first->modelVersion?->metadata, 'activation_factorial', []);
        $validationPlan = (array) data_get($activation, 'validation_plan', []);
        $components = (array) data_get($first->modelVersion?->metadata,
            'smart_composition.composition_passport.components', []);
        $reasons = [];
        $expectedManifest = hash('sha256', json_encode([
            ProofFrontierService::PROTOCOL, (string) data_get($block, 'block_key'),
            data_get($activation, 'source_agent_id'), data_get($activation, 'source_response_hash'),
            data_get($activation, 'source_model_version_id'), data_get($activation, 'source_parameter_hash'),
            data_get($activation, 'source_data_hash'), data_get($activation, 'hypothesis_key'),
            data_get($validationPlan, 'plan_hash'),
            $components, $a, $b, ProofFrontierService::MIN_PAIRED_OPPORTUNITIES,
            ProofFrontierService::MIN_SIGNAL_OPPORTUNITIES,
            data_get($first->modelVersion?->metadata, 'specialist_council_membership.contextual_cell.cell_hash'),
        ], JSON_UNESCAPED_SLASHES));
        if (strlen($manifest) !== 64 || ! hash_equals($expectedManifest, $manifest)
            || strlen((string) data_get($activation, 'hypothesis_key', '')) !== 64
            || (int) data_get($activation, 'minimum_paired_opportunities') !== ProofFrontierService::MIN_PAIRED_OPPORTUNITIES
            || (int) data_get($activation, 'minimum_signal_opportunities') !== ProofFrontierService::MIN_SIGNAL_OPPORTUNITIES
            || $aGene === '' || $bGene === '' || $aGene === $bGene || $components === []) {
            $reasons[] = 'ACTIVATION_FACTORIAL_MANIFEST_INVALID';
        }
        if (! app(ActivationValidationPlanService::class)->valid($validationPlan, [
            'hypothesis_key' => data_get($activation, 'hypothesis_key'),
            'source_data_hash' => data_get($activation, 'source_data_hash'),
            'source_response_hash' => data_get($activation, 'source_response_hash'),
            'source_execution_hash' => data_get($activation, 'source_execution_hash'),
            'source_mtf_bundle_hash' => data_get($activation, 'source_mtf_bundle_hash'),
        ])) {
            $reasons[] = 'ACTIVATION_VALIDATION_PLAN_INVALID';
        }
        $executableHashes = [];
        foreach ($required as $arm) {
            $agent = $byArm->get($arm);
            $actual = (array) data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block', []);
            $armActivation = (array) data_get($agent->modelVersion?->metadata, 'activation_factorial', []);
            $cellHash = (string) data_get($agent->modelVersion?->metadata,
                'specialist_council_membership.contextual_cell.cell_hash', '');
            $expectedExecutableHash = hash('sha256', json_encode([
                ProofFrontierService::PROTOCOL, (string) $agent->strategy_family,
                $this->schemas->canonicalizeForIdentity((string) $agent->strategy_family,
                    (array) $agent->modelVersion?->parameters), $components, $cellHash,
            ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            $executableHashes[] = $expectedExecutableHash;
            if ((string) data_get($actual, 'activation_manifest_hash') !== $manifest
                || (array) data_get($actual, 'factor_a', []) !== $a
                || (array) data_get($actual, 'factor_b', []) !== $b
                || (string) data_get($armActivation, 'hypothesis_key') !== (string) data_get($activation, 'hypothesis_key')
                || (array) data_get($armActivation, 'validation_plan', []) !== $validationPlan
                || (string) data_get($armActivation, 'executable_hash') !== $expectedExecutableHash
                || $cellHash === ''
                || (array) data_get($agent->modelVersion?->metadata,
                    'smart_composition.composition_passport.components', []) !== $components
                || (string) $agent->strategy_family !== (string) $first->strategy_family) {
                $reasons[] = 'ACTIVATION_FACTORIAL_ARM_IDENTITY_MISMATCH';
            }
        }
        if (count(array_unique($executableHashes)) !== 4) {
            $reasons[] = 'ACTIVATION_FACTORIAL_EXECUTABLE_HASHES_NOT_DISTINCT';
        }
        if ($reasons !== []) {
            return array_values(array_unique($reasons));
        }
        $control = (array) $byArm->get('control')->modelVersion->parameters;
        $aOnly = (array) $byArm->get('a_only')->modelVersion->parameters;
        $bOnly = (array) $byArm->get('b_only')->modelVersion->parameters;
        $ab = (array) $byArm->get('a_plus_b')->modelVersion->parameters;
        $aDiff = $this->diff($control, $aOnly);
        $bDiff = $this->diff($control, $bOnly);
        $abDiff = $this->diff($control, $ab);
        $expectedAB = $control;
        $expectedAB[$aGene] = $aOnly[$aGene] ?? null;
        $expectedAB[$bGene] = $bOnly[$bGene] ?? null;
        $sourceParameterHash = hash('sha256', json_encode(
            $this->schemas->canonicalizeForIdentity((string) $first->strategy_family, $control),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
        if ((int) data_get($first->modelVersion?->metadata,
            'activation_factorial_baseline_model_version_id', 0)
                !== (int) data_get($activation, 'source_model_version_id', 0)
            || ! hash_equals((string) data_get($activation, 'source_parameter_hash', ''), $sourceParameterHash)) {
            $reasons[] = 'ACTIVATION_FACTORIAL_SOURCE_BASELINE_MISMATCH';
        }
        if (array_keys($aDiff) !== [$aGene] || array_keys($bDiff) !== [$bGene]
            || count($abDiff) !== 2
            || $this->diff($expectedAB, $ab) !== []
            || json_encode($aOnly[$aGene] ?? null, JSON_PRESERVE_ZERO_FRACTION)
                !== json_encode($a['value'] ?? null, JSON_PRESERVE_ZERO_FRACTION)
            || json_encode($bOnly[$bGene] ?? null, JSON_PRESERVE_ZERO_FRACTION)
                !== json_encode($b['value'] ?? null, JSON_PRESERVE_ZERO_FRACTION)) {
            $reasons[] = 'ACTIVATION_FACTORIAL_EXECUTABLE_DELTAS_MISMATCH';
        }
        if ((int) data_get($byArm->get('a_only')->modelVersion?->metadata,
            'causal_baseline_model_version_id', 0) !== (int) $byArm->get('control')->model_version_id
            || (int) data_get($byArm->get('b_only')->modelVersion?->metadata,
                'activation_factorial_baseline_model_version_id', 0) !== (int) $byArm->get('control')->model_version_id
            || (int) data_get($byArm->get('a_plus_b')->modelVersion?->metadata,
                'causal_baseline_model_version_id', 0) !== (int) $byArm->get('b_only')->model_version_id) {
            $reasons[] = 'ACTIVATION_FACTORIAL_BASELINE_CHAIN_MISMATCH';
        }

        return $reasons;
    }

    /** @return array<string,array{old:mixed,new:mixed}> */
    private function diff(array $base, array $other): array
    {
        $keys = array_values(array_unique([...array_keys($base), ...array_keys($other)]));
        sort($keys);
        $diff = [];
        foreach ($keys as $key) {
            if (json_encode($base[$key] ?? null, JSON_PRESERVE_ZERO_FRACTION)
                !== json_encode($other[$key] ?? null, JSON_PRESERVE_ZERO_FRACTION)) {
                $diff[$key] = ['old' => $base[$key] ?? null, 'new' => $other[$key] ?? null];
            }
        }

        return $diff;
    }
}
