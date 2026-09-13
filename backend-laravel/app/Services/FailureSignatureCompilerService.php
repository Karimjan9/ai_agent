<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabFailureRepairAnchor;
use App\Models\ModelVersion;

/**
 * Turns a broad gate reason into a state-aware, one-gene learning signature.
 *
 * The signature is diagnostic only.  It is intentionally not a promotion
 * decision and it never changes a failed anchor.  Its job is to prevent the
 * mutation compiler from treating every temporal/stress failure as the same
 * global problem.
 */
class FailureSignatureCompilerService
{
    public const PROTOCOL = 'failure_signature_compiler_v2';

    /** @return array<string, mixed> */
    public function fromAnchor(LabFailureRepairAnchor $anchor): array
    {
        $existing = (array) data_get($anchor->evidence, 'failure_signature', []);
        $state = (array) data_get($existing, 'state', []);
        $context = app(ContextContractV2Service::class)->project($state);
        $payload = [
            'protocol' => self::PROTOCOL,
            'symbol' => strtoupper((string) $anchor->symbol),
            'timeframe' => strtoupper((string) $anchor->timeframe),
            'strategy_family' => (string) $anchor->strategy_family,
            'specialist_role' => data_get($existing, 'specialist_role'),
            'failure_target' => (string) $anchor->failure_target,
            // The canonical key is target/state/gene based. The raw gate
            // reason remains a secondary diagnostic so aliases such as
            // FAILED_TEMPORAL_CHUNK_SURVIVAL and FAILED_CALENDAR_MONTH_SURVIVAL
            // do not create separate learning surfaces.
            'failure_reason' => 'TARGET:'.strtoupper((string) $anchor->failure_target),
            'changed_gene' => count((array) $anchor->parameter_diff) === 1
                ? (string) array_key_first((array) $anchor->parameter_diff) : data_get($existing, 'changed_gene'),
            'mutation_direction' => data_get($existing, 'mutation_direction'),
            'state' => [...$state, 'context_contract' => $context],
            'canonical_context_hash' => data_get($context, 'identity_hash'),
            'causal_baseline' => [
                'model_version_id' => (int) $anchor->source_model_version_id ?: null,
                'parameter_hash' => (string) $anchor->parameter_fingerprint,
            ],
            'evolution_mode' => 'strategy_failure',
            'promotion_evidence' => false,
        ];

        $signature = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        return [
            ...$payload,
            'signature' => $signature,
            'failure_fingerprint' => $signature,
            'repeat_failure_fingerprint' => $signature,
            ...$this->productionLessonContract($payload, $signature),
        ];
    }

    /** @return array<string, mixed> */
    public function compile(
        LabAgent $agent,
        ?string $target = null,
        array $evidence = [],
        ?string $reason = null,
    ): array {
        $agent->loadMissing('modelVersion', 'parentA');
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);
        $diff = (array) ($agent->parameter_diff ?? []);
        $gene = count($diff) === 1 ? (string) array_key_first($diff) : (string) data_get(
            $metadata,
            'hypothesis_contract.changed_gene',
            data_get($metadata, 'causal_experiment_lane.parameter_key', ''),
        );
        $change = $gene !== '' ? (array) data_get($diff, $gene, []) : [];
        $old = data_get($change, 'old');
        $new = data_get($change, 'new');
        $state = $this->state($metadata, $evidence);
        $failureTarget = $this->normalize($target ?: data_get($metadata, 'generation_target', 'unknown'));
        $failureReason = strtoupper(trim((string) ($reason ?: data_get($evidence, 'failure_reason', 'UNKNOWN_FAILURE'))));
        $direction = $this->direction($old, $new);
        $baseline = $this->baselineIdentity($agent, $metadata);
        $payload = [
            'protocol' => self::PROTOCOL,
            'symbol' => strtoupper((string) $agent->symbol),
            'timeframe' => strtoupper((string) $agent->timeframe),
            'strategy_family' => (string) $agent->strategy_family,
            'specialist_role' => $this->role($metadata),
            'failure_target' => $failureTarget,
            // Keep gate aliases in secondary_diagnostics; the signature must
            // represent the causal lane, not the wording of one gate.
            'failure_reason' => 'TARGET:'.strtoupper($failureTarget),
            'changed_gene' => $gene !== '' ? $gene : null,
            'mutation_direction' => $direction,
            'state' => $state,
            'canonical_context_hash' => data_get($state, 'context_contract.identity_hash'),
            'causal_baseline' => $baseline,
            'evolution_mode' => 'strategy_failure',
            'promotion_evidence' => false,
        ];

        $signature = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        return [
            ...$payload,
            'signature' => $signature,
            'failure_fingerprint' => $signature,
            'repeat_failure_fingerprint' => $signature,
            'old_value' => $old,
            'new_value' => $new,
            ...$this->productionLessonContract($payload, $signature, $old, $new),
            'secondary_diagnostics' => array_values(array_unique(array_filter([
                data_get($evidence, 'screening_survival.reason_codes.0'),
                data_get($evidence, 'gate_reason'),
                $failureReason,
            ]))),
        ];
    }

    /** @return array<string, mixed> */
    private function state(array $metadata, array $evidence): array
    {
        $cluster = (array) data_get(
            $metadata,
            'state_cluster_contract.cluster',
            data_get($metadata, 'portfolio_council_lane.state_cluster', []),
        );
        $mutationScope = $this->mutationScope($metadata);

        $rawState = [
            'cluster_id' => data_get($cluster, 'cluster_id', data_get($evidence, 'state_cluster_id')),
            'regime' => $this->contextValue(
                data_get($cluster, 'regime'),
                data_get($metadata, 'portfolio_council_lane.regime'),
                data_get($evidence, 'regime'),
                $mutationScope['regime'],
            ),
            'volatility' => $this->contextValue(
                data_get($cluster, 'volatility'),
                data_get($metadata, 'portfolio_council_lane.volatility'),
                data_get($evidence, 'volatility'),
                $mutationScope['volatility'],
            ),
            'transition_state' => data_get($cluster, 'transition_state', data_get($evidence, 'transition_state', 'unknown')),
            'spread_liquidity_state' => data_get($cluster, 'spread_liquidity_state', data_get($evidence, 'spread_liquidity_state', 'unknown')),
            // `mutation_scope` is a typed axis (`market:*`,
            // `volatility:*`, or `session:*`). Treating every scalar scope as
            // a session poisoned contextual retrieval (for example,
            // volatility:high_volatility became a session name).
            'session' => $this->contextValue(
                data_get($cluster, 'session'),
                data_get($evidence, 'session'),
                $mutationScope['session'],
            ),
            // Volume is a contextual observation, never a direct promotion
            // feature. Keeping it in the fingerprint lets the same gene learn
            // differently in liquid/thin or fresh/stale conditions.
            'volume_state' => data_get($cluster, 'volume_state', data_get($evidence, 'volume_state', data_get($metadata, 'volume_context.state'))),
            'volume_quality' => data_get($cluster, 'volume_quality', data_get($evidence, 'volume_quality', data_get($metadata, 'volume_context.quality'))),
            'volume_available' => data_get($cluster, 'volume_available', data_get($evidence, 'volume_available', data_get($metadata, 'volume_context.available'))),
        ];
        $contract = app(ContextContractV2Service::class)->project($rawState);

        return [...$rawState,
            // Keep only canonical axes on the retrieval surface while the
            // raw v1 values stay immutable inside the versioned projection.
            'regime' => data_get($contract, 'axes.regime'),
            'volatility' => data_get($contract, 'axes.volatility'),
            'session' => data_get($contract, 'axes.session'),
            'context_contract' => $contract,
        ];
    }

    /** @return array{regime:?string,volatility:?string,session:?string} */
    private function mutationScope(array $metadata): array
    {
        $scope = data_get($metadata, 'mutation_scope');
        if (is_array($scope)) {
            return [
                'regime' => $this->contextValue(data_get($scope, 'market_regime'), data_get($scope, 'regime')),
                'volatility' => $this->contextValue(data_get($scope, 'volatility'), data_get($scope, 'volatility_regime')),
                'session' => $this->contextValue(data_get($scope, 'session')),
            ];
        }

        $value = strtolower(trim((string) $scope));

        return [
            'regime' => str_starts_with($value, 'market:') ? $this->contextValue(substr($value, 7)) : null,
            'volatility' => str_starts_with($value, 'volatility:') ? $this->contextValue(substr($value, 11)) : null,
            'session' => str_starts_with($value, 'session:') ? $this->contextValue(substr($value, 8)) : null,
        ];
    }

    private function contextValue(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $normalized = trim((string) $value);
            if ($normalized === '' || in_array(strtolower($normalized), ['-', 'unknown', 'none', 'null'], true)) {
                continue;
            }

            return $normalized;
        }

        return null;
    }

    private function role(array $metadata): ?string
    {
        $role = data_get($metadata, 'council_specialist_contract.role', data_get(
            $metadata,
            'repair_anchor_sibling.role',
            data_get($metadata, 'portfolio_council_lane.specialist_role'),
        ));

        return filled($role) ? (string) $role : null;
    }

    private function normalize(mixed $value): string
    {
        return strtolower(trim((string) $value)) ?: 'unknown';
    }

    private function direction(mixed $old, mixed $new): ?string
    {
        if (is_numeric($old) && is_numeric($new)) {
            return (float) $new > (float) $old ? 'increase' : ((float) $new < (float) $old ? 'decrease' : 'unchanged');
        }
        if (is_bool($old) || is_bool($new)) {
            return (bool) $new ? 'enable' : 'disable';
        }

        return $old === $new ? 'unchanged' : 'alternate';
    }

    /** @return array{model_version_id:?int,parameter_hash:?string} */
    private function baselineIdentity(LabAgent $agent, array $metadata): array
    {
        $modelId = (int) data_get(
            $metadata,
            'causal_baseline_model_version_id',
            data_get(
                $metadata,
                'causal_learning_cohort.baseline_model_version_id',
                data_get(
                    $metadata,
                    'control_pair_contract.causal_baseline_model_version_id',
                    data_get($metadata, 'repair_anchor.source_model_version_id', $agent->parent_a_model_version_id),
                ),
            ),
        );
        $model = $modelId > 0
            ? (($agent->parentA && (int) $agent->parentA->id === $modelId)
                ? $agent->parentA
                : ModelVersion::query()->find($modelId))
            : null;
        if (! $model) {
            return ['model_version_id' => null, 'parameter_hash' => null];
        }

        return [
            'model_version_id' => (int) $model->id,
            'parameter_hash' => $this->parameterHash((array) $model->parameters),
        ];
    }

    private function parameterHash(array $parameters): string
    {
        $sort = function (array $values) use (&$sort): array {
            ksort($values);
            foreach ($values as $key => $value) {
                if (is_array($value)) {
                    $values[$key] = $sort($value);
                }
            }

            return $values;
        };

        return hash('sha256', json_encode($sort($parameters), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return array<string,mixed> */
    private function productionLessonContract(
        array $payload,
        string $fingerprint,
        mixed $oldValue = null,
        mixed $newValue = null,
    ): array {
        $target = (string) data_get($payload, 'failure_target', 'unknown');
        $gene = data_get($payload, 'changed_gene');
        $direction = data_get($payload, 'mutation_direction');
        $contextHash = data_get($payload, 'canonical_context_hash');
        $rootCause = match ($target) {
            'trade_frequency' => 'The declared entry/abstention surface may be suppressing valid opportunities in this context.',
            'profit_factor' => 'The declared decision surface may not create positive after-cost expectancy in this context.',
            'stress_cost' => 'Execution cost or spread sensitivity may erase the local edge.',
            'temporal_stability', 'monthly_survival' => 'The decision policy may be unstable across chronological market phases.',
            'regime_coverage' => 'The router or activation predicate may not own the observed regime transition.',
            'drawdown_risk' => 'The risk/exit response may permit excessive adverse excursion or tail concentration.',
            'architecture' => 'The current architecture may be unable to express the required behavioural repair.',
            default => 'The declared causal surface must be isolated before another mutation is attempted.',
        };

        return [
            'root_cause_hypothesis' => [
                'status' => 'unverified_hypothesis',
                'statement' => $rootCause,
                'target' => $target,
                'must_be_falsified_against_exact_control' => true,
            ],
            'gene_policy' => [
                'forbidden' => filled($gene) ? [[
                    'gene' => $gene,
                    'direction' => $direction,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'rule' => 'Do not replay this exact unresolved intervention without a new hypothesis revision.',
                ]] : [],
                'recommended' => filled($gene) ? [[
                    'gene' => $gene,
                    'mode' => 'revised_bounded_repair_or_ablation',
                    'context_hash' => $contextHash,
                ]] : [],
            ],
            'context_scope' => [
                'context_hash' => $contextHash,
                'state' => data_get($payload, 'state', []),
                'global_inheritance_forbidden' => true,
            ],
            'next_experiment' => [
                'action' => 'repair_or_deliberate_abstain',
                'target' => $target,
                'one_gene_or_one_structural_axis' => true,
                'new_hypothesis_revision_required' => true,
                'exact_frozen_control_required' => true,
                'independent_windows_required' => 3,
            ],
            'exact_control' => [
                'model_version_id' => data_get($payload, 'causal_baseline.model_version_id'),
                'parameter_hash' => data_get($payload, 'causal_baseline.parameter_hash'),
                'same_data_and_execution_contract_required' => true,
            ],
            'consumption_receipt' => [
                'status' => 'pending',
                'failure_fingerprint' => $fingerprint,
                'must_be_sealed_before_mutation' => true,
                'outcome_must_close_source_lesson' => true,
                'promotion_evidence' => false,
            ],
        ];
    }
}
