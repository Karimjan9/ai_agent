<?php

namespace App\Services;

/**
 * Canonical, typed context identity.  It turns legacy free-form state into
 * the bounded axes used by research retrieval without deleting the original
 * evidence.  Unknown or cross-axis values fail closed as incomplete context.
 */
class ContextContractV2Service
{
    public const PROTOCOL = 'context_contract_v2';

    /** @return array<string,mixed> */
    public function project(array $state): array
    {
        $raw = $this->rawAxes($state);
        $extendedAxes = $this->canonicalAxes($state);
        $axes = array_intersect_key($extendedAxes, array_flip(['regime', 'volatility', 'session']));
        $invalid = [];
        foreach (['regime', 'volatility', 'session'] as $axis) {
            if (($axes[$axis] ?? null) === null) {
                $invalid[] = $axis;
            }
        }

        return [
            'protocol' => self::PROTOCOL,
            'version' => 2,
            'axes' => $axes,
            'extended_axes' => $extendedAxes,
            'raw_v1_axes' => $raw,
            'status' => $invalid === [] ? 'valid' : 'context_incomplete',
            'invalid_axes' => $invalid,
            'identity_hash' => hash('sha256', json_encode($extendedAxes, JSON_UNESCAPED_SLASHES)),
            'promotion_evidence' => false,
        ];
    }

    /**
     * One normalization surface for lessons, generation niches, instrument
     * evidence and runtime episodes. Optional axes do not make the legacy
     * three-axis contract invalid, but they do participate in identity and
     * exact-context retrieval whenever they are observed.
     *
     * @return array<string,?string>
     */
    public function canonicalAxes(array $state): array
    {
        $raw = $this->rawAxes($state);

        return [
            'regime' => $this->regime($raw['regime']),
            'volatility' => $this->volatility($raw['volatility']),
            'session' => $this->session($raw['session']),
            'transition_state' => $this->transition($raw['transition_state']),
            'spread_liquidity_state' => $this->liquidity($raw['spread_liquidity_state']),
            'volume_state' => $this->bounded($raw['volume_state']),
            'direction' => $this->bounded($raw['direction']),
            'state_cluster_id' => $this->bounded($raw['state_cluster_id'], false),
            'session_instance_id' => $this->bounded($raw['session_instance_id'], false),
            'venue_phase' => $this->session($raw['venue_phase']),
            'overlap_mask' => $this->mask($raw['overlap_mask']),
            'minutes_from_boundary' => is_numeric($raw['minutes_from_boundary'])
                ? (string) max(0, (int) $raw['minutes_from_boundary'])
                : null,
            'calendar_version' => $this->bounded($raw['calendar_version'], false),
            'session_offset_state' => $this->bounded($raw['session_offset_state'], false),
        ];
    }

    /** @return array<string,mixed> */
    private function rawAxes(array $state): array
    {
        $contractAxes = (array) data_get($state, 'context_contract.axes', []);
        $nested = (array) data_get($state, 'state', []);
        $value = static function (string $key, array $aliases = []) use ($state, $nested, $contractAxes): mixed {
            foreach ([$key, ...$aliases] as $candidate) {
                foreach ([$state, $nested, $contractAxes] as $source) {
                    $found = data_get($source, $candidate);
                    if ($found !== null && $found !== '') {
                        return $found;
                    }
                }
            }

            return null;
        };

        return [
            'regime' => $value('regime', ['market_regime', 'h1_regime', 'm15_regime']),
            'volatility' => $value('volatility', ['volatility_regime']),
            'session' => $value('session', ['session_name', 'session_utc_hour']),
            'transition_state' => $value('transition_state', ['transition']),
            'spread_liquidity_state' => $value('spread_liquidity_state', ['spread_state', 'liquidity_state', 'liquidity']),
            'volume_state' => $value('volume_state', ['volume_quality']),
            'direction' => $value('direction', ['side']),
            'state_cluster_id' => $value('state_cluster_id', ['cluster_id', 'state_cluster']),
            'session_instance_id' => $value('session_instance_id', ['session_ownership.session_instance_id']),
            'venue_phase' => $value('venue_phase', ['session_ownership.venue_phase']),
            'overlap_mask' => $value('overlap_mask', ['session_ownership.overlap_mask']),
            'minutes_from_boundary' => $value('minutes_from_boundary', ['session_ownership.minutes_from_boundary']),
            'calendar_version' => $value('calendar_version', ['session_ownership.calendar_version']),
            'session_offset_state' => $value('session_offset_state', ['offset_state']),
        ];
    }

    private function regime(mixed $value): ?string
    {
        return match ($this->key($value)) {
            'trend_up', 'uptrend', 'bull', 'bullish' => 'trend_up',
            'trend_down', 'downtrend', 'bear', 'bearish' => 'trend_down',
            'range', 'ranging', 'mean_reversion' => 'range',
            'transition', 'transitional' => 'transition',
            default => null,
        };
    }

    private function volatility(mixed $value): ?string
    {
        return match ($this->key($value)) {
            'low', 'low_volatility', 'compression' => 'low',
            'normal', 'normal_volatility', 'medium' => 'normal',
            'high', 'high_volatility', 'expansion' => 'high',
            default => null,
        };
    }

    private function session(mixed $value): ?string
    {
        return match ($this->key($value)) {
            'asia', 'asian' => 'asia',
            'london' => 'london',
            'new_york', 'newyork', 'ny' => 'new_york',
            'overlap', 'london_new_york_overlap', 'london_ny_overlap' => 'overlap',
            default => null,
        };
    }

    private function transition(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'transition' : 'stable';
        }
        $key = $this->bounded($value);
        if ($key === null) {
            return null;
        }

        return str_contains($key, 'transition') ? 'transition' : (in_array($key, ['stable', 'normal'], true) ? 'stable' : $key);
    }

    private function liquidity(mixed $value): ?string
    {
        $key = $this->bounded($value);
        if ($key === null) {
            return null;
        }

        return match ($key) {
            'low_spread', 'normal_spread', 'normal', 'liquid', 'high_liquidity' => 'normal',
            'high_spread', 'thin', 'illiquid', 'low_liquidity' => 'high',
            default => $key,
        };
    }

    private function bounded(mixed $value, bool $normalize = true): ?string
    {
        if (is_array($value)) {
            foreach (['value', 'state', 'cluster_id', 'state_cluster_id'] as $key) {
                if (array_key_exists($key, $value)) {
                    return $this->bounded($value[$key], $normalize);
                }
            }

            return null;
        }
        if (! is_scalar($value) && ! $value instanceof \Stringable) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '' || in_array(strtolower($text), ['unknown', 'missing', 'mixed', 'historical_mixed', 'stratified_replay'], true)) {
            return null;
        }

        return $normalize ? $this->key($text) : $text;
    }

    private function mask(mixed $value): ?string
    {
        if (! is_array($value)) {
            return $this->bounded($value);
        }
        $values = array_values(array_unique(array_filter(array_map(
            fn (mixed $item): ?string => $this->bounded($item),
            $value,
        ))));
        sort($values);

        return $values === [] ? null : implode('+', $values);
    }

    private function key(mixed $value): string
    {
        return strtolower(str_replace(['-', ' ', '/'], '_', trim((string) $value)));
    }
}
