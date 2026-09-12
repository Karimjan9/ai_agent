<?php

namespace App\Services;

use App\Models\LabAgent;

/**
 * Single trust boundary for one-candidate/one-control parameter identity.
 * Hash parity is checked elsewhere; this service proves that the control is
 * the candidate's exact untouched vector and genetic source.
 */
class ExactCausalBaselineService
{
    public const PROTOCOL = 'exact_causal_parameter_baseline_v1';

    public function matches(LabAgent $candidate, LabAgent $control): bool
    {
        $candidate->loadMissing('modelVersion');
        $control->loadMissing('modelVersion');
        if (! $candidate->modelVersion || ! $control->modelVersion
            || (int) $candidate->id === (int) $control->id
            || (int) $candidate->lab_generation_id !== (int) $control->lab_generation_id
            || strtoupper((string) $candidate->symbol) !== strtoupper((string) $control->symbol)
            || strtoupper((string) $candidate->timeframe) !== strtoupper((string) $control->timeframe)
            || (string) $candidate->strategy_family !== (string) $control->strategy_family
            || $this->parentSources($candidate) !== $this->parentSources($control)) {
            return false;
        }

        $diff = (array) $candidate->parameter_diff;
        if (count($diff) !== 1 || (array) $control->parameter_diff !== []) {
            return false;
        }
        $gene = (string) array_key_first($diff);
        $change = (array) ($diff[$gene] ?? []);
        $candidateParameters = (array) $candidate->modelVersion->parameters;
        $controlParameters = (array) $control->modelVersion->parameters;
        if ($gene === ''
            || ! array_key_exists($gene, $candidateParameters)
            || ! array_key_exists($gene, $controlParameters)
            || ! $this->sameValue(data_get($change, 'old'), $controlParameters[$gene])
            || ! $this->sameValue(data_get($change, 'new'), $candidateParameters[$gene])) {
            return false;
        }

        $candidateParameters[$gene] = $controlParameters[$gene];
        if (! $this->sameVector($candidateParameters, $controlParameters)) {
            return false;
        }

        $candidatePair = (array) data_get($candidate->modelVersion->metadata, 'control_pair_contract', []);
        $controlPair = (array) data_get($control->modelVersion->metadata, 'control_pair_contract', []);
        $candidatePairKey = (string) data_get($candidatePair, 'pair_key', '');
        $controlPairKey = (string) data_get($controlPair, 'pair_key', '');
        if ($candidatePairKey !== '' || $controlPairKey !== '') {
            if ($candidatePairKey === '' || $controlPairKey === ''
                || ! hash_equals($candidatePairKey, $controlPairKey)
                || (string) data_get($candidatePair, 'role', '') !== 'candidate'
                || (string) data_get($controlPair, 'role', '') !== 'control') {
                return false;
            }
        }

        return true;
    }

    /** @return array{0:?int,1:?int} */
    private function parentSources(LabAgent $agent): array
    {
        return [
            (int) $agent->parent_a_model_version_id > 0 ? (int) $agent->parent_a_model_version_id : null,
            (int) $agent->parent_b_model_version_id > 0 ? (int) $agent->parent_b_model_version_id : null,
        ];
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameVector(array $left, array $right): bool
    {
        ksort($left);
        ksort($right);
        if (array_keys($left) !== array_keys($right)) {
            return false;
        }

        foreach ($left as $key => $value) {
            if (! $this->sameValue($value, $right[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function sameValue(mixed $left, mixed $right): bool
    {
        if (! is_bool($left) && ! is_bool($right) && is_numeric($left) && is_numeric($right)) {
            return abs((float) $left - (float) $right) < 0.000000001;
        }

        return json_encode($left, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
            === json_encode($right, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
