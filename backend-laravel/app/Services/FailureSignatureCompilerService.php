<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabFailureRepairAnchor;

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
    public const PROTOCOL = 'failure_signature_compiler_v1';

    /** @return array<string, mixed> */
    public function fromAnchor(LabFailureRepairAnchor $anchor): array
    {
        $existing = (array) data_get($anchor->evidence, 'failure_signature', []);
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
            'state' => (array) data_get($existing, 'state', []),
            'evolution_mode' => 'strategy_failure',
            'promotion_evidence' => false,
        ];

        return [
            ...$payload,
            'signature' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
        ];
    }

    /** @return array<string, mixed> */
    public function compile(
        LabAgent $agent,
        ?string $target = null,
        array $evidence = [],
        ?string $reason = null,
    ): array {
        $agent->loadMissing('modelVersion');
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
            'evolution_mode' => 'strategy_failure',
            'promotion_evidence' => false,
        ];

        return [
            ...$payload,
            'signature' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
            'old_value' => $old,
            'new_value' => $new,
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

        return [
            'cluster_id' => data_get($cluster, 'cluster_id', data_get($evidence, 'state_cluster_id')),
            'regime' => data_get($cluster, 'regime', data_get($metadata, 'portfolio_council_lane.regime', data_get($evidence, 'regime'))),
            'volatility' => data_get($cluster, 'volatility', data_get($metadata, 'portfolio_council_lane.volatility', data_get($evidence, 'volatility'))),
            'transition_state' => data_get($cluster, 'transition_state', data_get($evidence, 'transition_state', 'unknown')),
            'spread_liquidity_state' => data_get($cluster, 'spread_liquidity_state', data_get($evidence, 'spread_liquidity_state', 'unknown')),
            'session' => data_get($cluster, 'session', data_get($evidence, 'session', data_get($metadata, 'mutation_scope'))),
            // Volume is a contextual observation, never a direct promotion
            // feature. Keeping it in the fingerprint lets the same gene learn
            // differently in liquid/thin or fresh/stale conditions.
            'volume_state' => data_get($cluster, 'volume_state', data_get($evidence, 'volume_state', data_get($metadata, 'volume_context.state'))),
            'volume_quality' => data_get($cluster, 'volume_quality', data_get($evidence, 'volume_quality', data_get($metadata, 'volume_context.quality'))),
            'volume_available' => data_get($cluster, 'volume_available', data_get($evidence, 'volume_available', data_get($metadata, 'volume_context.available'))),
        ];
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
        if (is_bool($old) || is_bool($new)) return (bool) $new ? 'enable' : 'disable';

        return $old === $new ? 'unchanged' : 'alternate';
    }
}
