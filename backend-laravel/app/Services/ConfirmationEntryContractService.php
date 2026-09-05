<?php

namespace App\Services;

/**
 * Normalizes the Python row-level entry contract without granting it routing
 * or risk authority. Missing contracts retain the historical route behavior,
 * but are explicitly marked as legacy/unattested evidence.
 */
class ConfirmationEntryContractService
{
    public const PROTOCOL = 'confirmation_entry_contract_v1';

    /** @return array<string,mixed> */
    public function compile(
        array $input = [],
        bool $legacyRouteActionable = false,
        bool $contractRequired = false,
        bool $attested = true,
    ): array {
        if (($input['protocol'] ?? null) !== self::PROTOCOL) {
            if ($contractRequired) {
                return [
                    'protocol' => 'confirmation_entry_contract_missing_v1',
                    'source_protocol' => $input['protocol'] ?? null,
                    'model' => 'unknown', 'mode' => 'unknown', 'direction' => 'WAIT',
                    'status' => 'required_contract_missing', 'stage' => 'setup', 'order_type' => 'none',
                    'checks' => array_fill_keys([
                        'context', 'location', 'setup', 'confirmation', 'trigger',
                        'invalidation', 'reward_space', 'chase', 'event',
                    ], false),
                    'required' => true, 'attested' => false, 'geometry_valid' => false,
                    'executable' => false,
                    'reason_codes' => ['ENTRY_CONTRACT_REQUIRED'],
                    'confirmation' => ['independent_count' => 0, 'raw_count' => 0, 'redundancy_penalty' => 0, 'families' => []],
                    'promotion_evidence' => false,
                ];
            }

            return [
                'protocol' => 'legacy_route_entry_projection_v1',
                'source_protocol' => $input['protocol'] ?? null,
                'model' => 'legacy_route',
                'mode' => 'legacy',
                'status' => $legacyRouteActionable ? 'legacy_route_actionable' : 'legacy_route_wait',
                'stage' => $legacyRouteActionable ? 'entry' : 'setup',
                'checks' => array_fill_keys([
                    'context', 'location', 'setup', 'confirmation', 'trigger',
                    'invalidation', 'reward_space', 'chase', 'event',
                ], $legacyRouteActionable),
                'required' => false,
                'attested' => true,
                'geometry_valid' => $legacyRouteActionable,
                'executable' => $legacyRouteActionable,
                'reason_codes' => $legacyRouteActionable ? [] : ['ROUTER_ABSTAIN'],
                'confirmation' => ['independent_count' => 0, 'raw_count' => 0, 'redundancy_penalty' => 0, 'families' => []],
                'promotion_evidence' => false,
            ];
        }

        $supplied = (array) ($input['checks'] ?? []);
        $checks = [];
        foreach (['context', 'location', 'setup', 'confirmation', 'trigger', 'invalidation', 'reward_space', 'chase', 'event'] as $name) {
            $checks[$name] = (bool) ($supplied[$name] ?? false);
        }
        $reasonMap = [
            'context' => 'ENTRY_CONTEXT_NOT_READY',
            'location' => 'ENTRY_LOCATION_INVALID',
            'setup' => 'ENTRY_SETUP_MISSING',
            'confirmation' => 'ENTRY_CONFIRMATION_MISSING',
            'trigger' => 'ENTRY_TRIGGER_MISSING',
            'invalidation' => 'ENTRY_INVALIDATION_INVALID',
            'reward_space' => 'ENTRY_REWARD_SPACE_INSUFFICIENT',
            'chase' => 'ENTRY_CHASING_VETO',
            'event' => 'ENTRY_EVENT_VETO',
        ];
        $reasons = [];
        foreach ($checks as $name => $passed) {
            if (! $passed) {
                $reasons[] = $reasonMap[$name];
            }
        }
        $confirmation = (array) ($input['confirmation'] ?? []);
        $families = array_values(array_unique(array_filter(
            array_map('strval', (array) ($confirmation['families'] ?? [])),
            fn (string $value): bool => $value !== '',
        )));
        $independent = max(0, (int) ($confirmation['independent_count'] ?? 0));
        $raw = max(0, (int) ($confirmation['raw_count'] ?? 0));
        $redundancy = max(0, (int) ($confirmation['redundancy_penalty'] ?? 0));
        $integrityReasons = [];
        if (! $attested) {
            $integrityReasons[] = 'ENTRY_CONTRACT_ATTESTATION_FAILED';
        }
        if (! in_array((string) ($input['model'] ?? ''), [
            'trend_continuation', 'breakout_retest', 'false_break_reversal',
            'range_sweep', 'htf_reversal',
        ], true)) {
            $integrityReasons[] = 'ENTRY_CONTRACT_MODEL_INVALID';
        }
        if (! in_array((string) ($input['mode'] ?? ''), ['aggressive', 'balanced', 'conservative'], true)) {
            $integrityReasons[] = 'ENTRY_CONTRACT_MODE_INVALID';
        }
        $allowedFamilies = ['price_reaction', 'market_structure', 'volatility_participation'];
        $evidenceConsistent = array_diff($families, $allowedFamilies) === []
            && $independent === count($families)
            && $raw >= $independent
            && $redundancy === $raw - $independent;
        if ($checks['confirmation'] && ! $evidenceConsistent) {
            $integrityReasons[] = 'ENTRY_CONFIRMATION_EVIDENCE_INCONSISTENT';
        }

        $reference = $this->finiteNumber($input['reference_price'] ?? null);
        $invalidation = $this->finiteNumber($input['invalidation_price'] ?? null);
        $target = $this->finiteNumber($input['target_reference_price'] ?? null);
        $anchor = $this->finiteNumber($input['trigger_anchor_price'] ?? null);
        $atr = $this->finiteNumber($input['structure_atr'] ?? null);
        $rewardSpace = $this->finiteNumber($input['reward_space_r'] ?? null);
        $chaseDistance = $this->finiteNumber($input['chase_distance_atr'] ?? null);
        $direction = (string) ($input['direction'] ?? 'WAIT');
        $mode = (string) ($input['mode'] ?? 'unknown');
        $readyClaim = ($input['status'] ?? null) === 'entry_ready';
        $geometryValid = false;
        if ($readyClaim) {
            $expectedOrderType = [
                'aggressive' => 'stop_after_confirmation_close',
                'balanced' => 'market_after_retest_close',
                'conservative' => 'stop_after_retest_continuation',
            ][$mode] ?? null;
            if (($input['stage'] ?? null) !== 'entry' || ($input['order_type'] ?? null) !== $expectedOrderType) {
                $integrityReasons[] = 'ENTRY_CONTRACT_STATE_INCONSISTENT';
            }
            if (! in_array($direction, ['BUY', 'SELL'], true)) {
                $integrityReasons[] = 'ENTRY_CONTRACT_DIRECTION_INVALID';
            } elseif ($reference === null || $invalidation === null || $target === null || $anchor === null
                || $atr === null || $rewardSpace === null || $chaseDistance === null || $atr <= 0) {
                $integrityReasons[] = 'ENTRY_CONTRACT_GEOMETRY_MISSING';
            } else {
                $risk = $direction === 'BUY' ? $reference - $invalidation : $invalidation - $reference;
                $reward = $direction === 'BUY' ? $target - $reference : $reference - $target;
                $computedReward = $risk > 0 ? $reward / $risk : -1;
                $computedChase = max(0.0, $direction === 'BUY' ? $reference - $anchor : $anchor - $reference) / $atr;
                $rewardTolerance = max(0.0001, abs($rewardSpace) * 0.0001);
                $chaseTolerance = max(0.0001, abs($chaseDistance) * 0.0001);
                $geometryValid = $reference > 0 && $invalidation > 0 && $target > 0
                    && $risk > 0 && $reward > 0 && $rewardSpace > 0 && $chaseDistance >= 0
                    && abs($computedReward - $rewardSpace) <= $rewardTolerance
                    && abs($computedChase - $chaseDistance) <= $chaseTolerance;
                if (! $geometryValid) {
                    $integrityReasons[] = 'ENTRY_CONTRACT_GEOMETRY_INCONSISTENT';
                }
            }
        }
        $reasons = array_values(array_unique([...$reasons, ...$integrityReasons]));
        $executable = $reasons === [] && $readyClaim && $geometryValid;

        return [
            'protocol' => self::PROTOCOL,
            'model' => (string) ($input['model'] ?? 'unknown'),
            'mode' => $mode,
            'direction' => $direction,
            'status' => (string) ($input['status'] ?? 'unknown'),
            'stage' => (string) ($input['stage'] ?? 'unknown'),
            'order_type' => (string) ($input['order_type'] ?? 'none'),
            'checks' => $checks,
            'required' => $contractRequired,
            'attested' => $attested,
            'geometry_valid' => $geometryValid,
            'executable' => $executable,
            'reason_codes' => array_values(array_unique($reasons)),
            'confirmation' => [
                'families' => $families,
                'independent_count' => $independent,
                'raw_count' => $raw,
                'redundancy_penalty' => $redundancy,
                'rule' => 'Repeated momentum labels count once; marginal value must be validated out of sample.',
            ],
            'reference_price' => $reference,
            'invalidation_price' => $invalidation,
            'target_reference_price' => $target,
            'trigger_anchor_price' => $anchor,
            'structure_atr' => $atr,
            'reward_space_r' => $rewardSpace,
            'chase_distance_atr' => $chaseDistance,
            'score' => $input['score'] ?? 0,
            'grade' => (string) ($input['grade'] ?? 'SKIP'),
            'contract_hash' => $input['contract_hash'] ?? null,
            'causal_context' => (array) ($input['causal_context'] ?? []),
            'promotion_evidence' => false,
        ];
    }

    private function finiteNumber(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }
}
