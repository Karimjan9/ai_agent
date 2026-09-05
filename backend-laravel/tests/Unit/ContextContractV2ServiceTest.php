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
}
