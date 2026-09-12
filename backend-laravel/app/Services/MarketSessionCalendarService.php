<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Versioned market-session truth for contextual research.
 *
 * Session labels are resolved from venue-local civil time through the host
 * IANA tz database.  They are research coordinates, not permission to trade:
 * observed spread/liquidity and the normal execution gates still decide
 * whether a candle is actionable.
 */
class MarketSessionCalendarService
{
    public const PROTOCOL = 'market_session_calendar_v1';

    /** @return array<string, mixed> */
    public function resolve(DateTimeInterface|string|null $timestamp, array $observed = []): array
    {
        $utc = $this->utc($timestamp);
        $phases = [];
        foreach ($this->definitions() as $key => $definition) {
            $phases[$key] = $this->phaseAt($key, $definition, $utc);
        }

        $active = array_values(array_keys(array_filter(
            $phases,
            fn (array $phase): bool => (bool) $phase['active'],
        )));
        $overlapMask = array_values(array_intersect(['asia', 'london', 'new_york'], $active));
        $session = match (true) {
            in_array('london', $active, true) && in_array('new_york', $active, true) => 'overlap',
            in_array('london', $active, true) => 'london',
            in_array('new_york', $active, true) => 'new_york',
            in_array('asia', $active, true) => 'asia',
            default => 'off_session',
        };
        $venue = $this->referenceVenueState($utc);
        $liquidity = $this->liquidityState($observed);
        $boundary = collect($phases)
            ->filter(fn (array $phase): bool => (bool) $phase['active'])
            ->pluck('minutes_from_boundary')
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->min();

        return [
            'protocol' => self::PROTOCOL,
            'calendar_version' => $this->version(),
            'tzdb_version' => function_exists('timezone_version_get') ? timezone_version_get() : 'system',
            'timestamp_utc' => $utc->toIso8601String(),
            'session' => $session,
            'venue_phase' => $session,
            'active_phases' => $active,
            'overlap_mask' => $overlapMask,
            'session_instance_ids' => collect($phases)->where('active', true)->pluck('session_instance_id')->values()->all(),
            'minutes_from_boundary' => $boundary,
            'phases' => $phases,
            'reference_venue_state' => $venue,
            'spread_liquidity_state' => $liquidity,
            'actionability' => $liquidity === null
                ? 'abstain_until_observed_spread_liquidity'
                : ($venue['tradable'] ? 'context_observed' : 'reference_venue_closed'),
            'promotion_evidence' => false,
        ];
    }

    /**
     * Build a pre-registered specialist scope.  The returned instance is a
     * reference/audit identity; every replay candle must be resolved again so
     * one summer UTC label cannot leak into a winter window.
     *
     * @return array<string, mixed>
     */
    public function specialistOwnership(string $session, DateTimeInterface|string|null $reference = null): array
    {
        $session = $this->normalizeSession($session);
        $referenceUtc = $this->nearestResearchDay($this->utc($reference));
        $keys = $session === 'overlap' ? ['london', 'new_york'] : [$session];
        $instances = [];
        foreach ($keys as $key) {
            $definition = $this->definitions()[$key] ?? null;
            if (! is_array($definition)) {
                continue;
            }
            $instances[$key] = $this->instanceForLocalDate($key, $definition, $referenceUtc);
        }

        $starts = collect($instances)->pluck('start_utc')->filter()->map(fn (string $value) => CarbonImmutable::parse($value));
        $ends = collect($instances)->pluck('end_utc')->filter()->map(fn (string $value) => CarbonImmutable::parse($value));
        $start = $session === 'overlap' ? $starts->max() : $starts->first();
        $end = $session === 'overlap' ? $ends->min() : $ends->first();
        $valid = $start instanceof CarbonImmutable && $end instanceof CarbonImmutable && $end->greaterThan($start);
        $scope = [
            'protocol' => self::PROTOCOL,
            'calendar_version' => $this->version(),
            'session' => $session,
            'venue_phase' => $session,
            'overlap_mask' => array_keys($instances),
            'phase_definitions' => $this->definitions(),
            'holidays' => collect(array_keys($this->definitions()))->mapWithKeys(
                fn (string $phase): array => [$phase => $this->holidays($phase)],
            )->all(),
            'reference_instances' => $instances,
            'reference_start_utc' => $valid ? $start->toIso8601String() : null,
            'reference_end_utc' => $valid ? $end->toIso8601String() : null,
            'session_instance_id' => hash('sha256', json_encode([
                $this->version(), $session, collect($instances)->pluck('session_instance_id')->all(),
            ], JSON_UNESCAPED_SLASHES)),
            'runtime_resolution_required' => true,
            'dst_cross_window_validation_required' => in_array($session, ['london', 'new_york', 'overlap'], true),
            'observed_spread_liquidity_required' => true,
            'outside_scope_action' => 'WAIT',
            'promotion_evidence' => false,
        ];
        $scope['scope_hash'] = hash('sha256', json_encode($scope, JSON_UNESCAPED_SLASHES));

        return $scope;
    }

    /** @return array<string, array<string, string>> */
    private function definitions(): array
    {
        return (array) config('services.market_session_calendar.phases', [
            'asia' => ['timezone' => 'Asia/Shanghai', 'start' => '08:00', 'end' => '16:00'],
            'london' => ['timezone' => 'Europe/London', 'start' => '08:00', 'end' => '16:30'],
            'new_york' => ['timezone' => 'America/New_York', 'start' => '08:00', 'end' => '17:00'],
        ]);
    }

    /** @param array<string, string> $definition @return array<string, mixed> */
    private function phaseAt(string $key, array $definition, CarbonImmutable $utc): array
    {
        $local = $utc->setTimezone((string) $definition['timezone']);
        $instance = $this->instanceForLocalDate($key, $definition, $local);
        $start = CarbonImmutable::parse((string) $instance['start_utc']);
        $end = CarbonImmutable::parse((string) $instance['end_utc']);
        $closed = (bool) $instance['calendar_closed'];
        $active = ! $closed && $utc->greaterThanOrEqualTo($start) && $utc->lessThan($end);
        $distance = min(abs($utc->diffInMinutes($start, false)), abs($utc->diffInMinutes($end, false)));

        return [
            ...$instance,
            'active' => $active,
            'minutes_from_boundary' => $distance,
        ];
    }

    /** @param array<string, string> $definition @return array<string, mixed> */
    private function instanceForLocalDate(string $key, array $definition, CarbonImmutable $reference): array
    {
        $timezone = (string) $definition['timezone'];
        $local = $reference->setTimezone($timezone);
        [$startHour, $startMinute] = array_map('intval', explode(':', (string) $definition['start']));
        [$endHour, $endMinute] = array_map('intval', explode(':', (string) $definition['end']));
        $start = $local->startOfDay()->setTime($startHour, $startMinute);
        $end = $local->startOfDay()->setTime($endHour, $endMinute);
        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }
        $date = $start->toDateString();
        $closed = $start->isWeekend() || in_array($date, $this->holidays($key), true);

        return [
            'phase' => $key,
            'timezone' => $timezone,
            'local_date' => $date,
            'local_offset' => $start->format('P'),
            'offset_state' => $start->format('T'),
            'start_utc' => $start->utc()->toIso8601String(),
            'end_utc' => $end->utc()->toIso8601String(),
            'calendar_closed' => $closed,
            'closure_reason' => $closed ? ($start->isWeekend() ? 'weekend' : 'configured_holiday') : null,
            'session_instance_id' => hash('sha256', implode('|', [$this->version(), $key, $date, $start->format('P')])),
        ];
    }

    /** @return array<string, mixed> */
    private function referenceVenueState(CarbonImmutable $utc): array
    {
        $chicago = $utc->setTimezone('America/Chicago');
        $minute = ((int) $chicago->format('G') * 60) + (int) $chicago->format('i');
        $weekday = (int) $chicago->format('N');
        $maintenance = $weekday <= 4 && $minute >= 16 * 60 && $minute < 17 * 60;
        $weekend = $weekday === 6 || ($weekday === 5 && $minute >= 16 * 60) || ($weekday === 7 && $minute < 17 * 60);

        return [
            'venue' => 'COMEX_GC_REFERENCE',
            'timezone' => 'America/Chicago',
            'local_time' => $chicago->toIso8601String(),
            'maintenance' => $maintenance,
            'weekend_closed' => $weekend,
            'tradable' => ! $maintenance && ! $weekend,
            'rule' => 'Reference venue state is context only; broker XAUUSD availability and observed costs remain authoritative.',
        ];
    }

    private function liquidityState(array $observed): ?string
    {
        $explicit = data_get($observed, 'spread_liquidity_state', data_get($observed, 'liquidity_state'));
        if (filled($explicit)) {
            return strtolower(trim((string) $explicit));
        }
        $ratio = data_get($observed, 'spread_atr_ratio');
        if (! is_numeric($ratio)) {
            return null;
        }

        return (float) $ratio <= .10 ? 'normal' : ((float) $ratio <= .20 ? 'elevated' : 'high');
    }

    /** @return array<int, string> */
    private function holidays(string $phase): array
    {
        return array_values(array_map('strval', (array) config('services.market_session_calendar.holidays.'.$phase, [])));
    }

    private function nearestResearchDay(CarbonImmutable $reference): CarbonImmutable
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $closedEverywhere = collect(array_keys($this->definitions()))->every(function (string $phase) use ($reference): bool {
                $definition = $this->definitions()[$phase];
                $local = $reference->setTimezone($definition['timezone']);

                return $local->isWeekend() || in_array($local->toDateString(), $this->holidays($phase), true);
            });
            if (! $closedEverywhere) {
                return $reference;
            }
            $reference = $reference->subDay();
        }

        return $reference;
    }

    private function normalizeSession(string $session): string
    {
        return match (strtolower(str_replace(['-', ' '], '_', trim($session)))) {
            'asian' => 'asia',
            'newyork', 'ny' => 'new_york',
            'london_new_york_overlap', 'london_ny_overlap' => 'overlap',
            default => strtolower(str_replace(['-', ' '], '_', trim($session))),
        };
    }

    private function utc(DateTimeInterface|string|null $timestamp): CarbonImmutable
    {
        if ($timestamp instanceof DateTimeInterface) {
            return CarbonImmutable::instance($timestamp)->utc();
        }

        return CarbonImmutable::parse($timestamp ?: 'now', 'UTC')->utc();
    }

    private function version(): string
    {
        return (string) config('services.market_session_calendar.version', 'xauusd_market_sessions_2026_v1');
    }
}
