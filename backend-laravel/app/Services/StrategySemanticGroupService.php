<?php

namespace App\Services;

use App\Models\ModelVersion;

/**
 * Defines the biological/semantic boundary of an agent family.
 *
 * A strategy architecture is a gene inside a group. The group boundary is
 * the market stream, strategy family and declared operating envelope/role.
 * Parents and champions may not cross that boundary merely because their
 * headline PF happens to be attractive.
 */
class StrategySemanticGroupService
{
    public const PROTOCOL = 'strategy_semantic_group_v1';

    /**
     * Canonical council envelopes.  A control root is a seed for the exact
     * cell that the next specialist owns; the role, family and operating
     * envelope therefore have to be defined once and reused by both plans.
     *
     * @return array<string, array<string, string>>
     */
    public function canonicalSpecialistGroups(): array
    {
        return [
            'trend_up_specialist' => [
                'role' => 'trend_up_specialist',
                'regime' => 'trend_up',
                'volatility' => 'high_volatility',
                'family' => 'differential_router',
                'target' => 'transition_firewall',
            ],
            'trend_down_specialist' => [
                'role' => 'trend_down_specialist',
                'regime' => 'trend_down',
                'volatility' => 'normal_volatility',
                'family' => 'differential_router',
                'target' => 'opportunity_recall',
            ],
            'range_specialist' => [
                'role' => 'range_specialist',
                'regime' => 'range',
                'volatility' => 'low_volatility',
                'family' => 'hybrid',
                'target' => 'regime_coverage',
            ],
            'transition_risk_router' => [
                'role' => 'transition_risk_router',
                // The router protects the transition in the trend-up/high
                // volatility envelope used by the execution contract.  The
                // policy label "transition|risk" is descriptive only and
                // must not become a different semantic cell.
                'regime' => 'trend_up',
                'volatility' => 'high_volatility',
                'family' => 'hybrid',
                'target' => 'transition_firewall',
            ],
        ];
    }

    public function descriptor(
        string $symbol,
        string $timeframe,
        string $family,
        ?array $niche = null,
        ?string $architecture = null,
    ): array {
        $niche = (array) ($niche ?? []);
        $role = $this->firstValue([
            data_get($niche, 'specialist_role'),
            data_get($niche, 'role'),
            'general',
        ]);
        $regime = $this->firstValue([data_get($niche, 'regime'), '*']);
        $volatility = $this->firstValue([data_get($niche, 'volatility'), '*']);
        $direction = $this->firstValue([data_get($niche, 'direction'), '*']);

        $parts = [
            strtoupper($symbol), strtoupper($timeframe), $family,
            $this->normalize($role), $this->normalize($regime),
            $this->normalize($volatility), $this->normalize($direction),
        ];

        return [
            'protocol' => self::PROTOCOL,
            // Architecture is intentionally stored as a child gene, not part
            // of the group key. Pullback and retest variants can learn from
            // the same trend group without importing a foreign family.
            'key' => implode('|', $parts),
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'strategy_family' => $family,
            'role' => $this->normalize($role),
            'regime' => $this->normalize($regime),
            'volatility' => $this->normalize($volatility),
            'direction' => $this->normalize($direction),
            'architecture' => $architecture ?: 'unknown',
            'declared' => true,
            'promotion_evidence' => false,
        ];
    }

    /** Derive a group for a new or legacy model version. */
    public function fromModel(ModelVersion $model, ?string $family = null): array
    {
        $metadata = (array) ($model->metadata ?? []);
        $declared = (array) data_get($metadata, 'semantic_group', []);
        if (data_get($declared, 'protocol') === self::PROTOCOL && filled(data_get($declared, 'key'))) {
            return [
                ...$declared,
                'strategy_family' => (string) data_get($declared, 'strategy_family', $family ?: 'unknown'),
                'declared' => true,
            ];
        }

        $lane = (array) data_get($metadata, 'portfolio_council_lane', []);
        $specialist = (array) data_get($metadata, 'council_specialist_contract', []);
        $niche = [
            'role' => data_get($specialist, 'role', data_get($lane, 'specialist_role', data_get($lane, 'role'))),
            'regime' => data_get($specialist, 'owner_regime', data_get($lane, 'regime')),
            'volatility' => data_get($specialist, 'owner_volatility', data_get($lane, 'volatility')),
            'direction' => data_get($specialist, 'owner_direction', data_get($lane, 'direction')),
        ];
        $resolvedFamily = $family ?: (string) data_get($declared, 'strategy_family', 'unknown');
        $architecture = data_get($metadata, 'strategy_architecture');
        $group = $this->descriptor(
            (string) data_get($metadata, 'lab_symbol', '*'),
            (string) data_get($metadata, 'lab_timeframe', '*'),
            $resolvedFamily,
            $niche,
            is_string($architecture) ? $architecture : null,
        );
        // Legacy models are accepted only as same-family, unscoped seeds. A
        // future child records a declared group and is never confused with a
        // different family or a different council envelope.
        $hasSemanticScope = collect($niche)->contains(fn ($value): bool => filled($value));
        if (! $hasSemanticScope && ! filled($architecture)) {
            $group['key'] = implode('|', [
                $group['symbol'], $group['timeframe'], $resolvedFamily,
                'general', '*', '*', '*',
            ]);
            $group['role'] = 'general';
            $group['regime'] = '*';
            $group['volatility'] = '*';
            $group['direction'] = '*';
        }
        $group['declared'] = false;
        $group['legacy_unscoped'] = true;
        return $group;
    }

    /** Same declared semantic group is required for a champion comparison. */
    public function sameGroup(
        ModelVersion $left,
        ?string $leftFamily,
        ModelVersion $right,
        ?string $rightFamily,
    ): bool {
        $leftGroup = $this->fromModel($left, $leftFamily);
        $rightGroup = $this->fromModel($right, $rightFamily);
        // An inferred legacy key is useful for diagnostics, but it is not a
        // genetic identity.  Treating it as a real group silently lets an
        // old unscoped model become a parent of a newly declared niche.
        return (bool) data_get($leftGroup, 'declared', false)
            && ! (bool) data_get($leftGroup, 'legacy_unscoped', false)
            && (bool) data_get($rightGroup, 'declared', false)
            && ! (bool) data_get($rightGroup, 'legacy_unscoped', false)
            && (string) data_get($leftGroup, 'key') !== ''
            && data_get($leftGroup, 'key') === data_get($rightGroup, 'key');
    }

    /**
     * Diagnostic compatibility only. Legacy unscoped same-family parents may
     * be surfaced while auditing old evidence, but callers constructing a
     * child must use exactParentCompatible(). Cross-family parents are never
     * compatible in either mode.
     */
    public function parentCompatible(ModelVersion $parent, string $family, ?array $niche = null): bool
    {
        $group = $this->fromModel($parent, $family);
        if ((string) data_get($group, 'strategy_family') !== $family) return false;

        $expected = $this->descriptor(
            (string) data_get($parent->metadata, 'lab_symbol', '*'),
            (string) data_get($parent->metadata, 'lab_timeframe', '*'),
            $family,
            $niche,
        );
        if ((bool) data_get($group, 'legacy_unscoped', false)) return true;

        foreach (['role', 'regime', 'volatility', 'direction'] as $field) {
            $wanted = (string) data_get($expected, $field, '*');
            $actual = (string) data_get($group, $field, '*');
            if ($wanted !== '*' && $actual !== '*' && $wanted !== $actual) return false;
        }
        return true;
    }

    /**
     * Strict genetic-parent check.
     *
     * `parentCompatible()` is intentionally retained as a loose diagnostic
     * query for migration reports.  It may answer whether a legacy model is
     * worth inspecting.  It must never be used to construct a child.  This
     * method is the only parent predicate used by the population builder:
     * the parent must have a declared, canonical semantic group and its full
     * key must equal the child's requested group key.
     */
    public function exactParentCompatible(
        ModelVersion $parent,
        string $symbol,
        string $timeframe,
        string $family,
        ?array $niche = null,
    ): bool {
        $actual = $this->fromModel($parent, $family);
        if ((string) data_get($actual, 'strategy_family') !== $family) return false;
        if (! (bool) data_get($actual, 'declared', false)
            || (bool) data_get($actual, 'legacy_unscoped', false)) {
            return false;
        }

        $expected = $this->descriptor($symbol, $timeframe, $family, $niche);
        $canonicalActual = $this->descriptor(
            (string) data_get($actual, 'symbol', '*'),
            (string) data_get($actual, 'timeframe', '*'),
            $family,
            [
                'role' => data_get($actual, 'role'),
                'regime' => data_get($actual, 'regime'),
                'volatility' => data_get($actual, 'volatility'),
                'direction' => data_get($actual, 'direction'),
            ],
        );

        // Checking the canonical reconstruction prevents a forged/stale key
        // from passing merely because the serialized `key` field happens to
        // match the requested group.
        return (string) data_get($actual, 'key') === (string) data_get($canonicalActual, 'key')
            && (string) data_get($actual, 'key') === (string) data_get($expected, 'key');
    }

    /** True only for a model that can participate in genetic inheritance. */
    public function hasDeclaredGroup(ModelVersion $model, ?string $family = null): bool
    {
        $group = $this->fromModel($model, $family);
        return (bool) data_get($group, 'declared', false)
            && ! (bool) data_get($group, 'legacy_unscoped', false)
            && filled(data_get($group, 'key'));
    }

    private function firstValue(array $values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') return (string) $value;
        }
        return '*';
    }

    private function normalize(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' || in_array($value, ['null', 'unknown'], true)) return '*';
        return preg_replace('/[^a-z0-9_-]+/', '-', $value) ?: '*';
    }
}
