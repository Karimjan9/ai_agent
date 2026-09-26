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

    public function __construct(private MarketSessionCalendarService $marketSessions) {}

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
        $timestamp = data_get($state, 'timestamp', data_get(
            $state,
            'time',
            data_get($state, 'candle_time', data_get($state, 'signal_time')),
        ));
        if (filled($timestamp)
            && ! filled(data_get($state, 'venue_phase'))
            && ! filled(data_get($state, 'session_ownership.venue_phase'))) {
            $resolved = $this->marketSessions->resolve($timestamp, [
                'candle_end' => data_get($state, 'candle_end'),
                'duration_minutes' => data_get($state, 'duration_minutes'),
                'spread_atr_ratio' => data_get($state, 'spread_atr_ratio'),
                'spread_liquidity_state' => data_get($state, 'spread_liquidity_state'),
            ]);
            // Explicit caller axes remain authoritative declarations; the
            // calendar only fills coordinates which were not supplied.
            $state = [...$resolved, ...$state];
        }
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
            'venue_phase' => $this->venuePhase($raw['venue_phase']),
            'venue_phases' => $this->mask($raw['venue_phases']),
            'overlap_mask' => $this->mask($raw['overlap_mask']),
            'minutes_from_boundary' => is_numeric($raw['minutes_from_boundary'])
                ? (string) max(0, (int) $raw['minutes_from_boundary'])
                : null,
            'calendar_version' => $this->bounded($raw['calendar_version'], false),
            'session_offset_state' => $this->bounded($raw['session_offset_state'], false),
            'local_time' => $this->structured($raw['local_time']),
            'utc_interval' => $this->structured($raw['utc_interval']),
            'dst_offset' => $this->structured($raw['dst_offset']),
            'minutes_from_open' => $this->structured($raw['minutes_from_open']),
            'minutes_from_fix_or_settlement' => $this->structured($raw['minutes_from_fix_or_settlement']),
            'holiday_or_maintenance_state' => $this->structured($raw['holiday_or_maintenance_state']),
            'classification_status' => $this->bounded($raw['classification_status']),
        ];
    }

    /**
     * Canonicalize an explicitly declared specialist/instrument boundary.
     *
     * Runtime declarations are stricter than observed market context: legacy
     * placeholders (for example "-", "unknown" or "both") are absence, not
     * wildcard authority.  Callers may therefore safely omit unresolved axes
     * instead of accidentally creating a scope which rejects every real
     * candle.
     *
     * @return array<string,string>
     */
    public function canonicalDeclaredAxes(array $state): array
    {
        $axes = $this->canonicalAxes($state);

        return array_filter(
            array_intersect_key($axes, array_flip([
                'regime', 'session', 'venue_phase', 'volatility',
                'spread_liquidity_state', 'transition_state', 'direction',
                'session_instance_id', 'calendar_version',
            ])),
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        );
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
            'venue_phases' => $value('venue_phases', ['active_phases', 'session_ownership.venue_phases']),
            'overlap_mask' => $value('overlap_mask', ['session_ownership.overlap_mask']),
            'minutes_from_boundary' => $value('minutes_from_boundary', ['session_ownership.minutes_from_boundary']),
            'calendar_version' => $value('calendar_version', ['session_ownership.calendar_version']),
            'session_offset_state' => $value('session_offset_state', ['offset_state']),
            'local_time' => $value('local_time', ['session_ownership.local_time']),
            'utc_interval' => $value('utc_interval', ['session_ownership.utc_interval']),
            'dst_offset' => $value('dst_offset', ['session_ownership.dst_offset']),
            'minutes_from_open' => $value('minutes_from_open', ['session_ownership.minutes_from_open']),
            'minutes_from_fix_or_settlement' => $value('minutes_from_fix_or_settlement', ['session_ownership.minutes_from_fix_or_settlement']),
            'holiday_or_maintenance_state' => $value('holiday_or_maintenance_state', ['session_ownership.holiday_or_maintenance_state']),
            'classification_status' => $value('classification_status', ['session_ownership.classification_status']),
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

    private function venuePhase(mixed $value): ?string
    {
        $key = $this->key($value);
        if (in_array($key, [
            'asia_sge_night', 'asia_sge_day', 'london_pre_am_fix', 'london_am_fix',
            'london_interfix', 'london_pm_fix', 'comex_active', 'comex_pre_settlement',
            'comex_post_settlement', 'comex_maintenance', 'london_comex_overlap',
            'calendar_quarantine',
        ], true)) {
            return $key;
        }

        return $this->session($value);
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
        if ($text === '' || in_array(strtolower($text), [
            '-', '*', 'n/a', 'na', 'none', 'null', 'unknown', 'missing',
            'mixed', 'both', 'historical_mixed', 'stratified_replay',
        ], true)) {
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

    private function structured(mixed $value): ?string
    {
        if (! is_array($value)) {
            return $this->bounded($value, false);
        }
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($normalize, $item);
        };
        $encoded = json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return is_string($encoded) && $encoded !== '[]' ? $encoded : null;
    }

    private function key(mixed $value): string
    {
        return strtolower(str_replace(['-', ' ', '/'], '_', trim((string) $value)));
    }
}
