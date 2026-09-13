<?php

namespace Tests\Unit;

use App\Services\ContextContractV2Service;
use Tests\TestCase;

class ContextContractV2ServiceTest extends TestCase
{
    public function test_it_normalizes_legacy_axis_aliases_without_cross_axis_poisoning(): void
    {
        $projection = app(ContextContractV2Service::class)->project([
            'regime' => 'bullish', 'volatility' => 'high_volatility', 'session' => 'london_new_york_overlap',
        ]);

        $this->assertSame('valid', $projection['status']);
        $this->assertSame(['regime' => 'trend_up', 'volatility' => 'high', 'session' => 'overlap'], $projection['axes']);
        $this->assertFalse($projection['promotion_evidence']);
    }

    public function test_it_rejects_cross_axis_values_instead_of_registering_them_as_sessions(): void
    {
        $projection = app(ContextContractV2Service::class)->project([
            'regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'volatility:high_volatility',
        ]);

        $this->assertSame('context_incomplete', $projection['status']);
        $this->assertSame(['session'], $projection['invalid_axes']);
        $this->assertNull($projection['axes']['session']);
    }

    public function test_detailed_session_coordinates_participate_in_exact_identity(): void
    {
        $service = app(ContextContractV2Service::class);
        $base = [
            'regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'overlap',
            'session_instance_id' => 'instance-1', 'venue_phase' => 'london_comex_overlap',
            'venue_phases' => ['london_interfix', 'comex_active', 'london_comex_overlap'],
            'overlap_mask' => ['lbma', 'comex'],
            'dst_offset' => ['london' => ['offset' => '+00:00'], 'comex' => ['offset' => '-06:00']],
            'minutes_from_open' => ['london_interfix' => 15, 'comex_active' => 870],
            'minutes_from_fix_or_settlement' => ['lbma_am_fix' => 17],
            'holiday_or_maintenance_state' => ['overall' => 'open_rulebook_state'],
            'calendar_version' => 'test-v2', 'classification_status' => 'classified',
        ];
        $first = $service->project($base);
        $second = $service->project([...$base, 'venue_phase' => 'london_pm_fix']);

        $this->assertSame('london_comex_overlap', data_get($first, 'extended_axes.venue_phase'));
        $this->assertNotNull(data_get($first, 'extended_axes.dst_offset'));
        $this->assertNotSame($first['identity_hash'], $second['identity_hash']);
    }

    public function test_candle_timestamp_is_automatically_resolved_into_detailed_session_coordinates(): void
    {
        $projection = app(ContextContractV2Service::class)->project([
            'timestamp' => '2026-01-05T10:31:00Z',
            'regime' => 'trend_up', 'volatility' => 'normal',
        ]);

        $this->assertSame('valid', $projection['status']);
        $this->assertSame('london_am_fix', data_get($projection, 'extended_axes.venue_phase'));
        $this->assertNotEmpty(data_get($projection, 'extended_axes.session_instance_id'));
        $this->assertNotEmpty(data_get($projection, 'extended_axes.dst_offset'));
        $this->assertSame('classified', data_get($projection, 'extended_axes.classification_status'));
    }
}
