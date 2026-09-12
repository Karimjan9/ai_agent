<?php

namespace Tests\Feature;

use App\Services\MarketSessionCalendarService;
use Tests\TestCase;

class MarketSessionCalendarTest extends TestCase
{
    public function test_london_and_new_york_offsets_follow_iana_dst_rules(): void
    {
        $calendar = app(MarketSessionCalendarService::class);
        $winterLondon = $calendar->specialistOwnership('london', '2026-01-15T12:00:00Z');
        $summerLondon = $calendar->specialistOwnership('london', '2026-07-15T12:00:00Z');
        $winterNewYork = $calendar->specialistOwnership('new_york', '2026-01-15T12:00:00Z');
        $summerNewYork = $calendar->specialistOwnership('new_york', '2026-07-15T12:00:00Z');

        $this->assertSame('+00:00', data_get($winterLondon, 'reference_instances.london.local_offset'));
        $this->assertSame('+01:00', data_get($summerLondon, 'reference_instances.london.local_offset'));
        $this->assertSame('-05:00', data_get($winterNewYork, 'reference_instances.new_york.local_offset'));
        $this->assertSame('-04:00', data_get($summerNewYork, 'reference_instances.new_york.local_offset'));
        $this->assertNotSame(data_get($winterLondon, 'reference_start_utc'), data_get($summerLondon, 'reference_start_utc'));
    }

    public function test_overlap_and_reference_maintenance_are_preserved_as_separate_context(): void
    {
        $calendar = app(MarketSessionCalendarService::class);
        $overlap = $calendar->resolve('2026-03-16T12:30:00Z', ['spread_atr_ratio' => .08]);
        $maintenance = $calendar->resolve('2026-01-06T22:30:00Z', ['spread_atr_ratio' => .25]);

        $this->assertSame('overlap', $overlap['session']);
        $this->assertEqualsCanonicalizing(['london', 'new_york'], $overlap['overlap_mask']);
        $this->assertSame('normal', $overlap['spread_liquidity_state']);
        $this->assertTrue((bool) data_get($maintenance, 'reference_venue_state.maintenance'));
        $this->assertFalse((bool) data_get($maintenance, 'reference_venue_state.tradable'));
        $this->assertSame('reference_venue_closed', $maintenance['actionability']);
    }

    public function test_configured_local_holiday_closes_only_that_phase(): void
    {
        config()->set('services.market_session_calendar.holidays.new_york', ['2026-07-03']);

        $context = app(MarketSessionCalendarService::class)->resolve('2026-07-03T14:00:00Z');

        $this->assertTrue((bool) data_get($context, 'phases.new_york.calendar_closed'));
        $this->assertSame('configured_holiday', data_get($context, 'phases.new_york.closure_reason'));
        $this->assertNotContains('new_york', $context['active_phases']);
    }
}
