<?php

namespace Tests\Feature;

use App\Services\MarketSessionCalendarService;
use Tests\TestCase;

class MarketSessionCalendarTest extends TestCase
{
    public function test_london_and_comex_offsets_follow_iana_dst_rules(): void
    {
        $calendar = app(MarketSessionCalendarService::class);
        $winterLondon = $calendar->specialistOwnership('london_interfix', '2026-01-15T12:00:00Z');
        $summerLondon = $calendar->specialistOwnership('london_interfix', '2026-07-15T12:00:00Z');
        $winterComex = $calendar->specialistOwnership('comex_active', '2026-01-15T12:00:00Z');
        $summerComex = $calendar->specialistOwnership('comex_active', '2026-07-15T12:00:00Z');

        $this->assertSame('+00:00', data_get($winterLondon, 'reference_instances.london_interfix.local_offset'));
        $this->assertSame('+01:00', data_get($summerLondon, 'reference_instances.london_interfix.local_offset'));
        $this->assertSame('-06:00', data_get($winterComex, 'reference_instances.comex_active.local_offset'));
        $this->assertSame('-05:00', data_get($summerComex, 'reference_instances.comex_active.local_offset'));
        $this->assertNotSame($winterLondon['reference_start_utc'], $summerLondon['reference_start_utc']);
        $this->assertSame('market_session_calendar_v2', $winterLondon['protocol']);
    }

    public function test_every_timestamp_is_classified_or_explicitly_quarantined_with_complete_coordinates(): void
    {
        $calendar = app(MarketSessionCalendarService::class);
        foreach (['2026-01-05T00:30:00Z', '2026-01-05T10:31:00Z', '2026-01-05T18:20:00Z', '2026-01-10T18:20:00Z'] as $timestamp) {
            $context = $calendar->resolve($timestamp, ['spread_atr_ratio' => .08]);
            $this->assertTrue($context['classified_or_quarantined']);
            $this->assertContains($context['classification_status'], ['classified', 'quarantined_market_closed']);
            foreach (['session_instance_id', 'venue_phase', 'overlap_mask', 'local_time', 'utc_interval', 'dst_offset', 'minutes_from_open', 'minutes_from_fix_or_settlement', 'holiday_or_maintenance_state', 'calendar_version'] as $key) {
                $this->assertArrayHasKey($key, $context);
            }
        }
        $invalid = $calendar->resolve('not-a-timestamp');
        $this->assertSame('quarantined_invalid_timestamp', $invalid['classification_status']);
        $this->assertSame('calendar_quarantine_wait', $invalid['actionability']);
    }

    public function test_sge_night_day_and_pre_holiday_night_closure_are_distinct(): void
    {
        $calendar = app(MarketSessionCalendarService::class);
        $night = $calendar->resolve('2026-01-04T16:30:00Z', ['spread_atr_ratio' => .08]); // 00:30 Monday Shanghai
        $day = $calendar->resolve('2026-01-05T02:00:00Z', ['spread_atr_ratio' => .08]); // 10:00 Shanghai

        $this->assertContains('asia_sge_night', $night['active_phases']);
        $this->assertContains('asia_sge_day', $day['active_phases']);
        $this->assertNotSame(
            data_get($night, 'phases.asia_sge_night.session_instance_id'),
            data_get($day, 'phases.asia_sge_day.session_instance_id'),
        );

        config()->set('services.market_session_calendar.holidays.sge', ['2026-01-05']);
        $closed = $calendar->resolve('2026-01-04T16:30:00Z');
        $this->assertTrue((bool) data_get($closed, 'phases.asia_sge_night.calendar_closed'));
        $this->assertSame('no_night_session_before_holiday', data_get($closed, 'phases.asia_sge_night.closure_reason'));
        $this->assertNotContains('asia_sge_night', $closed['active_phases']);
    }

    public function test_lbma_fixes_comex_settlement_maintenance_and_overlap_remain_separate(): void
    {
        $calendar = app(MarketSessionCalendarService::class);
        $amFix = $calendar->resolve('2026-01-05T10:31:00Z', ['spread_atr_ratio' => .08]);
        $preSettlement = $calendar->resolve('2026-01-05T18:10:00Z', ['spread_atr_ratio' => .08]);
        $postSettlement = $calendar->resolve('2026-01-05T18:45:00Z', ['spread_atr_ratio' => .08]);
        $overlap = $calendar->resolve('2026-01-05T14:00:00Z', ['spread_atr_ratio' => .08]);
        $maintenance = $calendar->resolve('2026-01-06T22:30:00Z', ['spread_atr_ratio' => .25]);

        $this->assertSame('london_am_fix', $amFix['venue_phase']);
        $this->assertSame(1, data_get($amFix, 'minutes_from_fix_or_settlement.lbma_am_fix'));
        $this->assertSame('comex_pre_settlement', $preSettlement['venue_phase']);
        $this->assertSame('comex_post_settlement', $postSettlement['venue_phase']);
        $this->assertContains('london_comex_overlap', $overlap['active_phases']);
        $this->assertEqualsCanonicalizing(['sge', 'lbma', 'comex'], $overlap['overlap_mask']);
        $this->assertSame('overlap', $overlap['session']);
        $this->assertSame('2026-01-05T13:00:00+00:00', data_get($overlap, 'utc_interval.london_comex_overlap.start'));
        $this->assertSame(60, data_get($overlap, 'minutes_from_open.london_comex_overlap'));
        $this->assertSame('comex_maintenance', $maintenance['venue_phase']);
        $this->assertTrue((bool) data_get($maintenance, 'holiday_or_maintenance_state.maintenance'));
        $this->assertSame('reference_venue_closed', $maintenance['actionability']);
    }

    public function test_higher_timeframe_candle_keeps_every_phase_crossed_by_its_closed_interval(): void
    {
        $context = app(MarketSessionCalendarService::class)->resolve(
            '2026-01-05T10:00:00Z',
            ['duration_minutes' => 60, 'spread_atr_ratio' => .08],
        );

        $this->assertContains('london_pre_am_fix', $context['active_phases']);
        $this->assertContains('london_am_fix', $context['active_phases']);
        $this->assertContains('london_interfix', $context['active_phases']);
        $this->assertSame('london_am_fix', $context['venue_phase']);
        $this->assertSame('2026-01-05T11:00:00+00:00', data_get($context, 'candle_utc_interval.end'));

        $crossingOverlap = app(MarketSessionCalendarService::class)->resolve(
            '2026-01-05T12:30:00Z',
            ['duration_minutes' => 60, 'spread_atr_ratio' => .08],
        );
        $this->assertContains('london_comex_overlap', $crossingOverlap['active_phases']);
        $this->assertSame('2026-01-05T13:00:00+00:00', data_get($crossingOverlap, 'utc_interval.london_comex_overlap.start'));
        $this->assertSame(0, data_get($crossingOverlap, 'minutes_from_open.london_comex_overlap'));
    }

    public function test_local_holiday_does_not_leak_global_authority(): void
    {
        config()->set('services.market_session_calendar.holidays.lbma', ['2026-07-03']);
        $context = app(MarketSessionCalendarService::class)->resolve('2026-07-03T14:00:00Z', ['spread_atr_ratio' => .08]);

        $this->assertTrue((bool) data_get($context, 'phases.london_interfix.calendar_closed'));
        $this->assertNotContains('london_interfix', $context['active_phases']);
        $this->assertContains('comex_active', $context['active_phases']);
        $this->assertFalse($context['promotion_evidence']);
    }
}
