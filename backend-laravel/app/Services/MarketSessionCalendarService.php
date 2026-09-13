<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/** Versioned, IANA/DST-aware XAUUSD venue calendar. */
class MarketSessionCalendarService
{
    public const PROTOCOL = 'market_session_calendar_v2';

    /** @return array<int, string> */
    public function researchPhases(): array
    {
        return [
            'asia_sge_night', 'asia_sge_day',
            'london_pre_am_fix', 'london_am_fix', 'london_interfix', 'london_pm_fix',
            'comex_active', 'comex_pre_settlement', 'comex_post_settlement',
            'comex_maintenance', 'london_comex_overlap',
        ];
    }

    /** @return array<string, mixed> */
    public function resolve(DateTimeInterface|string|null $timestamp, array $observed = []): array
    {
        try {
            $utc = $this->utc($timestamp);
        } catch (Throwable) {
            return $this->quarantine('invalid_timestamp', (string) $timestamp);
        }

        $candleInterval = $this->candleInterval($utc, $observed);
        $intervalEnd = null;
        if (filled($candleInterval['end'] ?? null)) {
            try {
                $candidateEnd = $this->utc((string) $candleInterval['end']);
                $intervalEnd = $candidateEnd->greaterThan($utc) ? $candidateEnd : null;
            } catch (Throwable) {
                $intervalEnd = null;
            }
        }
        $phases = [];
        foreach ($this->definitions() as $key => $definition) {
            $phases[$key] = $this->phaseAt($key, $definition, $utc, $intervalEnd);
        }
        // Globex is active almost around the clock. Preserve legacy London
        // and Asia coordinates by defining overlap as the bounded US core
        // liquidity window rather than every hour that Globex is technically open.
        // The resolver intersects the full candle, not merely its opening tick.
        $phases['london_comex_overlap'] = $this->derivedOverlap($utc, $phases, $intervalEnd);

        $active = collect($phases)->filter(fn (array $phase): bool => (bool) ($phase['active'] ?? false))
            ->keys()->values()->all();
        $session = $this->legacySession($active);
        $primary = $this->primaryPhase($active);
        $venues = collect($active)->map(fn (string $phase): string => $this->venueForPhase($phase))
            ->reject(fn (string $venue): bool => $venue === 'derived')->unique()->values()->all();
        $liquidity = $this->liquidityState($observed);
        $classified = $active !== [];
        $status = $classified ? 'classified' : 'quarantined_market_closed';
        $activeRows = collect($phases)->only($active);
        $instanceIds = $activeRows->pluck('session_instance_id')->filter()->values()->all();
        $sessionInstanceId = hash('sha256', json_encode([$this->version(), $active, $instanceIds], JSON_UNESCAPED_SLASHES));
        $maintenance = in_array('comex_maintenance', $active, true);

        return [
            'protocol' => self::PROTOCOL,
            'calendar_version' => $this->version(),
            'tzdb_version' => function_exists('timezone_version_get') ? timezone_version_get() : 'system',
            'timestamp_utc' => $utc->toIso8601String(),
            'candle_utc_interval' => $candleInterval,
            'classification_status' => $status,
            'classified_or_quarantined' => true,
            'session' => $session,
            'session_instance_id' => $sessionInstanceId,
            'session_instance_ids' => $instanceIds,
            'venue_phase' => $primary,
            'venue_phases' => $active,
            'active_phases' => $active,
            'overlap_mask' => $venues,
            'local_time' => $this->localTimes($utc),
            'utc_interval' => $activeRows->map(fn (array $phase): array => [
                'start' => $phase['start_utc'] ?? null, 'end' => $phase['end_utc'] ?? null,
            ])->all(),
            'dst_offset' => $this->dstOffsets($utc),
            'minutes_from_open' => $activeRows->map(fn (array $phase): ?int => $phase['minutes_from_open'] ?? null)->all(),
            'minutes_from_boundary' => $activeRows->pluck('minutes_from_boundary')->filter(fn ($v) => is_numeric($v))->min(),
            'minutes_from_fix_or_settlement' => $this->eventDistances($utc),
            'holiday_or_maintenance_state' => $this->holidayMaintenanceState($phases, $maintenance, $status),
            'phases' => $phases,
            'reference_venue_state' => $this->referenceVenueState($utc, $phases),
            'spread_liquidity_state' => $liquidity,
            'actionability' => match (true) {
                ! $classified => 'calendar_quarantine_wait',
                $maintenance => 'reference_venue_closed',
                $liquidity === null => 'abstain_until_observed_spread_liquidity',
                default => 'context_observed',
            },
            'outside_scope_action' => 'WAIT',
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function specialistOwnership(string $phase, DateTimeInterface|string|null $reference = null): array
    {
        $phase = $this->normalizePhase($phase);
        $referenceUtc = $this->nearestResearchDay($this->utc($reference));
        $requested = $this->ownershipPhases($phase);
        $instances = [];
        foreach ($requested as $key) {
            if ($key === 'london_comex_overlap') {
                continue;
            }
            $definition = $this->definitions()[$key] ?? null;
            if (is_array($definition)) {
                $instances[$key] = $this->instanceForReference($key, $definition, $referenceUtc);
            }
        }
        if ($phase === 'london_comex_overlap' || $phase === 'overlap') {
            $instances = [
                'london_interfix' => $this->instanceForReference('london_interfix', $this->definitions()['london_interfix'], $referenceUtc),
                'comex_active' => $this->instanceForReference('comex_active', $this->definitions()['comex_active'], $referenceUtc),
            ];
        }

        $starts = collect($instances)->pluck('start_utc')->filter()->map(fn (string $v) => CarbonImmutable::parse($v));
        $ends = collect($instances)->pluck('end_utc')->filter()->map(fn (string $v) => CarbonImmutable::parse($v));
        $intersection = in_array($phase, ['london_comex_overlap', 'overlap'], true);
        $start = $intersection ? $starts->max() : $starts->min();
        $end = $intersection ? $ends->min() : $ends->max();
        $valid = $start instanceof CarbonImmutable && $end instanceof CarbonImmutable && $end->greaterThan($start);
        $legacy = $this->legacySession([$phase]);
        if (in_array($phase, ['asia', 'london', 'new_york', 'overlap'], true) && $instances !== []) {
            $instances[$phase] = (array) collect($instances)->first();
        }

        $scope = [
            'protocol' => self::PROTOCOL,
            'calendar_version' => $this->version(),
            'tzdb_version' => function_exists('timezone_version_get') ? timezone_version_get() : 'system',
            'session' => $legacy,
            'venue_phase' => $phase,
            'venue_phases' => $requested,
            'overlap_mask' => collect($requested)->map(fn (string $p): string => $this->venueForPhase($p))->unique()->values()->all(),
            'phase_definitions' => $this->definitions(),
            'holidays' => $this->holidayExport(),
            'no_night_session_dates' => $this->noNightDates(),
            'reference_instances' => $instances,
            'reference_start_utc' => $valid ? $start->toIso8601String() : null,
            'reference_end_utc' => $valid ? $end->toIso8601String() : null,
            'session_instance_id' => hash('sha256', json_encode([
                $this->version(), $phase, collect($instances)->pluck('session_instance_id')->unique()->values()->all(),
            ], JSON_UNESCAPED_SLASHES)),
            'runtime_resolution_required' => true,
            'candidate_control_same_runtime_instance_required' => true,
            'dst_cross_window_validation_required' => collect($requested)->contains(
                fn (string $p): bool => str_starts_with($p, 'london_') || str_starts_with($p, 'comex_'),
            ),
            'holiday_maintenance_validation_required' => true,
            'observed_spread_liquidity_required' => true,
            'outside_scope_action' => 'WAIT',
            'local_evidence_grants_global_inheritance' => false,
            'promotion_evidence' => false,
        ];
        $scope['scope_hash'] = hash('sha256', json_encode($scope, JSON_UNESCAPED_SLASHES));

        return $scope;
    }

    public function legacySessionForPhase(string $phase): string
    {
        return $this->legacySession([$this->normalizePhase($phase)]);
    }

    /** @return array<string, array<string, mixed>> */
    private function definitions(): array
    {
        return (array) config('services.market_session_calendar.phases', []);
    }

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    private function phaseAt(
        string $key,
        array $definition,
        CarbonImmutable $utc,
        ?CarbonImmutable $intervalEnd = null,
    ): array
    {
        $candidates = $this->candidateInstances($key, $definition, $utc);
        $active = collect($candidates)->first(function (array $row) use ($utc, $intervalEnd): bool {
            if ($row['calendar_closed']) {
                return false;
            }
            $start = CarbonImmutable::parse($row['start_utc']);
            $end = CarbonImmutable::parse($row['end_utc']);

            return $intervalEnd
                ? $start->lessThan($intervalEnd) && $end->greaterThan($utc)
                : $utc->greaterThanOrEqualTo($start) && $utc->lessThan($end);
        });
        $instance = is_array($active) ? $active : (array) collect($candidates)->sortBy(function (array $row) use ($utc): int {
            $start = CarbonImmutable::parse($row['start_utc'])->getTimestamp();
            $end = CarbonImmutable::parse($row['end_utc'])->getTimestamp();
            return min(abs($utc->getTimestamp() - $start), abs($utc->getTimestamp() - $end));
        })->first();
        $start = CarbonImmutable::parse($instance['start_utc']);
        $end = CarbonImmutable::parse($instance['end_utc']);
        $isActive = is_array($active);

        return [
            ...$instance,
            'active' => $isActive,
            'minutes_from_open' => $isActive ? max(0, (int) floor(($utc->getTimestamp() - $start->getTimestamp()) / 60)) : null,
            'minutes_from_boundary' => (int) floor(min(abs($utc->getTimestamp() - $start->getTimestamp()), abs($utc->getTimestamp() - $end->getTimestamp())) / 60),
        ];
    }

    /** @param array<string, mixed> $definition @return array<int, array<string, mixed>> */
    private function candidateInstances(string $key, array $definition, CarbonImmutable $utc): array
    {
        $local = $utc->setTimezone((string) $definition['timezone']);

        return collect([-1, 0, 1])->map(
            fn (int $shift): array => $this->instanceForAnchor($key, $definition, $local->startOfDay()->addDays($shift)),
        )->all();
    }

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    private function instanceForReference(string $key, array $definition, CarbonImmutable $reference): array
    {
        $candidates = $this->candidateInstances($key, $definition, $reference);
        $containing = collect($candidates)->first(function (array $row) use ($reference): bool {
            return $reference->greaterThanOrEqualTo(CarbonImmutable::parse($row['start_utc']))
                && $reference->lessThan(CarbonImmutable::parse($row['end_utc']));
        });

        return is_array($containing) ? $containing : (array) collect($candidates)->sortBy(
            fn (array $row): int => abs($reference->getTimestamp() - CarbonImmutable::parse($row['start_utc'])->getTimestamp()),
        )->first();
    }

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    private function instanceForAnchor(string $key, array $definition, CarbonImmutable $anchor): array
    {
        $timezone = (string) $definition['timezone'];
        $local = $anchor->setTimezone($timezone)->startOfDay();
        [$startHour, $startMinute] = array_map('intval', explode(':', (string) $definition['start']));
        [$endHour, $endMinute] = array_map('intval', explode(':', (string) $definition['end']));
        $start = $local->setTime($startHour, $startMinute);
        $end = $local->setTime($endHour, $endMinute);
        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }
        $basis = (string) ($definition['trading_day_basis'] ?? 'start');
        $basisDate = $basis === 'end' ? $end : $start;
        $tradingDate = $basisDate->toDateString();
        $tradingWeekday = (int) $basisDate->format('N');
        $weekdays = array_map('intval', (array) ($definition['weekdays'] ?? [1, 2, 3, 4, 5]));
        $holidayVenue = (string) ($definition['holiday_venue'] ?? $this->venueForPhase($key));
        $holiday = in_array($tradingDate, $this->holidays($key, $holidayVenue), true);
        $noNight = $key === 'asia_sge_night' && (
            in_array($start->toDateString(), $this->noNightDates(), true)
            || in_array($end->toDateString(), $this->holidays('asia_sge_day', 'sge'), true)
        );
        $weekdayClosed = ! in_array($tradingWeekday, $weekdays, true);
        $closed = $weekdayClosed || $holiday || $noNight;
        $reason = match (true) {
            $noNight => 'no_night_session_before_holiday',
            $holiday => 'configured_holiday',
            $weekdayClosed => 'weekend_or_weekly_close',
            default => null,
        };

        return [
            'phase' => $key, 'venue' => $this->venueForPhase($key), 'timezone' => $timezone,
            'local_date' => $tradingDate, 'local_start' => $start->toIso8601String(), 'local_end' => $end->toIso8601String(),
            'local_offset' => $start->format('P'), 'offset_state' => $start->format('T'),
            'start_utc' => $start->utc()->toIso8601String(), 'end_utc' => $end->utc()->toIso8601String(),
            'calendar_closed' => $closed, 'closure_reason' => $reason,
            'session_instance_id' => hash('sha256', implode('|', [$this->version(), $key, $tradingDate, $start->format('P'), $start->toIso8601String()])),
        ];
    }

    /** @return array<string, mixed> */
    private function derivedOverlap(
        CarbonImmutable $utc,
        array $phases,
        ?CarbonImmutable $intervalEnd = null,
    ): array
    {
        $comex = (array) data_get($phases, 'comex_active', []);
        $chicagoDay = $utc->setTimezone('America/Chicago')->startOfDay();
        $coreStartLocal = $chicagoDay->setTime(7, 0);
        $coreEndLocal = $chicagoDay->setTime(11, 30);
        $coreStart = $coreStartLocal->utc();
        $coreEnd = $coreEndLocal->utc();
        $londonRows = collect($phases)->filter(function (array $row, string $key) use ($coreStart, $coreEnd): bool {
            if (! str_starts_with($key, 'london_') || (bool) ($row['calendar_closed'] ?? true)) {
                return false;
            }

            return CarbonImmutable::parse((string) $row['start_utc'])->lessThan($coreEnd)
                && CarbonImmutable::parse((string) $row['end_utc'])->greaterThan($coreStart);
        });
        $candidateStart = $utc;
        $candidateEnd = $utc;
        $active = false;

        $comexCoversCore = ! (bool) ($comex['calendar_closed'] ?? true)
            && filled($comex['start_utc'] ?? null)
            && filled($comex['end_utc'] ?? null)
            && CarbonImmutable::parse((string) $comex['start_utc'])->lessThan($coreEnd)
            && CarbonImmutable::parse((string) $comex['end_utc'])->greaterThan($coreStart);
        if ($londonRows->isNotEmpty() && $comexCoversCore) {
            $londonStart = $londonRows->pluck('start_utc')->filter()->map(
                fn (string $value): int => CarbonImmutable::parse($value)->getTimestamp(),
            )->min();
            $londonEnd = $londonRows->pluck('end_utc')->filter()->map(
                fn (string $value): int => CarbonImmutable::parse($value)->getTimestamp(),
            )->max();
            $startTimestamp = max(
                (int) $londonStart,
                CarbonImmutable::parse((string) $comex['start_utc'])->getTimestamp(),
                $coreStart->getTimestamp(),
            );
            $endTimestamp = min(
                (int) $londonEnd,
                CarbonImmutable::parse((string) $comex['end_utc'])->getTimestamp(),
                $coreEnd->getTimestamp(),
            );
            $candidateStart = CarbonImmutable::createFromTimestampUTC($startTimestamp);
            $candidateEnd = CarbonImmutable::createFromTimestampUTC($endTimestamp);
            $active = $endTimestamp > $startTimestamp && ($intervalEnd
                ? $candidateStart->lessThan($intervalEnd) && $candidateEnd->greaterThan($utc)
                : $utc->greaterThanOrEqualTo($candidateStart) && $utc->lessThan($candidateEnd));
        }

        $start = $candidateStart->getTimestamp();
        $end = $candidateEnd->getTimestamp();

        return [
            'phase' => 'london_comex_overlap', 'venue' => 'derived', 'timezone' => 'America/Chicago', 'local_date' => $chicagoDay->toDateString(),
            'local_offset' => $coreStartLocal->format('P'), 'offset_state' => $coreStartLocal->format('T'),
            'start_utc' => CarbonImmutable::createFromTimestampUTC($start)->toIso8601String(),
            'end_utc' => CarbonImmutable::createFromTimestampUTC($end)->toIso8601String(),
            'calendar_closed' => false, 'closure_reason' => null, 'active' => $active,
            'minutes_from_open' => $active ? max(0, (int) floor(($utc->getTimestamp() - $start) / 60)) : null,
            'minutes_from_boundary' => $active ? (int) floor(min(abs($utc->getTimestamp() - $start), abs($utc->getTimestamp() - $end)) / 60) : 0,
            'session_instance_id' => hash('sha256', json_encode([
                $this->version(), 'london_comex_overlap', $chicagoDay->toDateString(), $coreStartLocal->format('P'),
            ], JSON_UNESCAPED_SLASHES)),
        ];
    }

    /** @return array<string, mixed> */
    private function referenceVenueState(CarbonImmutable $utc, array $phases): array
    {
        $chicago = $utc->setTimezone('America/Chicago');
        $maintenance = (bool) data_get($phases, 'comex_maintenance.active', false);
        $active = (bool) data_get($phases, 'comex_active.active', false);

        return [
            'venue' => 'COMEX_GC_REFERENCE', 'timezone' => 'America/Chicago', 'local_time' => $chicago->toIso8601String(),
            'maintenance' => $maintenance, 'weekend_closed' => ! $active && ! $maintenance, 'tradable' => $active && ! $maintenance,
            'rule' => 'CME GC reference context only; broker XAUUSD availability and observed costs remain authoritative.',
        ];
    }

    /** @return array<string, string> */
    private function localTimes(CarbonImmutable $utc): array
    {
        return [
            'sge' => $utc->setTimezone('Asia/Shanghai')->toIso8601String(),
            'london' => $utc->setTimezone('Europe/London')->toIso8601String(),
            'comex' => $utc->setTimezone('America/Chicago')->toIso8601String(),
        ];
    }

    /** @return array<string, array<string, string>> */
    private function dstOffsets(CarbonImmutable $utc): array
    {
        return collect(['sge' => 'Asia/Shanghai', 'london' => 'Europe/London', 'comex' => 'America/Chicago'])
            ->map(fn (string $zone): array => ['offset' => $utc->setTimezone($zone)->format('P'), 'state' => $utc->setTimezone($zone)->format('T')])->all();
    }

    /** @return array<string, int> */
    private function eventDistances(CarbonImmutable $utc): array
    {
        $events = [
            'lbma_am_fix' => ['Europe/London', 10, 30], 'lbma_pm_fix' => ['Europe/London', 15, 0],
            'comex_settlement' => ['America/Chicago', 12, 30],
        ];

        return collect($events)->map(function (array $event) use ($utc): int {
            [$zone, $hour, $minute] = $event;
            $target = $utc->setTimezone($zone)->startOfDay()->setTime($hour, $minute)->utc();
            return (int) floor(($utc->getTimestamp() - $target->getTimestamp()) / 60);
        })->all();
    }

    /** @return array<string, mixed> */
    private function holidayMaintenanceState(array $phases, bool $maintenance, string $status): array
    {
        $closures = collect($phases)->filter(fn (array $row): bool => (bool) ($row['calendar_closed'] ?? false))
            ->mapWithKeys(fn (array $row, string $key): array => [$key => $row['closure_reason']])->all();

        return [
            'overall' => $maintenance ? 'comex_maintenance' : ($status === 'classified' ? 'open_rulebook_state' : 'calendar_closed_quarantine'),
            'maintenance' => $maintenance, 'closures' => $closures,
            'calendar_data_status' => 'versioned_rulebook_plus_configured_overrides',
        ];
    }

    /** @return array<string, string|null> */
    private function candleInterval(CarbonImmutable $utc, array $observed): array
    {
        $end = data_get($observed, 'candle_end');
        if ($end !== null) {
            try {
                return ['start' => $utc->toIso8601String(), 'end' => $this->utc($end)->toIso8601String()];
            } catch (Throwable) {
                // Point classification remains valid.
            }
        }
        $minutes = data_get($observed, 'duration_minutes');

        return ['start' => $utc->toIso8601String(), 'end' => is_numeric($minutes) && (int) $minutes > 0 ? $utc->addMinutes((int) $minutes)->toIso8601String() : null];
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

        return (float) $ratio <= .25 ? 'liquid' : 'illiquid';
    }

    /** @return array<int, string> */
    private function holidays(string $phase, string $venue): array
    {
        $legacy = match ($venue) {
            'sge' => 'asia', 'lbma' => 'london', 'comex' => 'new_york', default => $venue,
        };

        return collect([
            ...(array) config('services.market_session_calendar.holidays.'.$venue, []),
            ...(array) config('services.market_session_calendar.holidays.'.$phase, []),
            ...(array) config('services.market_session_calendar.holidays.'.$legacy, []),
        ])
            ->map(fn ($v): string => (string) $v)->unique()->values()->all();
    }

    /** @return array<string, array<int, string>> */
    private function holidayExport(): array
    {
        return collect(['sge', 'lbma', 'comex', ...$this->researchPhases()])->mapWithKeys(
            fn (string $key): array => [$key => array_values(array_map('strval', (array) config('services.market_session_calendar.holidays.'.$key, [])))],
        )->all();
    }

    /** @return array<int, string> */
    private function noNightDates(): array
    {
        return array_values(array_map('strval', (array) config('services.market_session_calendar.sge_no_night_session_dates', [])));
    }

    private function nearestResearchDay(CarbonImmutable $reference): CarbonImmutable
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            if ($reference->isWeekday()) {
                return $reference;
            }
            $reference = $reference->subDay();
        }

        return $reference;
    }

    /** @return array<int, string> */
    private function ownershipPhases(string $phase): array
    {
        return match ($phase) {
            'asia' => ['asia_sge_night', 'asia_sge_day'],
            'london' => ['london_pre_am_fix', 'london_am_fix', 'london_interfix', 'london_pm_fix'],
            'new_york' => ['comex_active', 'comex_pre_settlement', 'comex_post_settlement', 'comex_maintenance'],
            'overlap' => ['london_comex_overlap'],
            default => in_array($phase, $this->researchPhases(), true) ? [$phase] : [],
        };
    }

    /** @param array<int, string> $active */
    private function legacySession(array $active): string
    {
        if (in_array('london_comex_overlap', $active, true) || in_array('overlap', $active, true)) {
            return 'overlap';
        }
        if (collect($active)->contains(fn (string $p): bool => str_starts_with($p, 'london_')) || in_array('london', $active, true)) {
            return 'london';
        }
        if (collect($active)->contains(fn (string $p): bool => str_starts_with($p, 'asia_sge_')) || in_array('asia', $active, true)) {
            return 'asia';
        }
        if (collect($active)->contains(fn (string $p): bool => str_starts_with($p, 'comex_')) || in_array('new_york', $active, true)) {
            return 'new_york';
        }

        return 'off_session';
    }

    /** @param array<int, string> $active */
    private function primaryPhase(array $active): string
    {
        foreach (['comex_maintenance', 'london_am_fix', 'london_pm_fix', 'comex_pre_settlement', 'comex_post_settlement', 'london_comex_overlap'] as $priority) {
            if (in_array($priority, $active, true)) {
                return $priority;
            }
        }

        return $active[0] ?? 'calendar_quarantine';
    }

    private function venueForPhase(string $phase): string
    {
        return match (true) {
            str_starts_with($phase, 'asia_sge_') => 'sge',
            str_starts_with($phase, 'london_') && $phase !== 'london_comex_overlap' => 'lbma',
            str_starts_with($phase, 'comex_') => 'comex',
            $phase === 'london_comex_overlap' => 'derived',
            default => $phase,
        };
    }

    private function normalizePhase(string $phase): string
    {
        return match (strtolower(str_replace(['-', ' '], '_', trim($phase)))) {
            'asian' => 'asia', 'newyork', 'ny' => 'new_york',
            'london_new_york_overlap', 'london_ny_overlap' => 'overlap',
            default => strtolower(str_replace(['-', ' '], '_', trim($phase))),
        };
    }

    private function utc(DateTimeInterface|string|null $timestamp): CarbonImmutable
    {
        if ($timestamp instanceof DateTimeInterface) {
            return CarbonImmutable::instance($timestamp)->utc();
        }

        return CarbonImmutable::parse($timestamp ?: 'now', 'UTC')->utc();
    }

    /** @return array<string, mixed> */
    private function quarantine(string $reason, string $timestamp): array
    {
        return [
            'protocol' => self::PROTOCOL, 'calendar_version' => $this->version(), 'timestamp_utc' => $timestamp,
            'classification_status' => 'quarantined_'.$reason, 'classified_or_quarantined' => true,
            'session' => 'off_session', 'session_instance_id' => hash('sha256', $this->version().'|'.$reason.'|'.$timestamp),
            'venue_phase' => 'calendar_quarantine', 'venue_phases' => [], 'active_phases' => [], 'overlap_mask' => [],
            'local_time' => [], 'utc_interval' => [], 'dst_offset' => [], 'minutes_from_open' => [],
            'minutes_from_fix_or_settlement' => [], 'holiday_or_maintenance_state' => ['overall' => $reason],
            'actionability' => 'calendar_quarantine_wait', 'outside_scope_action' => 'WAIT', 'promotion_evidence' => false,
        ];
    }

    private function version(): string
    {
        return (string) config('services.market_session_calendar.version', 'xauusd_market_sessions_2026_v2');
    }
}
