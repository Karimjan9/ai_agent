<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;

/**
 * Builds and verifies the smallest object that may cross a generation.
 *
 * A profitable model is not itself a causal inheritance claim.  The claim is
 * the intervention, the exact instrument bundle that interpreted it, and the
 * bounded market context in which the pair observed it.  This service has no
 * database of its own: CanonicalSkillCartridge remains the durable registry
 * and EvolutionaryAuthorityFoundry remains the authority state machine.
 */
class ContextualCausalTraitCapsuleService
{
    public const PROTOCOL = 'contextual_causal_trait_capsule_v2';

    public const HASH_PROTOCOL = 'numeric_canonical_json_v1';

    public function __construct(private ContextContractV2Service $contexts) {}

    /** @return array<string,mixed> */
    public function compile(
        LabLearningLanePair $pair,
        LabAgent $agent,
        LabMutationResponseMap $map,
        array $result,
        array $context,
        array $intervention,
        array $effect,
        array $nonTargetEffects,
        array $provenance,
        string $componentStatus,
        int $revision,
    ): array {
        $contextContract = $this->contexts->project($context);
        $activation = [
            'protocol' => ContextContractV2Service::PROTOCOL,
            'status' => data_get($contextContract, 'status'),
            'predicate' => array_filter(
                (array) data_get($contextContract, 'extended_axes', []),
                static fn ($value): bool => $value !== null && $value !== '',
            ),
            'context_hash' => (string) data_get($contextContract, 'identity_hash', ''),
        ];
        $instrument = $this->instrumentBinding($agent, $result, $activation);
        $baselineAgent = $pair->controlAgent;
        $core = [
            'protocol' => self::PROTOCOL,
            'hash_protocol' => self::HASH_PROTOCOL,
            'trait' => [
                'gene' => (string) $map->parameter_key,
                'old_value' => data_get($intervention, 'old_value'),
                'tested_value' => data_get($intervention, 'tested_value'),
                'direction' => data_get($intervention, 'direction'),
                'executable' => data_get($intervention, 'old_value') !== null
                    && data_get($intervention, 'tested_value') !== null
                    && json_encode(data_get($intervention, 'old_value'), JSON_PRESERVE_ZERO_FRACTION)
                        !== json_encode(data_get($intervention, 'tested_value'), JSON_PRESERVE_ZERO_FRACTION),
            ],
            'instrument_bundle' => $instrument,
            'activation_context' => $activation,
            'scope' => [
                'symbol' => strtoupper((string) $pair->symbol),
                'timeframe' => strtoupper((string) $pair->timeframe),
                'strategy_family' => (string) $pair->strategy_family,
            ],
            'frozen_dependencies' => [
                'pair_id' => (int) $pair->id,
                'causal_baseline_agent_id' => (int) $pair->control_agent_id,
                'causal_baseline_model_version_id' => (int) ($baselineAgent?->model_version_id ?? 0),
                'source_agent_id' => (int) $agent->id,
                'source_model_version_id' => (int) $agent->model_version_id,
                'data_hash' => (string) $pair->candidate_data_hash,
                'execution_hash' => (string) $pair->candidate_execution_hash,
            ],
        ];
        $checks = $this->coreChecks($core);
        $status = in_array(false, $checks, true) ? 'incomplete' : 'sealed';
        $capsuleHash = $this->hash($core);

        return [
            ...$core,
            'status' => $status,
            'capsule_hash' => $capsuleHash,
            'target_effect_vector' => $effect,
            'non_target_effect_vector' => $nonTargetEffects,
            'contraindicated_contexts' => [],
            'support' => [
                'revision' => $revision,
                'component_status' => $componentStatus,
                'receipt_ids' => array_values(array_unique(array_filter(array_map(
                    'intval',
                    (array) data_get($provenance, 'receipt_ids', []),
                )))),
                'response_map_ids' => array_values(array_unique(array_filter(array_map(
                    'intval',
                    (array) data_get($provenance, 'response_map_ids', []),
                )))),
                'independent_windows' => (int) data_get($effect, 'total_windows', 0),
            ],
            'authority_level' => $componentStatus === 'component_confirmed'
                ? 'causally_confirmed_component'
                : 'research_inbox',
            'expiry' => [
                'policy' => 'revalidate_on_data_execution_drift_or_context_contraindication',
                'drift_epoch' => data_get($result, 'market_drift.epoch', data_get($result, 'drift_epoch')),
            ],
            'checks' => $checks,
            'promotion_evidence' => false,
        ];
    }

    /**
     * Re-derive capsule validity.  Persisted labels and hashes are evidence,
     * never authority by themselves.
     *
     * @return array<string,mixed>
     */
    public function assess(array $capsule, ?string $expectedGene = null, array $requestedContext = []): array
    {
        $core = $this->core($capsule);
        $checks = $this->coreChecks($core);
        $checks['declared_protocol'] = data_get($capsule, 'protocol') === self::PROTOCOL;
        $checks['declared_sealed'] = data_get($capsule, 'status') === 'sealed';
        $checks['capsule_hash_valid'] = filled(data_get($capsule, 'capsule_hash'))
            && hash_equals((string) data_get($capsule, 'capsule_hash'), $this->hash($core));
        $checks['gene_matches'] = $expectedGene === null || $expectedGene === ''
            || (string) data_get($core, 'trait.gene') === $expectedGene;
        $checks['component_confirmed'] = data_get($capsule, 'support.component_status') === 'component_confirmed'
            && data_get($capsule, 'authority_level') === 'causally_confirmed_component';
        $contextAssessment = $this->contextCompatibility($capsule, $requestedContext);
        $checks['requested_context_compatible'] = $requestedContext === []
            || (bool) data_get($contextAssessment, 'compatible', false);
        $valid = ! in_array(false, $checks, true);

        return [
            'protocol' => self::PROTOCOL,
            'valid' => $valid,
            'status' => $valid ? 'valid' : 'invalid',
            'reason_codes' => array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)),
            'checks' => $checks,
            'context_compatibility' => $contextAssessment,
            'capsule_hash' => data_get($capsule, 'capsule_hash'),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function contextCompatibility(array $capsule, array $requestedContext): array
    {
        if ($requestedContext === []) {
            return ['compatible' => true, 'status' => 'not_requested', 'reason_code' => null];
        }
        $requested = $this->contexts->project($requestedContext);
        $stored = (array) data_get($capsule, 'activation_context.predicate', []);
        $requestedAxes = (array) data_get($requested, 'extended_axes', []);
        $mismatches = [];
        $deferredRuntimeAxes = [];
        foreach ($stored as $axis => $value) {
            $observed = $requestedAxes[$axis] ?? null;
            if ($observed === null || $observed === '') {
                // Generation plans own the broad MAP-Elites routing cell.
                // Session/transition/liquidity/direction can be unknown at
                // construction time, but they are never discarded: the full
                // predicate is sealed into the child and must be evaluated by
                // the runtime council. Regime and volatility are the minimum
                // safe routing coordinates for selecting this parent at all.
                if (in_array($axis, ['regime', 'volatility'], true)) {
                    $mismatches[] = 'missing_routing_'.$axis;
                } else {
                    $deferredRuntimeAxes[] = $axis;
                }
            } elseif ((string) $observed !== (string) $value) {
                $mismatches[] = 'mismatch_'.$axis;
            }
        }
        $contextHash = (string) data_get($requested, 'identity_hash', '');
        foreach ((array) data_get($capsule, 'contraindicated_contexts', []) as $contraindication) {
            if ($contextHash !== '' && hash_equals($contextHash, (string) data_get($contraindication, 'context_hash', ''))) {
                $mismatches[] = 'explicit_contraindication';
            }
        }

        return [
            'compatible' => $mismatches === [],
            'status' => $mismatches !== []
                ? 'abstain'
                : ($deferredRuntimeAxes === [] ? 'exact_activation_match' : 'routing_match_runtime_guard_required'),
            'reason_code' => $mismatches[0] ?? null,
            'mismatches' => $mismatches,
            'deferred_runtime_axes' => array_values(array_unique($deferredRuntimeAxes)),
            'runtime_activation_predicate' => $stored,
            'requested_context_hash' => $contextHash ?: null,
        ];
    }

    /** @return array<string,mixed> */
    private function instrumentBinding(LabAgent $agent, array $result, array $activation): array
    {
        $assignment = (array) data_get($agent->modelVersion?->metadata, 'instrument_research_assignment', []);
        $trace = (array) data_get($result, 'instrument_research_trace', []);
        $bundle = (array) data_get($assignment, 'bundle_identity', []);
        $keys = array_values(array_unique(array_filter(array_map('strval', (array) data_get($bundle, 'instrument_keys', [])))));
        $runtimeKeys = collect((array) data_get($trace, 'instruments', []))
            ->filter(fn ($item): bool => is_array($item) && data_get($item, 'status') === 'consumed')
            ->pluck('instrument_key')->filter()->map(fn ($key): string => (string) $key)->unique()->values()->all();
        $assignmentWithoutHash = $assignment;
        unset($assignmentWithoutHash['assignment_hash']);
        $assignmentHash = (string) data_get($assignment, 'assignment_hash', '');
        $poweredContextSupport = collect((array) data_get($trace, 'context_slices', []))
            ->filter(fn ($slice): bool => is_array($slice) && data_get($slice, 'powered') === true)
            ->filter(fn (array $slice): bool => $this->sliceMatchesActivation((array) data_get($slice, 'context', []), $activation))
            ->count();
        $checks = [
            'assignment_protocol' => data_get($assignment, 'protocol') === LabInstrumentResearchService::PROTOCOL,
            'assignment_hash_protocol' => data_get($assignment, 'hash_protocol') === LabInstrumentResearchService::HASH_PROTOCOL,
            'assignment_status' => data_get($assignment, 'status') === 'assigned',
            'assignment_hash' => $assignmentHash !== '' && hash_equals($assignmentHash, $this->hash($assignmentWithoutHash)),
            'runtime_trace' => data_get($trace, 'protocol') === 'lab_instrument_runtime_trace_v1'
                && data_get($trace, 'status') === 'consumed'
                && data_get($trace, 'assignment_hash_valid') === true
                && data_get($trace, 'parameter_hash_valid') === true
                && data_get($trace, 'runtime_bindings_valid') === true,
            'same_assignment' => $assignmentHash !== '' && hash_equals($assignmentHash, (string) data_get($trace, 'assignment_hash', '')),
            'exact_bundle' => $keys !== [] && $keys === $runtimeKeys,
            'bundle_hash' => filled(data_get($bundle, 'bundle_hash')),
            'powered_activation_context' => $poweredContextSupport > 0,
        ];

        return [
            'status' => in_array(false, $checks, true) ? 'incomplete_or_unattested' : 'exact_bundle_attested',
            'playbook_key' => data_get($bundle, 'playbook_key', data_get($assignment, 'playbook_key')),
            'primary_instrument_key' => data_get($bundle, 'primary_instrument_key'),
            'instrument_keys' => $keys,
            'bundle_hash' => data_get($bundle, 'bundle_hash'),
            'assignment_hash' => $assignmentHash ?: null,
            'runtime_trace_hash' => $trace === [] ? null : $this->hash($trace),
            'powered_context_slices' => $poweredContextSupport,
            'credit_scope' => 'atomic_bundle_only_until_factorial_component_ablation',
            'component_synergy_claimed' => false,
            'checks' => $checks,
        ];
    }

    private function sliceMatchesActivation(array $slice, array $activation): bool
    {
        $sliceAxes = (array) data_get($this->contexts->project($slice), 'extended_axes', []);
        foreach ((array) data_get($activation, 'predicate', []) as $axis => $value) {
            if (in_array($axis, ['state_cluster_id'], true)) {
                continue;
            }
            if (($sliceAxes[$axis] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,bool> */
    private function coreChecks(array $core): array
    {
        return [
            'trait_executable' => data_get($core, 'trait.executable') === true
                && filled(data_get($core, 'trait.gene')),
            'hash_protocol' => data_get($core, 'hash_protocol') === self::HASH_PROTOCOL,
            'canonical_activation_context' => data_get($core, 'activation_context.status') === 'valid'
                && filled(data_get($core, 'activation_context.context_hash')),
            'instrument_bundle_attested' => data_get($core, 'instrument_bundle.status') === 'exact_bundle_attested'
                && (array) data_get($core, 'instrument_bundle.instrument_keys', []) !== [],
            'scope_sealed' => filled(data_get($core, 'scope.symbol'))
                && filled(data_get($core, 'scope.timeframe'))
                && filled(data_get($core, 'scope.strategy_family')),
            'causal_baseline_sealed' => (int) data_get($core, 'frozen_dependencies.causal_baseline_model_version_id', 0) > 0,
            'frozen_data_hash' => filled(data_get($core, 'frozen_dependencies.data_hash')),
            'frozen_execution_hash' => filled(data_get($core, 'frozen_dependencies.execution_hash')),
        ];
    }

    /** @return array<string,mixed> */
    private function core(array $capsule): array
    {
        return array_intersect_key($capsule, array_flip([
            'protocol', 'hash_protocol', 'trait', 'instrument_bundle', 'activation_context', 'scope', 'frozen_dependencies',
        ]));
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        // JSON database casts are allowed to round-trip 1.0 as 1. A durable
        // scientific seal must survive that representation-only change while
        // still distinguishing numeric values from strings and booleans.
        if (is_int($value) || is_float($value)) {
            $number = rtrim(rtrim(sprintf('%.14F', (float) $value), '0'), '.');

            return 'number:'.($number === '-0' || $number === '' ? '0' : $number);
        }
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
